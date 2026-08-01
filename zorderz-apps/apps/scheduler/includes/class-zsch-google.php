<?php
/**
 * ZSCH_Google — hand-rolled Google Calendar/OAuth REST client (wp_remote_*).
 *
 * Phase 0 surface: authorization URL, code exchange, token refresh, identity
 * (id_token claims), and calendarList (for the conflict-calendar picker).
 * Phase 1 adds events+syncToken and watch channels behind the same request
 * core. No SDKs, no Composer — same posture as ZSCH_Graph / ZDZ_Core_Nutshell.
 *
 * IDENTITY: a Google Account is keyed by its immutable `sub` claim, NEVER by
 * email — a Google login may use any address (e.g. name@example.com), including
 * non-Gmail domains. Email is kept as a display label only.
 *
 * @since 1.6.0 (Connected Calendars Phase 0)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSCH_Google {

	const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL = 'https://oauth2.googleapis.com/token';
	const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
	const API_BASE  = 'https://www.googleapis.com/calendar/v3';

	/** Phase 1 read-only scopes (sensitive, not restricted — no CASA). */
	const SCOPES = 'openid https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/calendar.calendarlist.readonly https://www.googleapis.com/auth/calendar.events.readonly';

	/**
	 * Build the consent-screen URL.
	 *
	 * access_type=offline + prompt=consent forces a refresh token even on a
	 * reconnect; select_account shows the account chooser for people with
	 * multiple Google identities.
	 *
	 * @param string $state Signed state blob.
	 * @return string|WP_Error
	 */
	public static function auth_url( string $state ) {
		$client_id = ZSCH_Settings::conncal_config()['google_client_id'];
		if ( '' === $client_id ) {
			return new WP_Error( 'zsch_google_unconfigured', 'Google Calendar connection is not configured.' );
		}
		return self::AUTH_URL . '?' . http_build_query( array(
			'client_id'              => $client_id,
			'redirect_uri'           => ZSCH_OAuth::redirect_uri( 'google' ),
			'response_type'          => 'code',
			'scope'                  => self::SCOPES,
			'access_type'            => 'offline',
			'prompt'                 => 'consent select_account',
			'include_granted_scopes' => 'true',
			'state'                  => $state,
		) );
	}

	/**
	 * Exchange an authorization code for tokens.
	 *
	 * @param string $code
	 * @return array|WP_Error {access_token, refresh_token, expires_in, id_token, scope}
	 */
	public static function exchange_code( string $code ) {
		$cfg = ZSCH_Settings::conncal_config();
		return self::token_request( array(
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'client_id'     => $cfg['google_client_id'],
			'client_secret' => ZSCH_Settings::google_secret(),
			'redirect_uri'  => ZSCH_OAuth::redirect_uri( 'google' ),
		) );
	}

	/**
	 * Refresh an access token. Google does NOT rotate the refresh token —
	 * the response usually omits refresh_token entirely (vault keeps the old).
	 *
	 * @param string $refresh_token
	 * @return array|WP_Error
	 */
	public static function refresh_token( string $refresh_token ) {
		$cfg = ZSCH_Settings::conncal_config();
		return self::token_request( array(
			'grant_type'    => 'refresh_token',
			'refresh_token' => $refresh_token,
			'client_id'     => $cfg['google_client_id'],
			'client_secret' => ZSCH_Settings::google_secret(),
		) );
	}

	/** Shared token-endpoint POST with the invalid_grant / invalid_client split. */
	private static function token_request( array $body ) {
		$resp = wp_remote_post( self::TOKEN_URL, array(
			'timeout' => 20,
			'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			'body'    => $body,
		) );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code >= 200 && $code < 300 && ! empty( $json['access_token'] ) ) {
			return $json;
		}
		$err = is_array( $json ) ? (string) ( $json['error'] ?? 'unknown' ) : 'unknown';
		// v1.6.2 DIAGNOSTIC: also log Google's error_description (no secret in it).
		$err_desc = is_array( $json ) ? (string) ( $json['error_description'] ?? '' ) : '';
		$err_desc = preg_replace( '/\s+/', ' ', $err_desc );
		self::log( "token error HTTP {$code}: {$err} — desc: " . substr( $err_desc, 0, 400 ) );
		if ( 'invalid_grant' === $err ) {
			return new WP_Error( 'invalid_grant', 'Google authorization is no longer valid.' );
		}
		if ( 'invalid_client' === $err || 'unauthorized_client' === $err ) {
			return new WP_Error( 'invalid_client', 'Google client ID/secret rejected.' );
		}
		return new WP_Error( 'zsch_google_token', 'Google token request failed (HTTP ' . $code . ').' );
	}

	/**
	 * Identity claims from an id_token (JWT). We only need `sub` and `email`,
	 * and the token just arrived over TLS DIRECTLY from Google's token
	 * endpoint in exchange for our client secret — that provenance is the
	 * trust anchor, so decoding without signature verification is standard
	 * and safe HERE (never do this for a token that arrived from a browser).
	 *
	 * @param string $id_token
	 * @return array{sub:string,email:string}
	 */
	public static function identity_from_id_token( string $id_token ): array {
		$parts = explode( '.', $id_token );
		if ( count( $parts ) !== 3 ) {
			return array( 'sub' => '', 'email' => '' );
		}
		$payload = json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ) ), true );
		if ( ! is_array( $payload ) ) {
			return array( 'sub' => '', 'email' => '' );
		}
		return array(
			'sub'   => sanitize_text_field( (string) ( $payload['sub'] ?? '' ) ),
			'email' => sanitize_email( (string) ( $payload['email'] ?? '' ) ),
		);
	}

	/**
	 * The user's calendar list (for the picker). Requires a live account.
	 *
	 * @param int $account_id
	 * @return array|WP_Error [{id,name,primary,color}]
	 */
	public static function calendar_list( int $account_id ) {
		$token = ZSCH_Vault::get_access_token( $account_id );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$resp = wp_remote_get(
			self::API_BASE . '/users/me/calendarList?minAccessRole=reader&fields=items(id,summary,primary,backgroundColor)',
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 401 === $code ) {
			ZSCH_Vault::mark_reauth( $account_id, 'calendarList 401' );
			return new WP_Error( 'zsch_reauth', 'This calendar needs to be reconnected.' );
		}
		if ( $code < 200 || $code >= 300 || ! is_array( $json ) ) {
			self::log( "calendarList error HTTP {$code}" );
			return new WP_Error( 'zsch_google_api', 'Could not list Google calendars (HTTP ' . $code . ').' );
		}
		$out = array();
		foreach ( (array) ( $json['items'] ?? array() ) as $item ) {
			$out[] = array(
				'id'      => sanitize_text_field( (string) ( $item['id'] ?? '' ) ),
				'name'    => sanitize_text_field( (string) ( $item['summary'] ?? '' ) ),
				'primary' => ! empty( $item['primary'] ),
				'color'   => sanitize_text_field( (string) ( $item['backgroundColor'] ?? '' ) ),
			);
		}
		return $out;
	}

	/**
	 * Phase 1 (v1.7.0) — read one calendar's busy events over a UTC window.
	 *
	 * singleEvents=true expands recurrences into instances (so the mirror holds
	 * concrete busy blocks). Events marked `transparency:transparent` (Google's
	 * "free"/"does not block time") and cancelled rows are skipped — this is a
	 * CONFLICT overlay. `visibility:private|confidential` keeps the time, drops
	 * the title. Pages nextPageToken to a sane guard.
	 *
	 * @param int    $account_id
	 * @param string $external_cal_id Google calendar id.
	 * @param string $start_utc 'Y-m-d H:i:s' (UTC).
	 * @param string $end_utc   'Y-m-d H:i:s' (UTC).
	 * @param string $cursor    Reserved (syncToken) — unused in poll mode.
	 * @return array|WP_Error { events:[{external_event_id,start_utc,end_utc,is_all_day,busy_status,title}], cursor:string }
	 */
	public static function fetch_events( int $account_id, string $external_cal_id, string $start_utc, string $end_utc, string $cursor = '' ) {
		$token = ZSCH_Vault::get_access_token( $account_id );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$base = array(
			'singleEvents' => 'true',
			'orderBy'      => 'startTime',
			'showDeleted'  => 'false',
			'maxResults'   => 250,
			'timeMin'      => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $start_utc . ' UTC' ) ),
			'timeMax'      => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $end_utc . ' UTC' ) ),
			'fields'       => 'nextPageToken,items(id,status,transparency,visibility,summary,start,end)',
		);
		$events = array();
		$page   = '';
		$guard  = 0;
		do {
			$guard++;
			$q = $base;
			if ( '' !== $page ) {
				$q['pageToken'] = $page;
			}
			$url  = self::API_BASE . '/calendars/' . rawurlencode( $external_cal_id ) . '/events?' . http_build_query( $q );
			$resp = wp_remote_get( $url, array(
				'timeout' => 25,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			) );
			if ( is_wp_error( $resp ) ) {
				return $resp;
			}
			$code = (int) wp_remote_retrieve_response_code( $resp );
			$json = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( 401 === $code ) {
				ZSCH_Vault::mark_reauth( $account_id, 'events 401' );
				return new WP_Error( 'zsch_reauth', 'This calendar needs to be reconnected.' );
			}
			if ( $code < 200 || $code >= 300 || ! is_array( $json ) ) {
				self::log( "events error HTTP {$code}" );
				return new WP_Error( 'zsch_google_api', 'Could not read Google calendar (HTTP ' . $code . ').' );
			}
			foreach ( (array) ( $json['items'] ?? array() ) as $ev ) {
				if ( 'cancelled' === (string) ( $ev['status'] ?? '' ) ) {
					continue;
				}
				if ( 'transparent' === (string) ( $ev['transparency'] ?? '' ) ) {
					continue; // "free" — not a conflict
				}
				$all_day = ( ! empty( $ev['start']['date'] ) && empty( $ev['start']['dateTime'] ) );
				$s = self::google_dt_to_utc( $ev['start'] ?? array() );
				$e = self::google_dt_to_utc( $ev['end'] ?? array() );
				if ( '' === $s || '' === $e ) {
					continue;
				}
				$vis     = (string) ( $ev['visibility'] ?? '' );
				$private = ( 'private' === $vis || 'confidential' === $vis );
				$events[] = array(
					'external_event_id' => (string) ( $ev['id'] ?? '' ),
					'start_utc'         => $s,
					'end_utc'           => $e,
					'is_all_day'        => $all_day ? 1 : 0,
					'busy_status'       => 'busy',
					'title'             => $private ? '' : sanitize_text_field( (string) ( $ev['summary'] ?? '' ) ),
				);
			}
			$page = (string) ( $json['nextPageToken'] ?? '' );
		} while ( '' !== $page && $guard < 25 );
		return array( 'events' => $events, 'cursor' => '' );
	}

	/**
	 * Google event datetime → 'Y-m-d H:i:s' UTC. Timed events carry an RFC3339
	 * `dateTime` with offset; all-day events carry a `date` (Google's end.date is
	 * EXCLUSIVE, which maps cleanly to a [start,end) UTC-midnight span — correct
	 * at the day granularity the team grid renders).
	 *
	 * @param mixed $dt { dateTime } | { date }
	 * @return string '' on any parse failure.
	 */
	private static function google_dt_to_utc( $dt ): string {
		if ( ! is_array( $dt ) ) {
			return '';
		}
		if ( ! empty( $dt['dateTime'] ) ) {
			try {
				$d = new DateTime( (string) $dt['dateTime'] ); // carries its own offset
				$d->setTimezone( new DateTimeZone( 'UTC' ) );
				return $d->format( 'Y-m-d H:i:s' );
			} catch ( Exception $e ) {
				return '';
			}
		}
		if ( ! empty( $dt['date'] ) ) {
			try {
				$d = new DateTime( (string) $dt['date'] . ' 00:00:00', new DateTimeZone( 'UTC' ) );
				return $d->format( 'Y-m-d H:i:s' );
			} catch ( Exception $e ) {
				return '';
			}
		}
		return '';
	}

	/**
	 * Best-effort revoke on disconnect (revoking the refresh token kills the
	 * whole grant). Failures are logged and ignored — the row is deleted
	 * regardless, and Google expires idle grants on its own.
	 */
	public static function revoke( string $token ): void {
		if ( '' === $token ) {
			return;
		}
		$resp = wp_remote_post( self::REVOKE_URL, array(
			'timeout' => 10,
			'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			'body'    => array( 'token' => $token ),
		) );
		if ( is_wp_error( $resp ) ) {
			self::log( 'revoke failed: ' . $resp->get_error_code() );
		}
	}

	private static function log( string $msg ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'ZSCH Google: ' . $msg );
		}
	}
}
