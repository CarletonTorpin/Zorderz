<?php
/**
 * Nutshell CRM — READ-ONLY client.
 *
 * Talks to Nutshell's REST API (https://developers.nutshell.com) from the server, with the API key kept in the
 * plugin settings (or a wp-config constant) — the phone never sees it. This class has exactly one transport
 * method, get(), and it only ever sends GET requests: nothing in the plugin can create, change, tag or delete
 * anything in Nutshell. Tagging customers would be a separate, explicit step.
 *
 * Auth: HTTP Basic — username = the Nutshell login email of the person who created the key, password = the key.
 * A key made with "API access" is enough; "user impersonation" would only be needed to act as another user.
 *
 * What it reads: users (to pick whose customers to check), People (/rest/contacts), Companies (/rest/accounts),
 * Leads (/rest/leads — and the People/Companies behind them), and it can read Products, Notes and Tags.
 * What it keeps: an "address index" for the chosen user — normalized address keys + Nutshell IDs, no names —
 * for 10 minutes, so repeated checks do not hammer Nutshell. Customer names are fetched live for the matches only
 * and are never written to the database.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDH_Nutshell {

	const BASE      = 'https://app.nutshell.com/rest';
	const APP       = 'https://app.nutshell.com';
	const PAGE      = 100;   // records per page
	const MAX_PAGES = 60;    // per entity type (6 000 records)
	const BUDGET_S  = 28;    // seconds of Nutshell calls per request; past that the index is marked partial
	const IDX_TTL   = 600;   // the address index is kept for 10 minutes

	/* ------------------------------------------------------------ settings */

	public static function base() {
		$b = ( defined( 'ZDH_NUTSHELL_BASE' ) && ZDH_NUTSHELL_BASE ) ? ZDH_NUTSHELL_BASE : self::BASE;
		return rtrim( (string) apply_filters( 'zdh_nutshell_base', $b ), '/' );
	}

	/**
	 * [email, key]: the ONE Nutshell login the platform keeps (Zorderz -> Settings). This app holds no copy
	 * and shows no key box. ZDH_NUTSHELL_BASE (tests) may point the client at a mock.
	 */
	public static function creds() {
		$email = '';
		$key   = '';
		if ( class_exists( 'ZDZ_Core_Settings' ) && method_exists( 'ZDZ_Core_Settings', 'get_ns_api_key' ) ) {
			$email = (string) ZDZ_Core_Settings::get_ns_email();
			$key   = (string) ZDZ_Core_Settings::get_ns_api_key();
		}
		$email = (string) apply_filters( 'zdh_nutshell_email', $email );
		$key   = (string) apply_filters( 'zdh_nutshell_key', $key );
		return array( trim( $email ), trim( $key ) );
	}

	/** The theme's Nutshell pair is set. */
	public static function has_key() {
		list( $e, $k ) = self::creds();
		return $e !== '' && $k !== '';
	}

	/** Ready for the app: the pair, and a Nutshell user to check for (the person's own, or the site default). */
	public static function configured() {
		return self::has_key() && self::owner() !== '';
	}

	/** An explicit owner for this request (an admin asking for someone else). '' = none. */
	private static $owner_for = null;
	public static function set_owner_for( $owner ) {
		self::$owner_for = preg_match( '/^\d+-users$/', (string) $owner ) ? (string) $owner : null;
	}

	/**
	 * The single CRM user whose customers are checked ("12-users"), per PERSON: the signed-in person's own
	 * CRM user (user meta zdz_crm_user_id, else the Leads app's zl_nutshell_user_id), else the site default an administrator chose
	 * (setting nutshell_user), else '' (the card says so). The cross-check never reads anyone else's records.
	 */
	public static function owner() {
		if ( null !== self::$owner_for ) {
			return self::$owner_for;
		}
		$uid = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $uid ) {
			$ns = (string) get_user_meta( $uid, 'zdz_crm_user_id', true );
			if ( '' === $ns ) {
				$ns = (string) get_user_meta( $uid, 'zl_nutshell_user_id', true ); // the Leads app's per-person match
			}
			if ( '' === $ns && class_exists( 'ZDZ_Core_Settings' ) && method_exists( 'ZDZ_Core_Settings', 'get_nutshell_user_id' ) ) {
				$ns = (string) (int) ZDZ_Core_Settings::get_nutshell_user_id( $uid );
			}
			if ( preg_match( '/^\d+$/', $ns ) && (int) $ns > 0 ) {
				return $ns . '-users';
			}
			if ( preg_match( '/^\d+-users$/', $ns ) ) {
				return $ns;
			}
		}
		$s = zdh_get_settings();
		return preg_match( '/^\d+-users$/', (string) $s['nutshell_user'] ) ? (string) $s['nutshell_user'] : '';
	}

	/** Whose records the current owner is: the name from the cached user list, else the id. Never cached per person. */
	public static function owner_label() {
		$o = self::owner();
		if ( $o === '' ) {
			return '';
		}
		$users = get_transient( 'zdh_nutshell_users' );
		if ( is_array( $users ) ) {
			foreach ( $users as $u ) {
				if ( isset( $u['id'] ) && (string) $u['id'] === $o && ! empty( $u['name'] ) ) {
					return (string) $u['name'];
				}
			}
		}
		$s = zdh_get_settings();
		if ( $o === (string) $s['nutshell_user'] && trim( (string) $s['nutshell_user_name'] ) !== '' ) {
			return trim( (string) $s['nutshell_user_name'] );
		}
		return $o;
	}

	/** Forget everything cached from Nutshell (after the settings change). */
	public static function forget() {
		delete_transient( 'zdh_nutshell_users' );
		delete_transient( 'zdh_nutshell_fields' );
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_zdh\\_nutshell\\_idx\\_%' OR option_name LIKE '\\_transient\\_timeout\\_tsdh\\_nutshell\\_idx\\_%'" );
	}

	/* ----------------------------------------------------------- transport */

	/**
	 * The only way this plugin talks to Nutshell: an authenticated GET.
	 * Returns the decoded JSON (array) or a WP_Error. Retries once on a network error, up to twice on 429/5xx.
	 */
	public static function get( $path, $query = array(), $timeout = 20 ) {
		list( $email, $key ) = self::creds();
		if ( $email === '' || $key === '' ) {
			return new WP_Error( 'nutshell_not_configured', __( 'The CRM is not set up yet: add the login email and API key on Zorderz → Settings.', 'zorderz' ) );
		}
		if ( ! preg_match( '#^https://#', self::base() ) && ! ( defined( 'ZDH_NUTSHELL_BASE' ) && ZDH_NUTSHELL_BASE ) ) {
			return new WP_Error( 'nutshell_insecure', 'Nutshell must be reached over https.' );
		}
		$url = self::base() . '/' . ltrim( (string) $path, '/' );
		if ( $query ) {
			$qs  = http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
			$qs  = preg_replace( '/%5B\d+%5D=/', '%5B%5D=', $qs ); // filter[owners][0]=… → filter[owners][]=…
			$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . $qs;
		}
		$args  = array(
			'method'      => 'GET',
			'timeout'     => $timeout,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Basic ' . base64_encode( $email . ':' . $key ),
				'Accept'        => 'application/json',
				'User-Agent'    => 'Zorderz-Doorhangers/' . ZDH_VERSION . ' (WordPress; read-only)',
			),
		);
		$tries = 0;
		while ( true ) {
			$tries++;
			$resp = wp_remote_get( $url, $args );
			if ( is_wp_error( $resp ) ) {
				if ( $tries < 2 ) {
					continue;
				}
				return new WP_Error( 'nutshell_network', __( 'Could not reach the CRM: ', 'zorderz' ) . $resp->get_error_message() );
			}
			$code = (int) wp_remote_retrieve_response_code( $resp );
			$body = (string) wp_remote_retrieve_body( $resp );
			if ( 429 === $code || ( $code >= 500 && $code < 600 ) ) {
				if ( $tries < 3 ) {
					$wait = (float) wp_remote_retrieve_header( $resp, 'retry-after' );
					usleep( (int) ( 1000000 * min( 4, max( 1, $wait > 0 ? $wait : 1.5 * $tries ) ) ) );
					continue;
				}
				return new WP_Error( 'nutshell_busy', 429 === $code ? 'Nutshell is rate-limiting us — wait a minute and try again.' : 'Nutshell returned a server error (' . $code . ') — try again in a minute.' );
			}
			if ( 401 === $code || 403 === $code ) {
				return new WP_Error( 'nutshell_auth', 'Nutshell rejected the login (' . $code . '). Check the Nutshell email and API key: the email must be the one that created the key, unless the key allows user impersonation.' );
			}
			$json = json_decode( $body, true );
			if ( 404 === $code ) {
				return new WP_Error( 'nutshell_not_found', 'Nutshell: not found (' . $path . ').' );
			}
			if ( $code >= 400 ) {
				$msg = is_array( $json ) ? self::error_text( $json ) : substr( wp_strip_all_tags( $body ), 0, 200 );
				return new WP_Error( 'nutshell_http_' . $code, 'Nutshell error ' . $code . ( $msg !== '' ? ': ' . $msg : '' ) );
			}
			if ( ! is_array( $json ) ) {
				return new WP_Error( 'nutshell_bad_json', 'Nutshell sent something that is not JSON (HTTP ' . $code . ').' );
			}
			return $json;
		}
	}

	private static function error_text( $json ) {
		foreach ( array( 'message', 'error', 'detail', 'title' ) as $k ) {
			if ( isset( $json[ $k ] ) && is_string( $json[ $k ] ) ) {
				return substr( $json[ $k ], 0, 200 );
			}
			if ( isset( $json[ $k ] ) && is_array( $json[ $k ] ) && isset( $json[ $k ]['message'] ) ) {
				return substr( (string) $json[ $k ]['message'], 0, 200 );
			}
		}
		if ( isset( $json['errors'] ) && is_array( $json['errors'] ) ) {
			$e = reset( $json['errors'] );
			return is_array( $e ) ? substr( (string) ( $e['message'] ?? $e['detail'] ?? wp_json_encode( $e ) ), 0, 200 ) : substr( (string) $e, 0, 200 );
		}
		return '';
	}

	/** The records of a list response: {"contacts":[…]}, {"listItems":[…]} or similar. */
	private static function rows( $r, $type ) {
		if ( isset( $r[ $type ] ) && is_array( $r[ $type ] ) ) {
			return array_values( $r[ $type ] );
		}
		foreach ( array( 'listItems', 'items', 'data', 'results' ) as $k ) {
			if ( isset( $r[ $k ] ) && is_array( $r[ $k ] ) ) {
				return array_values( $r[ $k ] );
			}
		}
		return array();
	}

	/* --------------------------------------------------------------- reads */

	/** Nutshell users (to pick whose customers to check). Cached an hour. */
	public static function users( $force = false ) {
		$c = $force ? false : get_transient( 'zdh_nutshell_users' );
		if ( is_array( $c ) ) {
			return $c;
		}
		$r = self::get( 'users' );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$out = array();
		foreach ( self::rows( $r, 'users' ) as $u ) {
			if ( ! is_array( $u ) || empty( $u['id'] ) ) {
				continue;
			}
			$em = '';
			foreach ( (array) ( $u['emails'] ?? array() ) as $e ) {
				$em = is_array( $e ) ? (string) ( $e['value'] ?? '' ) : (string) $e;
				if ( $em !== '' ) {
					break;
				}
			}
			$out[] = array(
				'id'       => (string) $u['id'],
				'name'     => trim( (string) ( $u['name'] ?? '' ) ),
				'initials' => (string) ( $u['initials'] ?? '' ),
				'email'    => $em,
				'enabled'  => ! isset( $u['isEnabled'] ) || ! empty( $u['isEnabled'] ),
			);
		}
		usort( $out, function ( $a, $b ) {
			return strcasecmp( $a['name'], $b['name'] );
		} );
		set_transient( 'zdh_nutshell_users', $out, HOUR_IN_SECONDS );
		return $out;
	}

	/** Products (name, SKU, price) — read only; not used by the cross-check but available for later. */
	public static function products( $q = '' ) {
		$r = self::get( 'products', $q !== '' ? array( 'q' => $q ) : array() );
		return is_wp_error( $r ) ? $r : self::rows( $r, 'products' );
	}

	/** Tags of one entity type (contacts / accounts / leads). Read only. */
	public static function tags( $type ) {
		$r = self::get( 'tags', array( 'filter' => array( 'tagType' => $type ) ) );
		return is_wp_error( $r ) ? $r : self::rows( $r, 'tags' );
	}

	/** One page of notes (newest first as Nutshell returns them). Read only. */
	public static function notes( $page = 0, $limit = 50 ) {
		$r = self::get( 'notes', array( 'page' => array( 'limit' => (int) $limit, 'page' => (int) $page ) ) );
		return is_wp_error( $r ) ? $r : self::rows( $r, 'notes' );
	}

	/**
	 * Which filter key selects records by their owner ("owners" per Nutshell's docs) — confirmed from
	 * /rest/{type}/list/fields when that endpoint answers, remembered for a day. false = no such filter (filter here).
	 */
	public static function owner_key( $type ) {
		$cache = get_transient( 'zdh_nutshell_fields' );
		$cache = is_array( $cache ) ? $cache : array();
		if ( array_key_exists( $type, $cache ) ) {
			return $cache[ $type ];
		}
		$r = self::get( $type . '/list/fields' );
		if ( is_wp_error( $r ) ) {
			return 'owners'; // could not ask — go with the documented key
		}
		$fields = self::field_map( $r );
		$key    = false;
		foreach ( array( 'owners', 'owner', 'assignee', 'assignees', 'assignedTo' ) as $cand ) {
			if ( isset( $fields[ $cand ] ) && ( ! is_array( $fields[ $cand ] ) || ! array_key_exists( 'isFilterable', $fields[ $cand ] ) || $fields[ $cand ]['isFilterable'] ) ) {
				$key = $cand;
				break;
			}
		}
		if ( false === $key && ! $fields ) {
			$key = 'owners'; // an answer we could not read — still try the documented key
		}
		$cache[ $type ] = $key;
		set_transient( 'zdh_nutshell_fields', $cache, DAY_IN_SECONDS );
		return $key;
	}

	private static function field_map( $r ) {
		foreach ( array( 'fields', 'properties', 'data' ) as $k ) {
			if ( isset( $r[ $k ] ) && is_array( $r[ $k ] ) ) {
				$r = $r[ $k ];
				break;
			}
		}
		$map = array();
		foreach ( (array) $r as $k => $v ) {
			if ( is_array( $v ) && isset( $v['key'] ) && is_string( $v['key'] ) ) {
				$map[ $v['key'] ] = $v;
			} elseif ( is_array( $v ) && is_int( $k ) && isset( $v['name'] ) && is_string( $v['name'] ) ) {
				$map[ $v['name'] ] = $v;
			} else {
				$map[ (string) $k ] = $v;
			}
		}
		unset( $map['meta'], $map['links'] );
		return $map;
	}

	/** Does this record say which user owns it, and is it someone else? (Team-owned records pass.) */
	private static function owned_by_other_user( $it, $owner ) {
		$refs = array();
		$l    = ( isset( $it['links'] ) && is_array( $it['links'] ) ) ? $it['links'] : array();
		foreach ( array( 'owner', 'owners', 'assignee', 'assignees' ) as $k ) {
			if ( isset( $l[ $k ] ) ) {
				foreach ( (array) $l[ $k ] as $v ) {
					$refs[] = is_array( $v ) ? (string) ( $v['id'] ?? '' ) : (string) $v;
				}
			}
			if ( isset( $it[ $k ] ) ) {
				foreach ( (array) $it[ $k ] as $v ) {
					$refs[] = is_array( $v ) ? (string) ( $v['id'] ?? '' ) : (string) $v;
				}
			}
		}
		$refs = array_filter( $refs );
		if ( ! $refs ) {
			return false; // no owner information → trust the server's filter
		}
		foreach ( $refs as $ref ) {
			if ( $ref === $owner || ! preg_match( '/-users$/', $ref ) ) {
				return false; // ours, or owned by a team
			}
		}
		return true;
	}

	private static function owned_by( $it, $owner ) {
		$l = ( isset( $it['links'] ) && is_array( $it['links'] ) ) ? $it['links'] : array();
		foreach ( array( 'owner', 'owners', 'assignee', 'assignees' ) as $k ) {
			foreach ( array( $l, $it ) as $src ) {
				if ( ! isset( $src[ $k ] ) ) {
					continue;
				}
				foreach ( (array) $src[ $k ] as $v ) {
					if ( ( is_array( $v ) ? (string) ( $v['id'] ?? '' ) : (string) $v ) === $owner ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * Every record of one type (contacts / accounts / leads), only those owned by $owner when it is set.
	 * Only that user's records are ever fetched: with Nutshell's owner filter when the account supports it ('server'),
	 * otherwise each page is filtered here and records that do not say they belong to the user are dropped ('client').
	 * Returns array( items, partial, pages, filtered, total ) or a WP_Error.
	 */
	public static function list_all( $type, $owner, $deadline ) {
		if ( ! preg_match( '/^\d+-users$/', (string) $owner ) ) {
			return new WP_Error( 'nutshell_no_user', __( 'No CRM user is chosen for the cross-check yet (Zorderz → Doorhangers → CRM cross-check).', 'zorderz' ) );
		}
		$key      = self::owner_key( $type );
		$filtered = $key ? 'server' : 'client';
		$items    = array();
		$page     = 0;
		$partial  = false;
		$total    = null;
		$leak     = false; // a server-filtered page that still carries another user's record → the filter is not trusted
		while ( $page < self::MAX_PAGES ) {
			if ( microtime( true ) > $deadline ) {
				$partial = true;
				break;
			}
			$q = array( 'page' => array( 'limit' => self::PAGE, 'page' => $page ) );
			if ( 'server' === $filtered ) {
				$q['filter'] = array( $key => array( $owner ) );
			}
			$r = self::get( $type, $q, 25 );
			if ( is_wp_error( $r ) ) {
				if ( 'server' === $filtered && 0 === $page && preg_match( '/^nutshell_http_4(00|22)$/', $r->get_error_code() ) ) {
					$filtered = 'client'; // the filter key was refused → fetch everything and filter here
					continue;
				}
				return $r;
			}
			$list = self::rows( $r, $type );
			$meta = ( isset( $r['meta'] ) && is_array( $r['meta'] ) ) ? $r['meta'] : array();
			foreach ( $list as $it ) {
				if ( ! is_array( $it ) || empty( $it['id'] ) ) {
					continue;
				}
				if ( 'client' === $filtered && ! self::owned_by( $it, $owner ) ) {
					continue;
				}
				if ( 'server' === $filtered && self::owned_by_other_user( $it, $owner ) ) {
					$leak = true;
					continue; // the server ignored the filter — never show someone else's customers
				}
				$items[] = $it;
			}
			if ( isset( $meta['total'] ) && is_numeric( $meta['total'] ) ) {
				$total = (int) $meta['total'];
			}
			$page++;
			if ( count( $list ) < self::PAGE ) {
				break;
			}
			if ( null !== $total && $page * self::PAGE >= $total ) {
				break;
			}
			if ( array_key_exists( 'next', $meta ) && ! $meta['next'] ) {
				break;
			}
		}
		if ( $page >= self::MAX_PAGES ) {
			$partial = true;
		}
		if ( $leak ) { // keep only records that positively belong to the user
			$items    = array_values( array_filter( $items, function ( $it ) use ( $owner ) { return self::owned_by( $it, $owner ); } ) );
			$filtered = 'client';
		}
		return array( 'items' => $items, 'partial' => $partial, 'pages' => $page, 'filtered' => $filtered, 'total' => $total );
	}

	/** Records by ID ("3-contacts,55-contacts" fetches several at once). Returns id → record. */
	public static function by_ids( $type, $ids ) {
		$out = array();
		$ids = array_values( array_unique( array_filter( array_map( 'strval', (array) $ids ) ) ) );
		foreach ( array_chunk( $ids, 40 ) as $chunk ) {
			$r = self::get( $type . '/' . implode( ',', $chunk ) );
			if ( is_wp_error( $r ) ) {
				if ( count( $chunk ) > 1 ) { // one bad ID spoils the batch — take them singly
					foreach ( $chunk as $one ) {
						$r1 = self::get( $type . '/' . $one );
						if ( ! is_wp_error( $r1 ) ) {
							foreach ( self::rows( $r1, $type ) as $it ) {
								if ( is_array( $it ) && ! empty( $it['id'] ) ) {
									$out[ (string) $it['id'] ] = $it;
								}
							}
						}
					}
				}
				continue;
			}
			foreach ( self::rows( $r, $type ) as $it ) {
				if ( is_array( $it ) && ! empty( $it['id'] ) ) {
					$out[ (string) $it['id'] ] = $it;
				}
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------ addresses */

	/** What address_keys() saw across one index build — counts and key names only, never values. */
	private static $seen_shape = array( 'records' => 0, 'no_field' => 0, 'entries' => 0, 'unreadable' => 0, 'po_or_place' => 0, 'shape' => '' );

	private static function first_str( $a, $keys ) {
		foreach ( $keys as $k ) {
			if ( isset( $a[ $k ] ) && is_scalar( $a[ $k ] ) && trim( (string) $a[ $k ] ) !== '' ) {
				return trim( (string) $a[ $k ] );
			}
		}
		return '';
	}

	/** Key names of one address entry, nested one level ("name,value{address_1,city,location}") — no values. */
	private static function shape_of( $ad ) {
		if ( ! is_array( $ad ) ) {
			return gettype( $ad );
		}
		$parts = array();
		foreach ( $ad as $k => $v ) {
			$parts[] = is_array( $v ) && $v && array_keys( $v ) !== range( 0, count( $v ) - 1 ) ? $k . '{' . implode( ',', array_slice( array_keys( $v ), 0, 14 ) ) . '}' : (string) $k;
		}
		return implode( ',', array_slice( $parts, 0, 16 ) );
	}

	/** The address entries of a record, whatever the payload calls them. */
	private static function address_entries( $it ) {
		$present = false;
		foreach ( array( 'addresses', 'address', 'primaryAddress', 'billingAddress', 'mailingAddress' ) as $k ) {
			if ( array_key_exists( $k, $it ) ) {
				$present = true; // the field is there, even if this customer has no address
			}
			if ( ! isset( $it[ $k ] ) || $it[ $k ] === '' || $it[ $k ] === array() ) {
				continue;
			}
			$v = $it[ $k ];
			if ( is_string( $v ) ) {
				return array( $v );
			}
			if ( is_array( $v ) ) {
				return array_keys( $v ) === range( 0, count( $v ) - 1 ) ? $v : array( $v ); // a list, or one address object
			}
		}
		return $present ? array() : null; // null = no address field at all (a light "list item")
	}

	/**
	 * The normalized addresses of one Nutshell record, the way the Lookup normalizes them:
	 * each is [ k => "4100 example way" (number + street), u => "4100 example way #3" or null, z => ZIP or '', a => display ].
	 * Nutshell's live payload wraps the street fields inside "value" ({name, value:{address_1, city, …}}), so the
	 * fields are read wherever they sit, under several spellings, and one-line strings work too (a reader that looks
	 * only at the top level finds no street on any record).
	 */
	public static function address_keys( $it ) {
		$out  = array();
		$list = self::address_entries( $it );
		self::$seen_shape['records']++;
		if ( null === $list ) {
			self::$seen_shape['no_field']++;
			return $out;
		}
		foreach ( $list as $ad ) {
			self::$seen_shape['entries']++;
			if ( self::$seen_shape['shape'] === '' ) {
				self::$seen_shape['shape'] = self::shape_of( $ad );
			}
			$one_line = '';
			$f        = array();
			if ( is_string( $ad ) ) {
				$one_line = $ad;
			} elseif ( is_array( $ad ) ) {
				$f = $ad;
				foreach ( array( 'value', 'address', 'fields', 'data' ) as $wrap ) { // nested shapes: {value:{address_1…}}
					if ( isset( $ad[ $wrap ] ) && is_array( $ad[ $wrap ] ) ) {
						$f = array_merge( $ad[ $wrap ], array_filter( $ad, 'is_scalar' ) );
						foreach ( $ad[ $wrap ] as $k2 => $v2 ) {
							if ( is_scalar( $v2 ) && trim( (string) $v2 ) !== '' ) {
								$f[ $k2 ] = $v2; // the nested value wins over an empty top-level twin
							}
						}
					} elseif ( isset( $ad[ $wrap ] ) && is_string( $ad[ $wrap ] ) && preg_match( '/^\s*\d+\s+\S/', $ad[ $wrap ] ) ) {
						$one_line = $ad[ $wrap ];
					}
				}
			} else {
				self::$seen_shape['unreadable']++;
				continue;
			}
			$a1   = self::first_str( $f, array( 'address_1', 'address1', 'addressLine1', 'address_line_1', 'line1', 'street', 'street1', 'streetAddress', 'street_address' ) );
			$a2   = self::first_str( $f, array( 'address_2', 'address2', 'addressLine2', 'address_line_2', 'line2', 'street2' ) );
			$city = self::first_str( $f, array( 'city', 'locality', 'town' ) );
			$pc   = self::first_str( $f, array( 'postalCode', 'postal_code', 'zip', 'zipCode', 'zipcode', 'postcode' ) );
			if ( $a1 === '' && $one_line === '' ) {
				$one_line = self::first_str( $f, array( 'formatted', 'formattedAddress', 'full', 'fullAddress', 'displayValue', 'text', 'label' ) );
			}
			if ( $a1 === '' && $a2 !== '' && preg_match( '/^\d/', $a2 ) ) {
				list( $a1, $a2 ) = array( $a2, '' ); // the street line landed in address_2
			}
			if ( $a1 === '' && $one_line !== '' ) {
				$a1 = $one_line; // "4100 Example Ct, Exampleton, ST 00000" — the parser takes the street part and the ZIP
			}
			if ( $a1 === '' ) {
				self::$seen_shape['unreadable']++;
				continue;
			}
			list( $number, $street, $unit, $zip_in ) = ZDH_DB::parse_address_line( $a1 );
			if ( $number === '' || $street === '' ) {
				self::$seen_shape['po_or_place']++;
				continue; // a PO box or a bare place name — nothing to match a house against
			}
			if ( $unit === '' && $a2 !== '' && preg_match( '/^(?:#|apt\.?|apartment|unit|ste\.?|suite|spc|space|no\.?|lot|bldg\.?)?\s*#?\s*([0-9a-z][0-9a-z\-]{0,7})$/i', $a2, $m ) ) {
				$unit = $m[1];
			}
			$zip  = preg_match( '/^\s*(\d{5})/', $pc, $m ) ? $m[1] : $zip_in;
			$disp = $one_line !== '' && $a1 === $one_line ? $one_line : $a1 . ( $a2 !== '' ? ' ' . $a2 : '' ) . ( $city !== '' && false === stripos( $a1, $city ) ? ', ' . $city : '' ) . ( $zip !== '' && false === strpos( $a1, $zip ) ? ' ' . $zip : '' );
			$out[] = array(
				'k' => ZDH_DB::match_key( $number, $street, '' ),
				'u' => $unit !== '' ? ZDH_DB::match_key( $number, $street, $unit ) : null,
				'z' => $zip,
				'a' => $disp,
			);
		}
		return $out;
	}

	/** Records of the list that came back without any address field → fetch them whole (40 per call). */
	private static function with_addresses( $type, $items, $deadline, &$partial ) {
		$light = array();
		foreach ( $items as $n => $it ) {
			if ( null === self::address_entries( $it ) ) {
				$light[ (string) $it['id'] ] = $n;
			}
		}
		if ( ! $light ) {
			return $items;
		}
		foreach ( array_chunk( array_keys( $light ), 40 ) as $chunk ) {
			if ( microtime( true ) > $deadline ) {
				$partial = true;
				break;
			}
			foreach ( self::by_ids( $type, $chunk ) as $id => $full ) {
				if ( isset( $light[ $id ] ) ) {
					$items[ $light[ $id ] ] = $full;
				}
			}
		}
		return $items;
	}

	/**
	 * The address index of the chosen user: every address of their People and Companies, plus the People / Companies
	 * behind their Leads (those may be owned by someone else). Keys + IDs only, no names; cached for 10 minutes.
	 */
	public static function index( $owner, $force = false ) {
		if ( ! preg_match( '/^\d+-users$/', (string) $owner ) ) {
			return new WP_Error( 'nutshell_no_user', __( 'No CRM user is chosen for the cross-check yet (Zorderz → Doorhangers → CRM cross-check).', 'zorderz' ) );
		}
		$tkey = 'zdh_nutshell_idx_v2_' . md5( $owner . '|' . self::base() ); // v2: the index shape the address reader builds
		if ( ! $force ) {
			$c = get_transient( $tkey );
			if ( is_array( $c ) && isset( $c['rows'] ) ) {
				$c['cached'] = true;
				return $c;
			}
		}
		@set_time_limit( 90 );
		$t0       = microtime( true );
		$deadline = $t0 + self::BUDGET_S;
		$rows     = array();
		$seen     = array();
		$counts   = array( 'contacts' => 0, 'accounts' => 0, 'leads' => 0, 'from_leads' => 0, 'addresses' => 0 );
		$partial  = false;
		$filtered = array();
		self::$seen_shape = array( 'records' => 0, 'no_field' => 0, 'entries' => 0, 'unreadable' => 0, 'po_or_place' => 0, 'shape' => '' );

		foreach ( array( 'contacts', 'accounts' ) as $type ) {
			$r = self::list_all( $type, $owner, $deadline );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$filtered[ $type ] = $r['filtered'];
			$partial           = $partial || $r['partial'];
			$counts[ $type ]   = count( $r['items'] );
			$r['items']        = self::with_addresses( $type, $r['items'], $deadline, $partial );
			foreach ( $r['items'] as $it ) {
				self::add_rows( $rows, $seen, $type, $it, array(), $counts );
			}
		}

		$r = self::list_all( 'leads', $owner, $deadline );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$filtered['leads'] = $r['filtered'];
		$partial           = $partial || $r['partial'];
		$counts['leads']   = count( $r['items'] );
		$leads             = $r['items'];
		// list rows without their links? fetch the lead details (40 at a time) so we can see whose lead it is
		if ( $leads && ! isset( $leads[0]['links'] ) && microtime( true ) < $deadline ) {
			$detail = self::by_ids( 'leads', array_map( function ( $l ) { return $l['id']; }, $leads ) );
			foreach ( $leads as &$l ) {
				if ( isset( $detail[ (string) $l['id'] ] ) ) {
					$l = $detail[ (string) $l['id'] ];
				}
			}
			unset( $l );
		}
		$need = array( 'contacts' => array(), 'accounts' => array() );
		$via  = array();
		foreach ( $leads as $ld ) {
			$lid = (string) $ld['id'];
			$l   = ( isset( $ld['links'] ) && is_array( $ld['links'] ) ) ? $ld['links'] : array();
			foreach ( array( 'contacts', 'accounts' ) as $type ) {
				$refs = (array) ( $l[ $type ] ?? array() );
				foreach ( array( 'primaryContact', 'primaryAccount', rtrim( $type, 's' ) ) as $alt ) { // some payloads name the customer directly
					if ( ( 'contacts' === $type && 'primaryAccount' === $alt ) || ( 'accounts' === $type && 'primaryContact' === $alt ) ) {
						continue;
					}
					if ( isset( $l[ $alt ] ) ) {
						$refs[] = $l[ $alt ];
					}
					if ( isset( $ld[ $alt ] ) ) {
						$refs[] = $ld[ $alt ];
					}
				}
				foreach ( $refs as $ref ) {
					$id = is_array( $ref ) ? (string) ( $ref['id'] ?? '' ) : (string) $ref;
					if ( ! preg_match( '/^\d+-' . $type . '$/', $id ) ) {
						continue;
					}
					$via[ $id ][] = $lid;
					if ( ! isset( $seen[ $id ] ) ) {
						$need[ $type ][ $id ] = true;
					}
				}
			}
		}
		foreach ( $need as $type => $ids ) {
			if ( ! $ids ) {
				continue;
			}
			if ( microtime( true ) > $deadline ) {
				$partial = true;
				break;
			}
			$got = self::by_ids( $type, array_keys( $ids ) );
			foreach ( $got as $id => $it ) {
				$counts['from_leads']++;
				self::add_rows( $rows, $seen, $type, $it, $via[ $id ] ?? array(), $counts );
			}
		}
		foreach ( $rows as &$row ) { // an owned person who is also behind a lead: remember the lead too
			if ( isset( $via[ $row['i'] ] ) ) {
				$row['v'] = array_values( array_unique( array_merge( $row['v'], $via[ $row['i'] ] ) ) );
			}
		}
		unset( $row );

		$out = array(
			'rows'     => $rows,
			'counts'   => $counts,
			'shape'    => self::$seen_shape, // key names and counts only — what Nutshell's address fields looked like
			'partial'  => $partial,
			'filtered' => $filtered,
			'at'       => time(),
			'ms'       => (int) round( 1000 * ( microtime( true ) - $t0 ) ),
			'cached'   => false,
		);
		set_transient( $tkey, $out, self::IDX_TTL );
		return $out;
	}

	private static function add_rows( &$rows, &$seen, $type, $it, $via, &$counts ) {
		$id = (string) ( $it['id'] ?? '' );
		if ( $id === '' ) {
			return;
		}
		$seen[ $id ] = true;
		foreach ( self::address_keys( $it ) as $ak ) {
			$counts['addresses']++;
			$rows[] = array( 't' => $type, 'i' => $id, 'k' => $ak['k'], 'u' => $ak['u'], 'z' => $ak['z'], 'a' => $ak['a'], 'v' => array_values( (array) $via ) );
		}
	}

	/* ---------------------------------------------------------- cross-check */

	/**
	 * Which of the chosen user's customers live at a house that got a door hanger in the last $days days (0 = ever)?
	 * Matching is by normalized house number + street (+ unit when both sides have one), and by ZIP when both have one.
	 */
	public static function crosscheck( $days, $force = false ) {
		global $wpdb;
		$t0    = microtime( true );
		$owner = self::owner();
		$idx   = self::index( $owner, $force );
		if ( is_wp_error( $idx ) ) {
			return $idx;
		}
		$days  = max( 0, min( 3650, (int) $days ) );
		$since = $days ? gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' 12:00:00 UTC' ) - $days * DAY_IN_SECONDS ) : '';

		$by_key = array();
		$keys   = array();
		foreach ( $idx['rows'] as $n => $row ) {
			$by_key[ $row['k'] ][] = $n;
			$keys[ $row['k'] ]     = true;
			if ( $row['u'] ) {
				$keys[ $row['u'] ] = true;
			}
		}

		$addr_t  = ZDH_DB::t_addr();
		$drops_t = ZDH_DB::t_drops();
		$where   = "d.status = 'done' AND a.delivered = 1";
		$args    = array();
		if ( $since ) {
			$where .= ' AND d.drop_date >= %s';
			$args[] = $since;
		}
		$sel  = "SELECT a.id AS addr_id, a.match_key, a.zip, a.unit, a.full_address, a.delivered_at, a.walk, d.id AS drop_id, d.name AS drop_name, d.drop_date, d.drop_time, d.delivered_by, d.hanger_type, d.finished_at FROM $addr_t a INNER JOIN $drops_t d ON d.id = a.drop_id WHERE $where";
		$hits = array();
		foreach ( array_chunk( array_keys( $keys ), 400 ) as $chunk ) {
			$ph   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$hits = array_merge( $hits, (array) $wpdb->get_results( $wpdb->prepare( "$sel AND a.match_key IN ($ph)", array_merge( $args, $chunk ) ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $sel is fixed SQL on the ZDH_DB table helpers with placeholders in $where; $ph is one %s per key; every value is bound
		}
		// houses on file with a unit number (condos): a customer at the same building matches at building level
		$sql_units = "$sel AND a.unit <> ''";
		$unit_rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql_units, $args ), ARRAY_A ) : $wpdb->get_results( $sql_units, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql_units is fixed SQL on the ZDH_DB table helpers; it holds placeholders only when $args carries their values
		foreach ( (array) $unit_rows as $u ) {
			$hits[] = $u;
		}
		$batches = $since ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $drops_t WHERE status = 'done' AND drop_date >= %s", $since ) ) : (int) $wpdb->get_var( "SELECT COUNT(*) FROM $drops_t WHERE status = 'done'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $drops_t is ZDH_DB::t_drops() ($wpdb->prefix and a fixed name); the date is a placeholder

		$found = array(); // entity id → match
		$seen  = array();
		foreach ( $hits as $h ) {
			$base = preg_replace( '/ #.*$/', '', (string) $h['match_key'] );
			if ( empty( $by_key[ $base ] ) ) {
				continue;
			}
			$row_unit = trim( (string) $h['unit'] ) !== '' || $base !== (string) $h['match_key'];
			foreach ( $by_key[ $base ] as $n ) {
				$c = $idx['rows'][ $n ];
				if ( $c['z'] !== '' && (string) $h['zip'] !== '' && $c['z'] !== (string) $h['zip'] ) {
					continue; // same street name in another ZIP
				}
				if ( $c['u'] ) {
					if ( $row_unit && (string) $h['match_key'] !== $c['u'] ) {
						continue; // another unit of the same building
					}
					$level = $row_unit ? 'exact' : 'building';
				} else {
					$level = $row_unit ? 'building' : 'exact';
				}
				$k = $c['i'] . '|' . $h['addr_id'];
				if ( isset( $seen[ $k ] ) ) {
					continue;
				}
				$seen[ $k ] = true;
				if ( ! isset( $found[ $c['i'] ] ) ) {
					$found[ $c['i'] ] = array( 'type' => $c['t'], 'id' => $c['i'], 'address' => $c['a'], 'level' => $level, 'zip_checked' => ( $c['z'] !== '' && (string) $h['zip'] !== '' ), 'lead_ids' => $c['v'], 'deliveries' => array() );
				}
				$m = &$found[ $c['i'] ];
				if ( 'exact' === $level ) {
					$m['level'] = 'exact';
				}
				$m['lead_ids']     = array_values( array_unique( array_merge( $m['lead_ids'], $c['v'] ) ) );
				$m['deliveries'][] = array(
					'drop_id'      => (int) $h['drop_id'],
					'drop_name'    => (string) $h['drop_name'],
					'drop_date'    => (string) $h['drop_date'],
					'drop_time'    => (string) $h['drop_time'],
					'delivered_by' => (string) $h['delivered_by'],
					'hanger_type'  => (string) $h['hanger_type'],
					'delivered_at' => (string) $h['delivered_at'],
					'full_address' => (string) $h['full_address'],
					'walk'         => (int) $h['walk'],
					'level'        => $level,
				);
				unset( $m );
			}
		}

		// misspelled streets in Nutshell ("4400 Exampel St" typed into a lead). Stricter than the Lookup:
		// ZIP on both sides and equal, house number equal, unit ignored, one clearly-closest street on file, and the
		// Nutshell address must NOT exist as written on the county map. Such matches are labelled "spelling".
		$fuzzy_rows = array();
		foreach ( $idx['rows'] as $row ) {
			if ( isset( $found[ $row['i'] ] ) || $row['z'] === '' || ! preg_match( '/^(\d+[a-z]?)\s+(.+?)(?:\s+#\S+)?$/', (string) $row['k'], $mm ) ) {
				continue;
			}
			$fuzzy_rows[] = array( $row, $mm[1], $mm[2] );
		}
		if ( $fuzzy_rows ) {
			$nums = array_values( array_unique( array_map( function ( $x ) { return $x[1]; }, $fuzzy_rows ) ) );
			$cand = array();
			foreach ( array_chunk( $nums, 300 ) as $chunk ) {
				$ph   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
				$sel2 = "SELECT a.id AS addr_id, a.match_key, a.street_key, a.house_number, a.zip, a.unit, a.full_address, a.delivered_at, a.walk, d.id AS drop_id, d.name AS drop_name, d.drop_date, d.drop_time, d.delivered_by, d.hanger_type, d.finished_at FROM $addr_t a INNER JOIN $drops_t d ON d.id = a.drop_id WHERE $where AND a.house_number IN ($ph)";
				foreach ( (array) $wpdb->get_results( $wpdb->prepare( $sel2, array_merge( $args, $chunk ) ), ARRAY_A ) as $h ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sel2 is fixed SQL on the ZDH_DB table helpers with a %s placeholder for every value; all bound here
					$cand[ $h['house_number'] . '|' . $h['zip'] ][] = $h;
				}
			}
			foreach ( $fuzzy_rows as $x ) {
				list( $c, $num, $skey ) = $x;
				$pool = $cand[ $num . '|' . $c['z'] ] ?? array();
				if ( ! $pool || isset( $found[ $c['i'] ] ) ) {
					continue;
				}
				$rows2 = ZDH_DB::fuzzy_rows_for( $skey, $pool );
				if ( ! $rows2 ) {
					continue;
				}
				if ( class_exists( 'ZDH_Geo' ) && ZDH_Geo::geocode_one( $num, $skey, $c['z'] ) ) {
					continue; // the Nutshell address is a real address as written — not a misspelling
				}
				$found[ $c['i'] ] = array( 'type' => $c['t'], 'id' => $c['i'], 'address' => $c['a'], 'level' => 'spelling', 'zip_checked' => true, 'lead_ids' => $c['v'], 'deliveries' => array() );
				foreach ( $rows2 as $h ) {
					$found[ $c['i'] ]['deliveries'][] = array(
						'drop_id' => (int) $h['drop_id'], 'drop_name' => (string) $h['drop_name'], 'drop_date' => (string) $h['drop_date'],
						'drop_time' => (string) $h['drop_time'], 'delivered_by' => (string) $h['delivered_by'], 'hanger_type' => (string) $h['hanger_type'],
						'delivered_at' => (string) $h['delivered_at'], 'full_address' => (string) $h['full_address'], 'walk' => (int) $h['walk'], 'level' => 'spelling',
					);
				}
			}
		}

		// names — live from Nutshell, for the matches only (never stored)
		$ids = array( 'contacts' => array(), 'accounts' => array(), 'leads' => array() );
		foreach ( $found as $m ) {
			$ids[ $m['type'] ][] = $m['id'];
			foreach ( $m['lead_ids'] as $lid ) {
				$ids['leads'][] = $lid;
			}
		}
		$names = array();
		foreach ( $ids as $type => $list ) {
			if ( $list ) {
				foreach ( self::by_ids( $type, $list ) as $id => $it ) {
					$names[ $id ] = array( 'name' => trim( (string) ( $it['name'] ?? '' ) ), 'url' => self::entity_url( $type, $id, $it ) );
				}
			}
		}
		$matches = array();
		foreach ( $found as $m ) {
			usort( $m['deliveries'], function ( $a, $b ) {
				return strcmp( $b['drop_date'] . $b['drop_time'], $a['drop_date'] . $a['drop_time'] );
			} );
			$leads = array();
			foreach ( $m['lead_ids'] as $lid ) {
				$leads[] = array( 'id' => $lid, 'name' => $names[ $lid ]['name'] ?? ( 'Lead ' . (int) $lid ), 'url' => $names[ $lid ]['url'] ?? self::entity_url( 'leads', $lid, array() ) );
			}
			$matches[] = array(
				'type'        => $m['type'],
				'kind'        => 'accounts' === $m['type'] ? 'Company' : 'Person',
				'id'          => $m['id'],
				'name'        => $names[ $m['id'] ]['name'] ?? ( ( 'accounts' === $m['type'] ? 'Company ' : 'Person ' ) . (int) $m['id'] ),
				'url'         => $names[ $m['id'] ]['url'] ?? self::entity_url( $m['type'], $m['id'], array() ),
				'address'     => $m['address'],
				'level'       => $m['level'],
				'zip_checked' => $m['zip_checked'],
				'leads'       => $leads,
				'deliveries'  => $m['deliveries'],
				'latest'      => $m['deliveries'][0]['drop_date'],
			);
		}
		usort( $matches, function ( $a, $b ) {
			$c = strcmp( $b['latest'], $a['latest'] );
			return $c ? $c : strcasecmp( $a['name'], $b['name'] );
		} );

		return array(
			'owner'      => array( 'id' => $owner, 'name' => self::owner_label() ),
			'days'       => $days,
			'since'      => $since,
			'batches'    => $batches,
			'counts'     => $idx['counts'],
			'shape'      => $idx['shape'] ?? null,
			'partial'    => $idx['partial'],
			'filtered'   => $idx['filtered'],
			'cached'     => ! empty( $idx['cached'] ),
			'fetched_at' => wp_date( 'Y-m-d H:i', (int) $idx['at'] ),
			'matches'    => $matches,
			'ms'         => (int) round( 1000 * ( microtime( true ) - $t0 ) ),
		);
	}

	/** A link to the record in Nutshell's app. */
	public static function entity_url( $type, $id, $it ) {
		if ( ! empty( $it['htmlUrl'] ) && is_string( $it['htmlUrl'] ) ) {
			return $it['htmlUrl'];
		}
		if ( ! empty( $it['htmlUrlPath'] ) && is_string( $it['htmlUrlPath'] ) ) {
			return self::APP . '/' . ltrim( $it['htmlUrlPath'], '/' );
		}
		$n    = (int) $id;
		$path = array( 'contacts' => 'person', 'accounts' => 'company', 'leads' => 'lead' );
		return self::APP . '/' . ( $path[ $type ] ?? $type ) . '/' . $n;
	}

	/* ---------------------------------------------------------------- check */

	/** The settings-page test: login, users, the chosen user, the owner filter, and a fresh index (counts + sample addresses). */
	public static function check() {
		global $wpdb;
		@set_time_limit( 120 );
		$t0  = microtime( true );
		$out = array( 'configured' => self::configured(), 'endpoint' => self::base(), 'read_only' => true );
		if ( ! self::has_key() ) {
			$out['ok']    = false;
			$out['error'] = __( 'Add the CRM login email and API key first (Zorderz → Settings).', 'zorderz' );
			return $out;
		}
		$u = self::users( true );
		if ( is_wp_error( $u ) ) {
			$out['ok']    = false;
			$out['error'] = $u->get_error_message();
			return $out;
		}
		$out['ok']    = true;
		$out['login'] = 'ok';
		$out['users'] = count( $u );
		$owner        = self::owner();
		if ( $owner === '' ) {
			$out['ok']    = false;
			$out['error'] = 'Login works. Now pick the salesperson under "CRM cross-check" and Save Changes: the cross-check only ever reads that one user\'s records.';
			return $out;
		}
		$out['cross_check_for'] = $owner;
		$out['user_name']       = '';
		foreach ( $u as $x ) {
			if ( $x['id'] === $owner ) {
				$out['user_name'] = $x['name'] . ( $x['initials'] ? ' (' . $x['initials'] . ')' : '' );
			}
		}
		if ( $out['user_name'] === '' ) {
			$out['warning'] = __( 'The chosen user is not in the CRM\'s user list any more: pick again under "CRM cross-check".', 'zorderz' );
		}
		$out['owner_filter'] = array( 'contacts' => self::owner_key( 'contacts' ), 'accounts' => self::owner_key( 'accounts' ), 'leads' => self::owner_key( 'leads' ) );
		$idx = self::index( $owner, true );
		if ( is_wp_error( $idx ) ) {
			$out['index_error'] = $idx->get_error_message();
		} else {
			$out['records']      = $idx['counts'];
			$out['filtered_by']  = $idx['filtered'];
			$out['partial']      = $idx['partial'];
			$addresses           = array_values( array_unique( array_map( function ( $r ) { return $r['a']; }, $idx['rows'] ) ) );
			$out['sample_addresses'] = array_slice( $addresses, 0, 5 );
			$out['index_ms']     = $idx['ms'];
			$out['address_fields'] = $idx['shape'] ?? null;
			if ( ! $idx['counts']['addresses'] ) {
				$out['warning_addresses'] = 'No street addresses could be read from these records — the cross-check matches on the address field of People and Companies in Nutshell. address_fields shows what Nutshell sent (key names only).';
			}
		}
		$out['batches_on_file'] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ZDH_DB::t_drops() . " WHERE status = 'done'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from ZDH_DB::t_drops() ($wpdb->prefix and a fixed name); the query takes no values
		$out['elapsed_ms']      = (int) round( 1000 * ( microtime( true ) - $t0 ) );
		return $out;
	}
}
