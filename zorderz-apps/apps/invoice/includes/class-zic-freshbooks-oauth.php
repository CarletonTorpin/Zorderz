<?php
/**
 * FreshBooks OAuth 2.0 for the invoicing module — a SEPARATE, ISOLATED
 * connection instance.
 *
 * This deliberately does NOT delegate to any shared cross-plugin token service.
 * FreshBooks refresh tokens are single-use, and this app is a distinct OAuth
 * application from the platform's primary FreshBooks connection; sharing a
 * refresher is exactly what let a primary-account refresh clobber this app's
 * tokens in the source. Keeping the refresh self-contained on the app's own
 * `zic_fb_*` options keeps the two instances isolated. The FreshBooks OAuth
 * hosts are provider endpoints, not business identity.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIC_FreshBooks_OAuth {

	const AUTHORIZE_URL          = 'https://auth.freshbooks.com/oauth/authorize';
	const TOKEN_URL              = 'https://api.freshbooks.com/auth/oauth/token';
	const CRON_HOOK              = 'zic_fb_token_refresh_cron';
	const REFRESH_MARGIN_SECONDS = 600;

	public static function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
		}
	}

	public static function unschedule_cron() {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	public static function default_redirect_uri() {
		return admin_url( 'admin-post.php?action=zic_fb_oauth_callback' );
	}

	public static function is_configured() {
		return get_option( 'zic_fb_client_id' ) && get_option( 'zic_fb_client_secret' );
	}

	public static function is_connected() {
		return (bool) get_option( 'zic_fb_access_token' ) && (bool) get_option( 'zic_fb_refresh_token' );
	}

	public static function token_status_label() {
		if ( ! self::is_configured() ) {
			return 'Client ID/Secret not configured';
		}
		if ( ! self::is_connected() ) {
			return 'Not connected — click Connect FreshBooks';
		}
		$exp = (int) get_option( 'zic_fb_token_expires_at', 0 );
		if ( $exp <= 0 ) {
			return 'Connected (expiry unknown)';
		}
		if ( $exp <= time() ) {
			return 'EXPIRED at ' . gmdate( 'Y-m-d H:i', $exp ) . ' UTC — will auto-refresh';
		}
		return 'Valid until ' . gmdate( 'Y-m-d H:i', $exp ) . ' UTC (' . human_time_diff( time(), $exp ) . ' remaining)';
	}

	public static function handle_start() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'nope' );
		}
		check_admin_referer( 'zic_fb_oauth_start' );
		if ( ! self::is_configured() ) {
			wp_safe_redirect( add_query_arg( array( 'zic_fb' => 'not_configured' ), admin_url( 'admin.php?page=zic_settings' ) ) );
			exit;
		}
		$state = wp_generate_password( 32, false, false );
		update_option( 'zic_fb_oauth_state', $state );
		$redirect = get_option( 'zic_fb_redirect_uri' ) ?: self::default_redirect_uri();
		$url      = add_query_arg(
			array(
				'response_type' => 'code',
				'client_id'     => get_option( 'zic_fb_client_id' ),
				'redirect_uri'  => $redirect,
				'state'         => $state,
			),
			self::AUTHORIZE_URL
		);
		wp_redirect( $url );
		exit;
	}

	public static function handle_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'nope' );
		}
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$saved = get_option( 'zic_fb_oauth_state' );
		if ( ! $code || ! $state || ! $saved || ! hash_equals( $saved, $state ) ) {
			wp_safe_redirect( add_query_arg( array( 'zic_fb' => 'state_mismatch' ), admin_url( 'admin.php?page=zic_settings' ) ) );
			exit;
		}
		delete_option( 'zic_fb_oauth_state' );
		$resp = self::exchange_code_for_tokens( $code );
		if ( is_wp_error( $resp ) ) {
			zic_log( 'FB oauth exchange err: ' . $resp->get_error_message() );
			wp_safe_redirect( add_query_arg( array( 'zic_fb' => 'exchange_failed' ), admin_url( 'admin.php?page=zic_settings' ) ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( array( 'zic_fb' => 'connected' ), admin_url( 'admin.php?page=zic_settings' ) ) );
		exit;
	}

	protected static function exchange_code_for_tokens( $code ) {
		return self::post_token(
			array(
				'grant_type'    => 'authorization_code',
				'client_id'     => get_option( 'zic_fb_client_id' ),
				'client_secret' => get_option( 'zic_fb_client_secret' ),
				'code'          => $code,
				'redirect_uri'  => get_option( 'zic_fb_redirect_uri' ) ?: self::default_redirect_uri(),
			)
		);
	}

	public static function refresh_access_token() {
		$refresh = get_option( 'zic_fb_refresh_token' );
		if ( ! $refresh ) {
			return new WP_Error( 'no_refresh', 'No refresh token stored' );
		}
		return self::post_token(
			array(
				'grant_type'    => 'refresh_token',
				'client_id'     => get_option( 'zic_fb_client_id' ),
				'client_secret' => get_option( 'zic_fb_client_secret' ),
				'refresh_token' => $refresh,
				'redirect_uri'  => get_option( 'zic_fb_redirect_uri' ) ?: self::default_redirect_uri(),
			)
		);
	}

	protected static function post_token( $body ) {
		$resp = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = wp_remote_retrieve_response_code( $resp );
		$raw  = wp_remote_retrieve_body( $resp );
		$json = json_decode( $raw, true );
		if ( $code < 200 || $code >= 300 || ! is_array( $json ) ) {
			return new WP_Error( 'fb_token_err', 'HTTP ' . $code . ' :: ' . substr( $raw, 0, 500 ) );
		}
		if ( empty( $json['access_token'] ) ) {
			return new WP_Error( 'fb_token_err', 'no access_token :: ' . substr( $raw, 0, 500 ) );
		}
		update_option( 'zic_fb_access_token', $json['access_token'] );
		if ( ! empty( $json['refresh_token'] ) ) {
			update_option( 'zic_fb_refresh_token', $json['refresh_token'] );
		}
		$expires_in = isset( $json['expires_in'] ) ? (int) $json['expires_in'] : 3600;
		update_option( 'zic_fb_token_expires_at', time() + $expires_in );
		return $json;
	}

	public static function cron_refresh() {
		if ( ! self::is_configured() || ! self::is_connected() ) {
			return;
		}
		$exp = (int) get_option( 'zic_fb_token_expires_at', 0 );
		if ( $exp > time() + self::REFRESH_MARGIN_SECONDS ) {
			return;
		}
		$r = self::refresh_access_token();
		if ( is_wp_error( $r ) ) {
			zic_log( 'FB cron refresh failed: ' . $r->get_error_message() );
		}
	}

	public static function live_token() {
		if ( self::is_configured() && self::is_connected() ) {
			$exp = (int) get_option( 'zic_fb_token_expires_at', 0 );
			if ( $exp > 0 && $exp <= time() + self::REFRESH_MARGIN_SECONDS ) {
				$r = self::refresh_access_token();
				if ( is_wp_error( $r ) ) {
					zic_log( 'FB inline refresh failed: ' . $r->get_error_message() );
				}
			}
			$tok = get_option( 'zic_fb_access_token' );
			if ( $tok ) {
				return $tok;
			}
		}
		return get_option( 'zic_freshbooks_token', '' );
	}

	public static function handle_unauthorized() {
		if ( ! self::is_connected() ) {
			return false;
		}
		$r = self::refresh_access_token();
		return ! is_wp_error( $r );
	}
}
