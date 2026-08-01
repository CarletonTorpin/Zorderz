<?php
/**
 * ZSCH_Graph_Delegated — delegated-auth Microsoft Graph client.
 *
 * A SECOND, separate Microsoft surface: per-user OAuth (authorization-code)
 * against a multi-tenant app registration, so staff can connect their own
 * M365 mailboxes AND outside-tenant accounts as conflict calendars. The
 * existing app-level client-credentials sync (ZSCH_Graph, company mailboxes)
 * is untouched and shares nothing with this class — separate app
 * registration, separate secret option, separate risk posture.
 *
 * Phase 0 surface: authorization URL, code exchange, token refresh, identity
 * (/me), calendar list (/me/calendars). Phase 1 adds calendarView delta +
 * subscriptions behind the same request core.
 *
 * AUTHORITY: /organizations (any work/school tenant, no personal MSA) — per
 * the plan; flipping to /common later is a config change plus an MSA
 * free/busy code path, deliberately out of scope.
 *
 * @since 1.6.0 (Connected Calendars Phase 0)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSCH_Graph_Delegated {

	const AUTHORITY = 'https://login.microsoftonline.com/organizations';
	const GRAPH     = 'https://graph.microsoft.com/v1.0';

	/** Phase 1 delegated scopes; Calendars.ReadWrite arrives with Phase 2 re-consent. */
	const SCOPES = 'openid profile email offline_access User.Read Calendars.Read';

	/**
	 * Build the Microsoft sign-in URL.
	 *
	 * @param string $state Signed state blob.
	 * @return string|WP_Error
	 */
	public static function auth_url( string $state ) {
		$client_id = ZSCH_Settings::conncal_config()['ms_client_id'];
		if ( '' === $client_id ) {
			return new WP_Error( 'zsch_ms_unconfigured', 'Microsoft Calendar connection is not configured.' );
		}
		return self::AUTHORITY . '/oauth2/v2.0/authorize?' . http_build_query( array(
			'client_id'     => $client_id,
			'response_type' => 'code',
			'redirect_uri'  => ZSCH_OAuth::redirect_uri( 'microsoft' ),
			'response_mode' => 'query',
			'scope'         => self::SCOPES,
			'prompt'        => 'select_account',
			'state'         => $state,
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
			'client_id'     => $cfg['ms_client_id'],
			'client_secret' => ZSCH_Settings::ms_delegated_secret(),
			'redirect_uri'  => ZSCH_OAuth::redirect_uri( 'microsoft' ),
			'scope'         => self::SCOPES,
		) );
	}

	/**
	 * Refresh an access token. Microsoft ROTATES the refresh token on every
	 * use — the caller (ZSCH_Vault) persists the returned pair atomically;
	 * losing the rotation strands the grant.
	 *
	 * @param string $refresh_token
	 * @return array|WP_Error
	 */
	public static function refresh_token( string $refresh_token ) {
		$cfg = ZSCH_Settings::conncal_config();
		return self::token_request( array(
			'grant_type'    => 'refresh_token',
			'refresh_token' => $refresh_token,
			'client_id'     => $cfg['ms_client_id'],
			'client_secret' => ZSCH_Settings::ms_delegated_secret(),
			'scope'         => self::SCOPES,
		) );
	}

	/** Shared token-endpoint POST with the invalid_grant / invalid_client split. */
	private static function token_request( array $body ) {
		$resp = wp_remote_post( self::AUTHORITY . '/oauth2/v2.0/token', array(
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
		// v1.6.2 DIAGNOSTIC: log Microsoft's full error_description (carries the
		// AADSTS code that names the exact cause) + which correlation id, so an
		// invalid_client/invalid_grant can be pinned precisely. Contains no
		// secret. Safe to leave in; harmless when the request succeeds.
		$err_desc = is_array( $json ) ? (string) ( $json['error_description'] ?? '' ) : '';
		$err_desc = preg_replace( '/\s+/', ' ', $err_desc ); // collapse the multi-line AADSTS blob to one line.
		self::log( "token error HTTP {$code}: {$err} — desc: " . substr( $err_desc, 0, 400 ) );
		if ( 'invalid_grant' === $err || 'interaction_required' === $err ) {
			return new WP_Error( 'invalid_grant', 'Microsoft authorization is no longer valid.' );
		}
		if ( 'invalid_client' === $err || 'unauthorized_client' === $err ) {
			return new WP_Error( 'invalid_client', 'Microsoft client ID/secret rejected.' );
		}
		return new WP_Error( 'zsch_ms_token', 'Microsoft token request failed (HTTP ' . $code . ').' );
	}

	/**
	 * Identity claims from an id_token JWT (oid, tid, preferred_username).
	 * Same provenance argument as the Google client: this token came straight
	 * from the token endpoint over TLS in exchange for our secret.
	 *
	 * External identity key = "{tid}:{oid}" — oid alone is only unique within
	 * a tenant, and multi-tenant means several tenants.
	 *
	 * @param string $id_token
	 * @return array{external_id:string,email:string}
	 */
	public static function identity_from_id_token( string $id_token ): array {
		$parts = explode( '.', $id_token );
		if ( count( $parts ) !== 3 ) {
			return array( 'external_id' => '', 'email' => '' );
		}
		$payload = json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ) ), true );
		if ( ! is_array( $payload ) ) {
			return array( 'external_id' => '', 'email' => '' );
		}
		$oid = sanitize_text_field( (string) ( $payload['oid'] ?? '' ) );
		$tid = sanitize_text_field( (string) ( $payload['tid'] ?? '' ) );
		$eml = (string) ( $payload['preferred_username'] ?? ( $payload['email'] ?? '' ) );
		return array(
			'external_id' => ( '' !== $oid && '' !== $tid ) ? $tid . ':' . $oid : '',
			'email'       => sanitize_email( $eml ),
		);
	}

	/**
	 * The user's calendars (for the picker).
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
			self::GRAPH . '/me/calendars?$select=id,name,isDefaultCalendar,hexColor&$top=50',
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
			ZSCH_Vault::mark_reauth( $account_id, 'calendars 401' );
			return new WP_Error( 'zsch_reauth', 'This calendar needs to be reconnected.' );
		}
		if ( $code < 200 || $code >= 300 || ! is_array( $json ) ) {
			self::log( "calendars error HTTP {$code}" );
			return new WP_Error( 'zsch_ms_api', 'Could not list Microsoft calendars (HTTP ' . $code . ').' );
		}
		$out = array();
		foreach ( (array) ( $json['value'] ?? array() ) as $item ) {
			$out[] = array(
				'id'      => sanitize_text_field( (string) ( $item['id'] ?? '' ) ),
				'name'    => sanitize_text_field( (string) ( $item['name'] ?? '' ) ),
				'primary' => ! empty( $item['isDefaultCalendar'] ),
				'color'   => sanitize_text_field( (string) ( $item['hexColor'] ?? '' ) ),
			);
		}
		return $out;
	}

	/**
	 * Phase 1 (v1.7.0) — read one calendar's busy events over a UTC window.
	 *
	 * Uses calendarView (expands recurrences into instances) and asks Graph to
	 * return start/end in UTC via the Prefer header, so no Windows-zone mapping
	 * is needed. Events shown as FREE and cancelled instances are skipped — the
	 * mirror is a CONFLICT (busy) overlay, not a full copy. `sensitivity:private`
	 * events keep their times but drop the title (owner-privacy at the source).
	 * Pages @odata.nextLink to a sane guard.
	 *
	 * @param int    $account_id
	 * @param string $external_cal_id Graph calendar id.
	 * @param string $start_utc 'Y-m-d H:i:s' (UTC).
	 * @param string $end_utc   'Y-m-d H:i:s' (UTC).
	 * @param string $cursor    Reserved (delta link) — unused in poll mode.
	 * @return array|WP_Error { events:[{external_event_id,start_utc,end_utc,is_all_day,busy_status,title}], cursor:string }
	 */
	public static function fetch_events( int $account_id, string $external_cal_id, string $start_utc, string $end_utc, string $cursor = '' ) {
		$token = ZSCH_Vault::get_access_token( $account_id );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$q = array(
			'startDateTime' => gmdate( 'Y-m-d\TH:i:s', strtotime( $start_utc . ' UTC' ) ),
			'endDateTime'   => gmdate( 'Y-m-d\TH:i:s', strtotime( $end_utc . ' UTC' ) ),
			'$select'       => 'id,subject,start,end,isAllDay,showAs,isCancelled,sensitivity',
			'$top'          => 100,
		);
		$url    = self::GRAPH . '/me/calendars/' . rawurlencode( $external_cal_id ) . '/calendarView?' . http_build_query( $q );
		$events = array();
		$guard  = 0;
		while ( '' !== $url && $guard < 25 ) {
			$guard++;
			$resp = wp_remote_get( $url, array(
				'timeout' => 25,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Prefer'        => 'outlook.timezone="UTC"',
				),
			) );
			if ( is_wp_error( $resp ) ) {
				return $resp;
			}
			$code = (int) wp_remote_retrieve_response_code( $resp );
			$json = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( 401 === $code ) {
				ZSCH_Vault::mark_reauth( $account_id, 'calendarView 401' );
				return new WP_Error( 'zsch_reauth', 'This calendar needs to be reconnected.' );
			}
			if ( $code < 200 || $code >= 300 || ! is_array( $json ) ) {
				self::log( "calendarView error HTTP {$code}" );
				return new WP_Error( 'zsch_ms_api', 'Could not read Microsoft calendar (HTTP ' . $code . ').' );
			}
			foreach ( (array) ( $json['value'] ?? array() ) as $ev ) {
				if ( ! empty( $ev['isCancelled'] ) ) {
					continue;
				}
				$show = strtolower( (string) ( $ev['showAs'] ?? 'busy' ) );
				if ( 'free' === $show ) {
					continue; // not a conflict
				}
				$s = self::graph_dt_to_utc( $ev['start'] ?? array() );
				$e = self::graph_dt_to_utc( $ev['end'] ?? array() );
				if ( '' === $s || '' === $e ) {
					continue;
				}
				$private = ( 'private' === strtolower( (string) ( $ev['sensitivity'] ?? '' ) ) );
				$events[] = array(
					'external_event_id' => (string) ( $ev['id'] ?? '' ),
					'start_utc'         => $s,
					'end_utc'           => $e,
					'is_all_day'        => ! empty( $ev['isAllDay'] ) ? 1 : 0,
					'busy_status'       => ( 'tentative' === $show ) ? 'tentative' : 'busy',
					'title'             => $private ? '' : sanitize_text_field( (string) ( $ev['subject'] ?? '' ) ),
				);
			}
			$url = isset( $json['@odata.nextLink'] ) ? (string) $json['@odata.nextLink'] : '';
		}
		return array( 'events' => $events, 'cursor' => '' );
	}

	/**
	 * Graph datetime object → 'Y-m-d H:i:s' UTC. With the Prefer header set to
	 * UTC the timeZone is 'UTC'; we still honour whatever it says and fall back
	 * to UTC. Strips Graph's 7-digit fractional seconds.
	 *
	 * @param mixed $dt { dateTime, timeZone }
	 * @return string '' on any parse failure.
	 */
	private static function graph_dt_to_utc( $dt ): string {
		if ( ! is_array( $dt ) || empty( $dt['dateTime'] ) ) {
			return '';
		}
		$raw = preg_replace( '/\.\d+$/', '', (string) $dt['dateTime'] );
		$tzs = (string) ( $dt['timeZone'] ?? 'UTC' );
		try {
			$zone = new DateTimeZone( '' === $tzs ? 'UTC' : $tzs );
		} catch ( Exception $e ) {
			$zone = new DateTimeZone( 'UTC' );
		}
		try {
			$d = new DateTime( $raw, $zone );
			$d->setTimezone( new DateTimeZone( 'UTC' ) );
			return $d->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			return '';
		}
	}

	/**
	 * Microsoft has no delegated refresh-token revoke endpoint an app can call
	 * with just the token; disconnect deletes our grant material and the
	 * subscription (Phase 1) simply lapses. Kept for interface symmetry.
	 */
	public static function revoke( string $token ): void {
		// Intentionally a no-op; documented above.
		unset( $token );
	}

	private static function log( string $msg ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'ZSCH Graph Delegated: ' . $msg );
		}
	}
}
