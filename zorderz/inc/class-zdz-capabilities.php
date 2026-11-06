<?php
/**
 * ZDZ_Capabilities — the one list of every app's verbs, and the one answer to
 * "may this person use this verb".
 *
 * Apps describe what they can do on the `zdz_register_capabilities` filter: a verb
 * such as `lead.lookup` or `appointment.create`, the callable that does it, and the
 * minimum tier that may ask for it. This class reads that filter once per request
 * and:
 *
 *   - evens out the two shapes rows arrive in: a flat row ({tier, callback, kiosk, …})
 *     and the nested {callable, descriptor} form;
 *   - maps the tier words apps use onto one ladder (sales -> staff, tech -> viewer,
 *     manager -> admin); an unknown word is treated as the highest tier — refuse,
 *     don't guess;
 *   - adds what the registry needs and an app may not have said: which app owns the
 *     verb, whether it writes OUTSIDE the platform (billing, CRM, calendar, e-mail),
 *     and whether the person needs that app to use it;
 *   - answers can( verb, user ) from the tier ladder, the shared-device rule and the
 *     person's app access;
 *   - runs a verb with invoke( verb, args ). The identity keys are always
 *     overwritten with the signed-in person, whatever the caller passed, and a verb
 *     that writes is never run here: it returns needs_confirm, so the write goes
 *     through the chat's Confirm card, where the press is logged with who pressed it.
 *
 * The ladder:
 *
 *   kiosk 0 (a shared device)  <  viewer 1  <  staff 2  <  admin 3
 *
 * Which role sits on which rung is the business's to decide (org.yml `roles[].tier`,
 * stored as the `zdz_role_tiers` option, or the `zdz_role_tiers` filter). Core's
 * defaults are below. Two rules a business cannot override: only an admin-level role
 * may sit on the admin rung, and the shared-device identity is always the kiosk rung.
 * A role nobody placed gets no rung at all — deny by default.
 *
 * Fail-closed default for writes: a verb counts as a write unless its row (or the
 * annotation table) says side_effect => false. Nothing an app forgot to label is run
 * unconfirmed.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Capabilities {

	const RANK = array( 'kiosk' => 0, 'viewer' => 1, 'staff' => 2, 'admin' => 3 );

	/** Tier words apps use, onto the ladder. Anything unknown is treated as the highest: refuse, don't guess. */
	const TIER_WORDS = array(
		'viewer' => 'viewer', 'tech' => 'viewer', 'any' => 'viewer',
		'staff' => 'staff', 'sales' => 'staff', 'operator' => 'staff',
		'admin' => 'admin', 'manager' => 'admin', 'owner' => 'admin',
	);

	/** Core's default rung per platform role. A business may move a role (never onto admin unless it is admin-level). */
	const DEFAULT_ROLE_TIERS = array(
		'administrator' => 'admin',
		'zdz_owner'     => 'admin',
		'zdz_admin'     => 'admin',
		'zdz_sales'     => 'staff',
		'zdz_operator'  => 'staff',
		'zdz_mfg'       => 'staff',
		'zdz_tech'      => 'viewer',
	);

	/** Keys that name the caller. invoke() always overwrites them. */
	const IDENTITY_KEYS = array( 'user_id', 'requesting_user_id', 'is_kiosk', 'tier' );

	const OPT_ROLE_TIERS = 'zdz_role_tiers';

	/** @var array|null verb => normalized row, built once per request. */
	private static $list = null;

	public static function init(): void {
		add_filter( 'zdz_register_capabilities', array( __CLASS__, 'register_theme_verbs' ), 5 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/** The theme's own verb: contact.lookup. */
	public static function register_theme_verbs( $caps ) {
		$caps = is_array( $caps ) ? $caps : array();
		if ( class_exists( 'ZDZ_Contact_Bridge' ) && method_exists( 'ZDZ_Contact_Bridge', 'get_capability_descriptor' ) ) {
			$d = ZDZ_Contact_Bridge::get_capability_descriptor();
			if ( ! empty( $d['verb'] ) && ! isset( $caps[ $d['verb'] ] ) ) {
				$caps[ $d['verb'] ] = $d;
			}
		}
		return $caps;
	}

	/**
	 * What a row may not have said. app: the app id that owns the verb ('' = the theme).
	 * outside: it writes outside the platform. needs_app: the person must have that app.
	 * side_effect: whether it writes at all (for rows registered without saying).
	 * These name the platform's own apps and verbs; apps may override any of it from
	 * their own row, or with the `zdz_capability_annotations` filter.
	 */
	public static function annotations(): array {
		$a = array(
			'lead.lookup'         => array( 'app' => 'leads', 'outside' => false, 'needs_app' => true, 'side_effect' => false ),
			'lead.find'           => array( 'app' => 'leads', 'outside' => false, 'needs_app' => true, 'side_effect' => false ),
			'lead.create'         => array( 'app' => 'leads', 'outside' => true, 'needs_app' => true, 'side_effect' => true ),
			'survey.lookup'       => array( 'app' => 'surveys', 'outside' => false, 'needs_app' => true, 'side_effect' => false ),
			'survey.exclude'      => array( 'app' => 'surveys', 'outside' => false, 'needs_app' => true, 'side_effect' => true ),
			'availability.lookup' => array( 'app' => 'scheduler', 'outside' => false, 'needs_app' => true, 'side_effect' => false ),
			'schedule.lookup'     => array( 'app' => 'scheduler', 'outside' => false, 'needs_app' => true, 'side_effect' => false ),
			'appointment.create'  => array( 'app' => 'scheduler', 'outside' => true, 'needs_app' => true, 'side_effect' => true ),
			'jobs.handoff'        => array( 'app' => 'jobs', 'outside' => true, 'needs_app' => true, 'side_effect' => true ),
			'jobs.project_lookup' => array( 'app' => 'jobs', 'outside' => false, 'needs_app' => true, 'side_effect' => false ),
			// Commission decides who may see a figure from each person's data permissions, so the
			// ladder does not stand in front of it. The shared-device rule still applies.
			'commission.calc'     => array( 'app' => 'commission-calculator', 'outside' => false, 'needs_app' => false, 'self_gated' => true, 'side_effect' => false ),
			'commission.units'    => array( 'app' => 'commission-calculator', 'outside' => false, 'needs_app' => false, 'side_effect' => false ),
			'contact.lookup'      => array( 'app' => '', 'outside' => false, 'needs_app' => false, 'side_effect' => false ),
			'estimate.lookup'     => array( 'app' => 'estimate-creator', 'outside' => false, 'needs_app' => false, 'side_effect' => false ),
			'estimate.create'     => array( 'app' => 'estimate-creator', 'outside' => true, 'needs_app' => true, 'side_effect' => true ),
			'estimate.modify'     => array( 'app' => 'estimate-creator', 'outside' => true, 'needs_app' => true, 'side_effect' => true ),
			'estimate.send'       => array( 'app' => 'estimate-creator', 'outside' => true, 'needs_app' => true, 'side_effect' => true ),
			'estimate.stub'       => array( 'app' => 'estimate-creator', 'outside' => true, 'needs_app' => true, 'side_effect' => true ),
			'stock.lookup'        => array( 'app' => 'stock', 'outside' => false, 'needs_app' => false, 'side_effect' => false ),
		);
		$f = function_exists( 'apply_filters' ) ? apply_filters( 'zdz_capability_annotations', $a ) : $a;
		return is_array( $f ) ? $f : $a;
	}

	/** Every registered verb, normalized. */
	public static function all(): array {
		if ( null !== self::$list ) {
			return self::$list;
		}
		$raw  = apply_filters( 'zdz_register_capabilities', array() );
		$ann  = self::annotations();
		$list = array();
		foreach ( (array) $raw as $verb => $row ) {
			$n = self::normalize( (string) $verb, $row, $ann );
			if ( $n ) {
				$list[ $n['verb'] ] = $n;
			}
		}
		ksort( $list );
		return self::$list = $list;
	}

	/** Forget the list (tests; an app registering late). */
	public static function flush(): void {
		self::$list = null;
	}

	public static function get( string $verb ): ?array {
		$all = self::all();
		return $all[ $verb ] ?? null;
	}

	/** One shape from either: a flat row, or {callable, descriptor}. Null when it cannot be called. */
	public static function normalize( string $verb, $row, array $ann = array() ): ?array {
		if ( ! is_array( $row ) ) {
			return null;
		}
		if ( isset( $row['descriptor'] ) && is_array( $row['descriptor'] ) ) {
			$row = array_merge( $row['descriptor'], array( 'callback' => $row['callable'] ?? ( $row['callback'] ?? null ) ) );
		}
		if ( '' === $verb || is_numeric( $verb ) ) {
			$verb = (string) ( $row['verb'] ?? '' );   // a list-shaped row names its verb inside
		}
		if ( '' === $verb || ! preg_match( '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $verb ) ) {
			return null;
		}
		$cb = $row['callback'] ?? ( $row['callable'] ?? null );
		if ( ! is_callable( $cb ) ) {
			return null;
		}
		$word = strtolower( (string) ( $row['tier'] ?? ( $row['min_tier'] ?? '' ) ) );
		$tier = self::TIER_WORDS[ $word ] ?? 'admin';
		$a    = isset( $ann[ $verb ] ) && is_array( $ann[ $verb ] ) ? $ann[ $verb ] : array();
		// A write unless someone said it is not: an unlabelled verb is never run unconfirmed.
		$side = array_key_exists( 'side_effect', $row ) ? ! empty( $row['side_effect'] )
			: ( array_key_exists( 'side_effect', $a ) ? ! empty( $a['side_effect'] ) : true );
		return array(
			'verb'          => $verb,
			'provider'      => (string) ( $row['provider'] ?? ( $row['source'] ?? '' ) ),
			'app'           => (string) ( $row['app'] ?? ( $a['app'] ?? '' ) ),
			'tier'          => $tier,
			'tier_word'     => $word,
			'kiosk'         => ! empty( $row['kiosk'] ),
			'kiosk_bounded' => ! empty( $row['kiosk_bounded'] ),
			'side_effect'   => $side,
			// A write is outside unless said otherwise; a read never is.
			'outside'       => $side && (bool) ( $row['outside'] ?? ( $a['outside'] ?? true ) ),
			'needs_app'     => (bool) ( $row['needs_app'] ?? ( $a['needs_app'] ?? true ) ),
			'self_gated'    => (bool) ( $a['self_gated'] ?? false ),
			'summary'       => (string) ( $row['summary'] ?? '' ),
			'callback'      => $cb,
		);
	}

	/* ── Who may ──────────────────────────────────────────────────────────────── */

	/**
	 * Role => rung, after the business's choices. Validated: a value off the ladder is
	 * dropped, the admin rung is refused to a role that is not admin-level, and the
	 * shared-device role is never placed (it is always the kiosk rung, decided below).
	 */
	public static function role_tiers(): array {
		$tiers  = self::DEFAULT_ROLE_TIERS;
		$stored = function_exists( 'get_option' ) ? get_option( self::OPT_ROLE_TIERS, array() ) : array();
		if ( is_array( $stored ) ) {
			foreach ( $stored as $role => $t ) {
				if ( in_array( (string) $t, array( 'viewer', 'staff', 'admin' ), true ) ) {
					$tiers[ (string) $role ] = (string) $t;   // an invalid value leaves the default
				}
			}
		}
		if ( function_exists( 'apply_filters' ) ) {
			$f     = apply_filters( 'zdz_role_tiers', $tiers );
			$tiers = is_array( $f ) ? $f : $tiers;
		}
		$out = array();
		foreach ( $tiers as $role => $t ) {
			$role = (string) $role;
			$t    = (string) $t;
			if ( ! isset( self::RANK[ $t ] ) || 'kiosk' === $t ) {
				continue;
			}
			$shared = class_exists( 'ZDZ_User_Roles' ) ? ZDZ_User_Roles::SHARED_DEVICE_ROLE : 'zdz_general';
			if ( $shared === $role ) {
				continue;
			}
			if ( 'admin' === $t && ! self::is_admin_level( $role ) ) {
				$t = 'staff';   // a business may narrow a floor, never widen it
			}
			$out[ $role ] = $t;
		}
		return $out;
	}

	private static function is_admin_level( string $role ): bool {
		if ( class_exists( 'ZDZ_User_Roles' ) ) {
			return ZDZ_User_Roles::is_admin_role( $role );
		}
		return in_array( $role, array( 'administrator', 'zdz_owner', 'zdz_admin' ), true );
	}

	/** This person's rung: kiosk | viewer | staff | admin | '' (no rung: refused). */
	public static function tier_of( int $uid ): string {
		if ( $uid <= 0 ) {
			return '';
		}
		if ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $uid ) ) {
			return 'kiosk';
		}
		if ( class_exists( 'ZDZ_User_Roles' ) && ZDZ_User_Roles::is_shared_device( $uid ) ) {
			return 'kiosk';
		}
		if ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_admin( $uid ) ) {
			return 'admin';
		}
		$u     = get_userdata( $uid );
		$roles = $u ? (array) $u->roles : array();
		$map   = self::role_tiers();
		$best  = '';
		foreach ( $roles as $role ) {
			$t = $map[ (string) $role ] ?? '';
			if ( '' !== $t && ( '' === $best || self::RANK[ $t ] > self::RANK[ $best ] ) ) {
				$best = $t;
			}
		}
		return $best;
	}

	/** The tier word the chat bridges were written for: an operator or shop role keeps "operator". */
	public static function bridge_tier( int $uid ): string {
		$t = self::tier_of( $uid );
		if ( 'staff' === $t ) {
			$u = get_userdata( $uid );
			if ( $u && array_intersect( (array) $u->roles, array( 'zdz_operator', 'zdz_mfg' ) ) ) {
				return 'operator';
			}
		}
		return $t;
	}

	/**
	 * May this person use this verb? Returns '' when yes, else the reason:
	 * unknown | signed_out | kiosk | tier | app.
	 */
	public static function why_not( string $verb, int $uid ): string {
		$c = self::get( $verb );
		if ( ! $c ) {
			return 'unknown';
		}
		if ( $uid <= 0 ) {
			return 'signed_out';
		}
		$t = self::tier_of( $uid );
		if ( '' === $t ) {
			return 'tier';
		}
		if ( 'kiosk' === $t ) {
			// A shared device: a verb that says so, or has a bounded form; never a write.
			return ( ! $c['side_effect'] && ( $c['kiosk'] || $c['kiosk_bounded'] ) ) ? '' : 'kiosk';
		}
		if ( ! $c['self_gated'] && self::RANK[ $t ] < self::RANK[ $c['tier'] ] ) {
			return 'tier';
		}
		if ( $c['needs_app'] && '' !== $c['app'] && class_exists( 'ZDZ_Plugin_API' ) && method_exists( 'ZDZ_Plugin_API', 'user_can_access_app' )
			&& ! ZDZ_Plugin_API::user_can_access_app( $uid, $c['app'] ) ) {
			return 'app';
		}
		return '';
	}

	public static function can( string $verb, int $uid ): bool {
		return '' === self::why_not( $verb, $uid );
	}

	/**
	 * Run a verb for the signed-in person. Never acts on a write: that returns needs_confirm.
	 * The bridges' own envelope comes back ({success, denied, message, …}); a refusal uses the same shape.
	 */
	public static function invoke( string $verb, array $args = array() ): array {
		$uid = (int) get_current_user_id();
		$why = self::why_not( $verb, $uid );
		if ( '' !== $why ) {
			$msg = array(
				'unknown'    => __( 'That is not something the app can do.', 'zorderz' ),
				'signed_out' => __( 'Sign in first.', 'zorderz' ),
				'kiosk'      => __( 'That is not available on the shared device.', 'zorderz' ),
				'tier'       => __( 'That is not available on your account.', 'zorderz' ),
				'app'        => __( 'That is not available on your account.', 'zorderz' ),
			);
			return array( 'success' => 'unknown' !== $why, 'denied' => true, 'reason' => $why, 'message' => $msg[ $why ], 'verb' => $verb, 'source' => 'zdz_capabilities' );
		}
		$c = self::get( $verb );
		// The caller never names who is asking: the signed-in person does.
		foreach ( self::IDENTITY_KEYS as $k ) {
			unset( $args[ $k ] );
		}
		$args['user_id']            = $uid;
		$args['requesting_user_id'] = $uid;
		$args['is_kiosk']           = 'kiosk' === self::tier_of( $uid );
		$args['tier']               = self::bridge_tier( $uid );
		// Any write waits for a person: it is confirmed in chat; this runs reads only.
		if ( $c['side_effect'] ) {
			return array( 'success' => false, 'needs_confirm' => true, 'route' => 'chat', 'verb' => $verb,
				'message' => __( 'That changes something, so it is confirmed in chat.', 'zorderz' ), 'source' => 'zdz_capabilities' );
		}
		try {
			$out = call_user_func( $c['callback'], $args );
		} catch ( \Throwable $e ) {
			error_log( 'ZDZ_Capabilities: ' . $verb . ' failed - ' . $e->getMessage() );
			return array( 'success' => false, 'error' => __( 'That could not be answered just now.', 'zorderz' ), 'verb' => $verb, 'source' => 'zdz_capabilities' );
		}
		return is_array( $out ) ? $out : array( 'success' => false, 'error' => __( 'No answer.', 'zorderz' ), 'verb' => $verb );
	}

	/* ── For an administrator to read ────────────────────────────────────────── */

	public static function register_routes(): void {
		register_rest_route( ZDZ_REST_NS, '/capabilities', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_list' ),
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
		) );
	}

	/** The list without callbacks, plus what the asking administrator may use. */
	public static function rest_list(): array {
		$uid = (int) get_current_user_id();
		$out = array();
		foreach ( self::all() as $verb => $c ) {
			unset( $c['callback'] );
			$c['you'] = self::why_not( $verb, $uid ) ?: 'yes';
			$out[]    = $c;
		}
		return array( 'count' => count( $out ), 'verbs' => $out );
	}
}
