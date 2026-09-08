<?php
/**
 * ZIB_REST — the owner's own door to manage their mailbox connection.
 *
 * Route base '/inbox' within the theme's single REST namespace (ZDZ_REST_NS,
 * = 'zorderz/v1') — the namespace literal is never typed here, mirroring the
 * Scheduler's own ZSCH_REST. Every route is OWNER-SCOPED to the live session
 * user (get_current_user_id) — there is no account_id path that lets one user
 * touch another's connection. The shared kiosk is denied at the permission
 * callback (zib_user_can_write). These routes manage the CONNECTION only;
 * none of them reads a byte of mail (that is the Gatekeeper's job, in P2+).
 *
 * @since 0.1.0 (P0 — Connect)
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIB_REST {

	/**
	 * Route base WITHIN the theme's single REST namespace.
	 *
	 * Every route lives under ZDZ_REST_NS, the one constant the theme owns.
	 * Routes resolve as `zorderz/v1/inbox/…` so they never collide with
	 * sibling apps.
	 */
	const ROUTE_BASE = '/inbox';

	/**
	 * Full REST base URL the widget/connections JS prefixes onto route paths.
	 * Empty when the theme (which defines ZDZ_REST_NS) is absent — callers
	 * degrade rather than build a bad URL.
	 */
	public static function base_url(): string {
		if ( ! defined( 'ZDZ_REST_NS' ) ) {
			return '';
		}
		return esc_url_raw( rest_url( ZDZ_REST_NS . self::ROUTE_BASE ) );
	}

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/** Logged-in, non-kiosk, has-access, feature-on. */
	public static function can(): bool {
		return is_user_logged_in()
			&& zib_user_has_access()
			&& zib_user_can_write()
			&& class_exists( 'ZIB_Settings' )
			&& ZIB_Settings::feature_enabled();
	}

	public static function routes(): void {
		// Decline cleanly if the theme (owner of ZDZ_REST_NS) isn't present.
		if ( ! defined( 'ZDZ_REST_NS' ) ) {
			return;
		}
		$ns   = ZDZ_REST_NS . self::ROUTE_BASE;
		$perm = array( __CLASS__, 'can' );

		register_rest_route( $ns, '/connection', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_connection' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( $ns, '/connection/disconnect', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'disconnect' ),
			'permission_callback' => $perm,
			'args'                => array(
				'account_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
			),
		) );

		register_rest_route( $ns, '/connection/mode', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'set_mode' ),
			'permission_callback' => $perm,
			'args'                => array(
				'account_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				'mode'       => array( 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
			),
		) );

		register_rest_route( $ns, '/connection/admin-access', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'set_admin_access' ),
			'permission_callback' => $perm,
			'args'                => array(
				'account_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				'enabled'    => array( 'required' => true ),
			),
		) );

		// P2 — owner search (Gatekeeper, owner==current user, forced server-side).
		register_rest_route( $ns, '/search', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'search' ),
			'permission_callback' => $perm,
			'args'                => array(
				'q'      => array( 'sanitize_callback' => 'sanitize_text_field' ),
				'limit'  => array( 'sanitize_callback' => 'absint' ),
				'offset' => array( 'sanitize_callback' => 'absint' ),
			),
		) );
		// Mail-list browse (by folder hash / coarse bucket / all). Owner-scoped in the Gatekeeper.
		register_rest_route( $ns, '/browse', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'browse' ),
			'permission_callback' => $perm,
			'args'                => array(
				'folder' => array( 'sanitize_callback' => 'sanitize_text_field' ),
				'limit'  => array( 'sanitize_callback' => 'absint' ),
				'offset' => array( 'sanitize_callback' => 'absint' ),
			),
		) );
		// Phase 2 — owner SEND (INV-SEND). POST only; the body/comment are NOT sanitize_text_field'd
		// (that would flatten an email body); identity is forced to the current user in the Gatekeeper.
		register_rest_route( $ns, '/message/(?P<id>[0-9]+)/reply', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'send_reply' ),
			'permission_callback' => $perm,
			'args'                => array(
				'id'      => array( 'sanitize_callback' => 'absint' ),
				'comment' => array( 'required' => true ),
				'all'     => array(),
			),
		) );
		register_rest_route( $ns, '/message/(?P<id>[0-9]+)/forward', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'send_forward' ),
			'permission_callback' => $perm,
			'args'                => array(
				'id'      => array( 'sanitize_callback' => 'absint' ),
				'to'      => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'comment' => array(),
			),
		) );
		register_rest_route( $ns, '/send', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'send_new' ),
			'permission_callback' => $perm,
			'args'                => array(
				'to'      => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'cc'      => array( 'sanitize_callback' => 'sanitize_text_field' ),
				'subject' => array( 'sanitize_callback' => 'sanitize_text_field' ),
				'body'    => array(),
			),
		) );
		// Triage write routes (INV-WRITE): mark-read + move. Same owner gate; identity forced to the
		// current user; the move destination is allow-listed in the Gatekeeper.
		register_rest_route( $ns, '/message/(?P<id>[0-9]+)/mark-read', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'mark_read' ),
			'permission_callback' => $perm,
			'args'                => array(
				'id'   => array( 'sanitize_callback' => 'absint' ),
				'read' => array(),
			),
		) );
		register_rest_route( $ns, '/message/(?P<id>[0-9]+)/move', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'move_msg' ),
			'permission_callback' => $perm,
			'args'                => array(
				'id' => array( 'sanitize_callback' => 'absint' ),
				'to' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );
		register_rest_route( $ns, '/message/(?P<id>\\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_message' ),
			'permission_callback' => $perm,
			'args'                => array(
				'id' => array( 'sanitize_callback' => 'absint' ),
			),
		) );

		// P4 — admin read of a STAFF mailbox. Route guarded by can_admin (reader);
		// the per-mailbox gate is still enforced inside the Gatekeeper.
		register_rest_route( $ns, '/admin/search', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'admin_search' ),
			'permission_callback' => array( __CLASS__, 'can_admin' ),
			'args'                => array(
				'owner_user_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				'q'             => array( 'sanitize_callback' => 'sanitize_text_field' ),
				'limit'         => array( 'sanitize_callback' => 'absint' ),
				'offset'        => array( 'sanitize_callback' => 'absint' ),
			),
		) );
		register_rest_route( $ns, '/admin/message/(?P<id>[0-9]+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'admin_message' ),
			'permission_callback' => array( __CLASS__, 'can_admin' ),
			'args'                => array(
				'id' => array( 'sanitize_callback' => 'absint' ),
			),
		) );
		register_rest_route( $ns, '/admin/read-gate', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'admin_read_gate' ),
			'permission_callback' => array( __CLASS__, 'can_admin_write' ),
			'args'                => array(
				'owner_user_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				'enabled'       => array( 'required' => true ),
			),
		) );
	}

	/** Admin-READ permission: logged-in, feature live, and an authorised reader
	 *  (manage_options OR an identity-plugin reader role). Guards the route only —
	 *  the per-mailbox gate is enforced inside the Gatekeeper. */
	public static function can_admin(): bool {
		if ( ! is_user_logged_in() || ! class_exists( 'ZIB_Settings' ) || ! ZIB_Settings::feature_enabled() ) {
			return false;
		}
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		// The mailbox-identity module (ZMI_Store) publishes the roles permitted to
		// read a shared service mailbox. class_exists-guarded, so this is a safe
		// no-op on an install where the mailbox-identity module is not present.
		if ( class_exists( 'ZMI_Store' ) ) {
			$allowed = array_map( 'strtolower', (array) ZMI_Store::service_mailbox_reader_roles() );
			$u       = wp_get_current_user();
			if ( $u && ! empty( $u->roles ) ) {
				foreach ( (array) $u->roles as $role ) {
					if ( in_array( strtolower( (string) $role ), $allowed, true ) ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/** Flipping the gate is admin-only (stricter than reading). */
	public static function can_admin_write(): bool {
		return is_user_logged_in() && class_exists( 'ZIB_Settings' ) && ZIB_Settings::feature_enabled() && current_user_can( 'manage_options' );
	}

	public static function admin_search( WP_REST_Request $req ): WP_REST_Response {
		return rest_ensure_response( ZIB_Gatekeeper::admin_search(
			get_current_user_id(),
			(int) $req->get_param( 'owner_user_id' ),
			(string) $req->get_param( 'q' ),
			(int) ( $req->get_param( 'limit' ) ?: 30 ),
			(int) ( $req->get_param( 'offset' ) ?: 0 )
		) );
	}

	public static function admin_message( WP_REST_Request $req ) {
		$r = ZIB_Gatekeeper::admin_get_message( get_current_user_id(), (int) $req->get_param( 'id' ) );
		if ( empty( $r['ok'] ) ) {
			return new WP_Error( 'zib_not_found', 'Not found.', array( 'status' => 404 ) );
		}
		return rest_ensure_response( $r );
	}

	public static function admin_read_gate( WP_REST_Request $req ) {
		$enabled = filter_var( $req->get_param( 'enabled' ), FILTER_VALIDATE_BOOLEAN );
		$r       = ZIB_Gatekeeper::admin_set_read(
			get_current_user_id(),
			(int) $req->get_param( 'owner_user_id' ),
			$enabled
		);
		if ( empty( $r['ok'] ) ) {
			return new WP_Error( 'zib_denied', 'Could not update the gate.', array( 'status' => 403 ) );
		}
		return rest_ensure_response( $r );
	}

	public static function get_connection(): WP_REST_Response {
		return rest_ensure_response( array(
			'connection' => ZIB_Connections::get_for_user( get_current_user_id() ),
			'modes'      => ZIB_Connections::MODES,
		) );
	}

	public static function disconnect( WP_REST_Request $req ) {
		$ok = ZIB_OAuth::disconnect( get_current_user_id(), (int) $req->get_param( 'account_id' ) );
		if ( ! $ok ) {
			return new WP_Error( 'zib_denied', 'Not found.', array( 'status' => 404 ) );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function set_mode( WP_REST_Request $req ) {
		$r = ZIB_Connections::set_index_mode(
			get_current_user_id(),
			(int) $req->get_param( 'account_id' ),
			(string) $req->get_param( 'mode' )
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return rest_ensure_response( array( 'ok' => true, 'connection' => ZIB_Connections::get_for_user( get_current_user_id() ) ) );
	}

	public static function set_admin_access( WP_REST_Request $req ) {
		$enabled = filter_var( $req->get_param( 'enabled' ), FILTER_VALIDATE_BOOLEAN );
		$r       = ZIB_Connections::set_admin_search_enabled(
			get_current_user_id(),
			(int) $req->get_param( 'account_id' ),
			$enabled
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return rest_ensure_response( array( 'ok' => true, 'connection' => ZIB_Connections::get_for_user( get_current_user_id() ) ) );
	}

	public static function search( WP_REST_Request $req ): WP_REST_Response {
		return rest_ensure_response( ZIB_Gatekeeper::owner_search(
			get_current_user_id(),
			(string) $req->get_param( 'q' ),
			(int) ( $req->get_param( 'limit' ) ?: 30 ),
			(int) ( $req->get_param( 'offset' ) ?: 0 )
		) );
	}

	public static function browse( WP_REST_Request $req ): WP_REST_Response {
		return rest_ensure_response( ZIB_Gatekeeper::owner_browse(
			get_current_user_id(),
			(string) $req->get_param( 'folder' ),
			(int) ( $req->get_param( 'limit' ) ?: 30 ),
			(int) ( $req->get_param( 'offset' ) ?: 0 )
		) );
	}

	public static function get_message( WP_REST_Request $req ) {
		$r = ZIB_Gatekeeper::owner_get_message( get_current_user_id(), (int) $req->get_param( 'id' ) );
		if ( empty( $r['ok'] ) ) {
			return new WP_Error( 'zib_not_found', 'Not found.', array( 'status' => 404 ) );
		}
		return rest_ensure_response( $r );
	}

	// ── Send path (INV-SEND: every send is human-confirmed; no auto-send) ──

	public static function send_reply( WP_REST_Request $req ) {
		return self::send_response( ZIB_Gatekeeper::owner_send_reply(
			get_current_user_id(),
			(int) $req->get_param( 'id' ),
			(string) $req->get_param( 'comment' ),
			(bool) $req->get_param( 'all' )
		) );
	}

	public static function send_forward( WP_REST_Request $req ) {
		return self::send_response( ZIB_Gatekeeper::owner_send_forward(
			get_current_user_id(),
			(int) $req->get_param( 'id' ),
			(string) $req->get_param( 'comment' ),
			(string) $req->get_param( 'to' )
		) );
	}

	public static function send_new( WP_REST_Request $req ) {
		return self::send_response( ZIB_Gatekeeper::owner_send_new(
			get_current_user_id(),
			(string) $req->get_param( 'to' ),
			(string) $req->get_param( 'cc' ),
			(string) $req->get_param( 'subject' ),
			(string) $req->get_param( 'body' )
		) );
	}

	// ── Triage callbacks (INV-WRITE: identity forced to the current user) ──

	public static function mark_read( WP_REST_Request $req ) {
		$read    = $req->get_param( 'read' );
		$is_read = ( null === $read ) ? true : (bool) filter_var( $read, FILTER_VALIDATE_BOOLEAN );
		return self::send_response( ZIB_Gatekeeper::owner_mark_read(
			get_current_user_id(),
			(int) $req->get_param( 'id' ),
			$is_read
		) );
	}

	public static function move_msg( WP_REST_Request $req ) {
		return self::send_response( ZIB_Gatekeeper::owner_move(
			get_current_user_id(),
			(int) $req->get_param( 'id' ),
			(string) $req->get_param( 'to' )
		) );
	}

	private static function send_response( array $r ) {
		if ( ! empty( $r['ok'] ) ) {
			return rest_ensure_response( array( 'ok' => true ) );
		}
		$reason = (string) ( $r['reason'] ?? 'error' );
		if ( 'denied' === $reason ) {
			$status = 403;
		} elseif ( 'not-found' === $reason ) {
			$status = 404;
		} elseif ( 'reconnect' === $reason || 'auth' === $reason ) {
			$status = 409;
		} elseif ( 'busy' === $reason ) {
			$status = 503;
		} elseif ( in_array( $reason, array( 'empty', 'no-recipients', 'not-connected', 'bad-dest' ), true ) ) {
			$status = 422;
		} else {
			$status = 502;
		}
		return new WP_Error( 'zib_write_' . $reason, 'Action failed.', array( 'status' => $status, 'reason' => $reason ) );
	}
}
