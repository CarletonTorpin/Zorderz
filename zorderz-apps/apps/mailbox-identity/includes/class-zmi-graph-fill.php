<?php
/**
 * ZMI_Graph_Fill — "Pull from Microsoft": read a mailbox's proxy addresses (aliases) from
 * Microsoft Graph and hand them back for the profile screen to store.
 *
 * REUSES the existing app-only Scheduler token: ZSCH_Graph::get_token(). That app must be
 * granted the APPLICATION permission `User.Read.All` (admin-consented) for the directory
 * read below. If the token or scope isn't there yet, this returns a clear WP_Error and the
 * admin simply types aliases by hand; nothing breaks.
 *
 *   GET /v1.0/users/{mailbox}?$select=userPrincipalName,mail,proxyAddresses,otherMails
 *
 * proxyAddresses entries are "SMTP:primary@x" (uppercase = primary) and "smtp:alias@x"
 * (lowercase = alias). We return the primary + the alias list, normalized.
 *
 * @package Zorderz\Mailbox_Identity
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZMI_Graph_Fill {

	const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

	/**
	 * Fetch identity + aliases for a mailbox address.
	 *
	 * @param string $mailbox userPrincipalName / primary SMTP.
	 * @return array{upn:string,primary:string,aliases:string[],oid:string}|WP_Error
	 */
	public static function fetch( string $mailbox ) {
		$mailbox = ZDZ_Mailbox_Identity::normalize( $mailbox );
		if ( '' === $mailbox || ! is_email( $mailbox ) ) {
			return new WP_Error( 'zmi_fill_input', 'A valid mailbox address is required.' );
		}

		$token = self::token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url  = self::GRAPH_BASE . '/users/' . rawurlencode( $mailbox )
			. '?$select=' . rawurlencode( 'id,userPrincipalName,mail,proxyAddresses,otherMails' );
		$resp = wp_remote_get( $url, array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		) );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== $code || ! is_array( $body ) ) {
			$msg = is_array( $body ) && isset( $body['error']['message'] ) ? (string) $body['error']['message'] : ( 'HTTP ' . $code );
			// 403 here almost always means the app is missing User.Read.All (application) consent.
			return new WP_Error( 'zmi_fill_graph', 'Microsoft Graph refused the directory read (' . $msg . '). Confirm the app has User.Read.All (application) with admin consent.' );
		}

		return self::parse( $body );
	}

	/**
	 * Parse a Graph user payload into { upn, primary, aliases[], oid }. PURE (no WP calls that
	 * matter for the unit under test beyond normalize) — kept separate so it's testable with a
	 * fixture.
	 *
	 * @param array $u
	 * @return array{upn:string,primary:string,aliases:string[],oid:string}
	 */
	public static function parse( array $u ): array {
		$upn     = ZDZ_Mailbox_Identity::normalize( (string) ( $u['userPrincipalName'] ?? ( $u['mail'] ?? '' ) ) );
		$primary = '';
		$aliases = array();

		foreach ( (array) ( $u['proxyAddresses'] ?? array() ) as $p ) {
			$p = (string) $p;
			if ( 0 === strpos( $p, 'SMTP:' ) ) {           // uppercase scheme = primary
				$primary = ZDZ_Mailbox_Identity::normalize( substr( $p, 5 ) );
			} elseif ( 0 === stripos( $p, 'smtp:' ) ) {    // lowercase = alias
				$aliases[] = substr( $p, 5 );
			}
		}
		foreach ( (array) ( $u['otherMails'] ?? array() ) as $m ) {
			$aliases[] = (string) $m;
		}

		if ( '' === $primary ) {
			$primary = $upn;
		}
		$aliases = ZDZ_Mailbox_Identity::clean_list( $aliases, $primary );

		return array(
			'upn'     => '' !== $upn ? $upn : $primary,
			'primary' => $primary,
			'aliases' => $aliases,
			'oid'     => (string) ( $u['id'] ?? '' ),
		);
	}

	/** The app-only token, reused from the Scheduler. WP_Error if that path isn't available. */
	private static function token() {
		if ( class_exists( 'ZSCH_Graph' ) && method_exists( 'ZSCH_Graph', 'get_token' ) ) {
			$t = ZSCH_Graph::get_token();
			if ( is_wp_error( $t ) ) {
				return $t;
			}
			if ( is_string( $t ) && '' !== $t ) {
				return $t;
			}
		}
		return new WP_Error( 'zmi_fill_token', 'The app-only Microsoft token is not available yet. Configure the Scheduler’s Microsoft app (and add User.Read.All) first.' );
	}
}
