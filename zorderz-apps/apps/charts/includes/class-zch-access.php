<?php
/**
 * Who may see what in Charts — the first lock.
 *
 * The same shape as the platform's ZDZ_Data_Permissions: a role default, then a per-user
 * allow/deny override (user meta `zch_permissions`), then a hard deny. Least privilege
 * throughout:
 *   - a role not in the table gets NOTHING (no fallback to another role's defaults);
 *   - a user holding several roles gets the MOST restrictive one's defaults, and the shared
 *     device always wins — nothing can override it;
 *   - the platform's view_company_revenue / view_others_data / view_own_commission switches
 *     are honoured, so Charts cannot show what another app would hide.
 *
 * The result is an `access` block that PHP checks on every route and also seals into the hub
 * request, where the hub checks it again.
 *
 * Keys (per-user overridable, except on the shared device):
 *   charts_access       open Charts at all (health, vocab, charts, saved graphs)
 *   charts_ask          the ask bar (the hub's question reader and model)
 *   charts_company      whole-company figures; without it a person sees only their own jobs
 *   charts_by_person    split or filter by other salespeople
 *   charts_money        dollar figures (their own, or the company's with charts_company)
 *   charts_line_items   individual line items (their own, or all with charts_company)
 *   charts_admin        data status (the import log) and the model list
 *   charts_accounts     named business accounts
 *   charts_invoices     open an invoice card and its questions: admins see any job, anyone
 *                       else only the jobs that carry their own initials
 *   charts_compare      comparisons across salespeople AS JOB COUNTS for someone without
 *                       whole-company figures; follows the platform's "others' data" switch
 *                       unless overridden here. The hub turns such a chart's dollars into counts
 *                       and keeps job lists to the person's own.
 *
 * @package Zorderz\Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCH_Access {

	const META = 'zch_permissions';

	const KEYS = array(
		'charts_access', 'charts_ask', 'charts_company', 'charts_by_person',
		'charts_money', 'charts_line_items', 'charts_admin', 'charts_accounts',
		'charts_invoices', 'charts_compare',
	);

	/** Roles whose invoice cards cover every job (everyone else: their own). */
	const ALL_INVOICE_ROLES = array( 'zdz_owner', 'administrator', 'zdz_admin' );

	/** Lower = more restrictive; used when a user holds several roles. */
	const RANK = array(
		'zdz_general'   => 0,
		'zdz_tech'      => 1,
		'zdz_mfg'       => 2,
		'zdz_operator'  => 3,
		'zdz_sales'     => 4,
		'zdz_admin'     => 5,
		'administrator' => 5,
		'zdz_owner'     => 6,
	);

	/** The shape of a person's initials: what the hub scopes "own jobs" by. */
	const INITIALS = '/^[A-Z]{1,8}$/D';

	/**
	 * Role defaults. 'theme:<key>' means "whatever ZDZ_Data_Permissions says for this user",
	 * so an owner who has turned a person's revenue off elsewhere turns it off here too.
	 * Anything not listed is deny.
	 */
	public static function role_defaults() {
		$full     = array(
			'charts_access'     => 'allow',
			'charts_ask'        => 'allow',
			'charts_company'    => 'theme:view_company_revenue',
			'charts_by_person'  => 'theme:view_others_data',
			'charts_money'      => 'allow',
			'charts_line_items' => 'allow',
			'charts_admin'      => 'allow',
			'charts_accounts'   => 'allow',
			'charts_invoices'   => 'allow',
			'charts_compare'    => 'allow',   // moot with whole-company figures; kept for an admin whose company switch is off.
		);
		$defaults = array(
			'zdz_owner'     => $full,
			'administrator' => $full,
			'zdz_admin'     => array_merge( $full, array( 'charts_admin' => 'deny' ) ),
			// A salesperson sees their OWN jobs (and their own dollars, the same figures their
			// commission comes from); nothing about anyone else.
			'zdz_sales'     => array(
				'charts_access'     => 'allow',
				'charts_ask'        => 'allow',
				'charts_company'    => 'deny',
				'charts_by_person'  => 'deny',
				'charts_money'      => 'theme:view_own_commission',
				'charts_line_items' => 'deny',
				'charts_admin'      => 'deny',
				'charts_accounts'   => 'deny',
				'charts_invoices'   => 'allow',   // their OWN jobs' cards only (see block()).
				'charts_compare'    => 'theme:view_others_data',
			),
			// Operator, shop and field roles: nothing until granted per user.
			'zdz_operator'  => array(),
			'zdz_mfg'       => array(),
			'zdz_tech'      => array(),
			'zdz_general'   => array(),
		);
		$f = function_exists( 'apply_filters' ) ? apply_filters( 'zch_role_defaults', $defaults ) : $defaults;
		$f = is_array( $f ) ? $f : $defaults;
		// The shared device is a floor no filter can lift.
		$f['zdz_general'] = array();
		return $f;
	}

	private static $cache = array();

	public static function flush( $uid = null ) {
		if ( null === $uid ) {
			self::$cache = array();
		} else {
			unset( self::$cache[ (int) $uid ] );
		}
	}

	/** The user's governing role: the most restrictive known role they hold. */
	public static function governing_role( $user ) {
		$best      = null;
		$best_rank = PHP_INT_MAX;
		foreach ( (array) $user->roles as $r ) {
			if ( isset( self::RANK[ $r ] ) && self::RANK[ $r ] < $best_rank ) {
				$best      = $r;
				$best_rank = self::RANK[ $r ];
			}
		}
		return $best;   // null = no known role -> nothing.
	}

	/** Is this the shared device (by role, trait or the platform's kiosk check)? */
	public static function is_kiosk( $uid ) {
		$uid = (int) $uid;
		if ( $uid <= 0 ) {
			return false;
		}
		if ( class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $uid ) ) {
			return true;
		}
		if ( class_exists( 'ZDZ_Hierarchy' ) && method_exists( 'ZDZ_Hierarchy', 'is_kiosk' ) && ZDZ_Hierarchy::is_kiosk( $uid ) ) {
			return true;
		}
		$u = function_exists( 'get_userdata' ) ? get_userdata( $uid ) : null;
		return $u && in_array( 'zdz_general', (array) $u->roles, true );
	}

	/** Resolve every key to 'allow' or 'deny' for a user. */
	public static function resolve( $uid ) {
		$uid = (int) $uid;
		if ( isset( self::$cache[ $uid ] ) ) {
			return self::$cache[ $uid ];
		}
		$deny = array_fill_keys( self::KEYS, 'deny' );
		$user = $uid ? get_userdata( $uid ) : false;
		if ( ! $user ) {
			return $deny;
		}
		$role = self::governing_role( $user );
		if ( null === $role || 'zdz_general' === $role || self::is_kiosk( $uid ) ) {   // an unknown role, or the shared device.
			self::$cache[ $uid ] = $deny;
			return $deny;
		}
		$defs     = self::role_defaults();
		$role_def = isset( $defs[ $role ] ) && is_array( $defs[ $role ] ) ? $defs[ $role ] : array();
		$over     = get_user_meta( $uid, self::META, true );
		if ( ! is_array( $over ) ) {
			$over = array();
		}
		$out = array();
		foreach ( self::KEYS as $k ) {
			$v = isset( $over[ $k ] ) && in_array( $over[ $k ], array( 'allow', 'deny' ), true ) ? $over[ $k ]
				: ( isset( $role_def[ $k ] ) ? $role_def[ $k ] : 'deny' );
			if ( is_string( $v ) && 0 === strpos( $v, 'theme:' ) ) {
				$tk = substr( $v, 6 );
				$v  = ( class_exists( 'ZDZ_Data_Permissions' ) && ZDZ_Data_Permissions::can( $uid, $tk ) ) ? 'allow' : 'deny';
			}
			$out[ $k ] = 'allow' === $v ? 'allow' : 'deny';
		}
		// Everything hangs off charts_access.
		if ( 'allow' !== $out['charts_access'] ) {
			$out = $deny;
		}
		self::$cache[ $uid ] = $out;
		return $out;
	}

	public static function can( $uid, $key ) {
		$r = self::resolve( $uid );
		return isset( $r[ $key ] ) && 'allow' === $r[ $key ];
	}

	/** The user's salesperson initials (the same meta the rest of the platform scopes on). */
	public static function initials( $uid ) {
		return strtoupper( trim( (string) get_user_meta( (int) $uid, 'zdz_user_initials', true ) ) );
	}

	/**
	 * The sealed access block for one hub call.
	 *
	 * @param int    $uid     the person the call is for (never the assistant or an app).
	 * @param string $caller  'ui' | 'bot' | 'app:<slug>' | 'cron'
	 */
	public static function block( $uid, $caller = 'ui' ) {
		$r    = self::resolve( $uid );
		$kind = 0 === strpos( (string) $caller, 'app:' ) ? 'app' : $caller;
		$none = array(
			'caller'     => $caller,
			'ops'        => array( 'health' ),
			'scope'      => array(),
			'money'      => false,
			'by_person'  => false,
			'line_items' => 'none',
			'kiosk'      => false,
			'accounts'   => false,
			'invoices'   => 'none',
			'me'         => null,
			'compare'    => false,
		);
		if ( 'allow' !== $r['charts_access'] || ! in_array( $kind, array( 'ui', 'bot', 'app', 'cron' ), true ) ) {
			return $none;
		}
		$company = 'allow' === $r['charts_company'];
		$scope   = null;
		if ( ! $company ) {
			$init  = self::initials( $uid );
			$scope = '' !== $init ? array( $init ) : array();     // no initials -> nothing to show.
		}
		$ops = array( 'health', 'vocab', 'run_spec', 'run_v2', 'drill' );
		if ( 'allow' === $r['charts_ask'] ) {
			$ops[] = 'ask';
			$ops[] = 'flag';
		}
		if ( 'allow' === $r['charts_line_items'] ) {
			$ops[] = 'search_lines';
			$ops[] = 'list_jobs';
		}
		if ( 'allow' === $r['charts_admin'] ) {
			$ops[] = 'data_status';
		}
		// Invoice cards: every job for the owner and admins (with company-wide figures);
		// otherwise only the person's own jobs, which needs their initials. A card is reached
		// from a job list, so "own" also lists the person's own jobs.
		$invoices = self::invoice_breadth( $uid, $r, $company );
		$me       = self::initials( $uid );
		if ( 'none' !== $invoices ) {
			$ops[] = 'invoice';
			$ops[] = 'pivot';
			if ( ! in_array( 'list_jobs', $ops, true ) ) {
				$ops[] = 'list_jobs';
			}
		}
		$me_ok = 1 === preg_match( self::INITIALS, $me );
		$block = array(
			'caller'     => $caller,
			'ops'        => $ops,
			'scope'      => $scope,
			'money'      => 'allow' === $r['charts_money'],
			'by_person'  => $company && 'allow' === $r['charts_by_person'],
			'line_items' => 'allow' === $r['charts_line_items'] ? ( $company ? 'all' : 'own' ) : 'none',
			'kiosk'      => false,
			// Named accounts: people at the screen with company-wide figures only.
			'accounts'   => 'ui' === $kind && $company && 'allow' === $r['charts_accounts'],
			'invoices'   => $invoices,
			'me'         => $me_ok ? $me : null,
			// Comparisons as counts: only for a scoped person (with the company switch, by_person
			// covers it), and never without `me` (the own-jobs rule needs it).
			'compare'    => ! $company && array() !== $scope && $me_ok && 'allow' === $r['charts_compare'] && 'cron' !== $kind,
		);
		// Floors for callers that are not the person at the screen (the hub applies the same
		// floors again).
		if ( 'bot' === $kind || 'app' === $kind ) {
			$block['ops']        = array_values( array_intersect( $block['ops'], array( 'health', 'vocab', 'run_spec', 'run_v2' ) ) );
			$block['line_items'] = 'none';
			$block['accounts']   = false;            // their models run elsewhere: never names.
			$block['invoices']   = 'none';           // cards are for the person at the screen.
		} elseif ( 'cron' === $kind ) {
			$block['ops']      = array( 'health' );
			$block['invoices'] = 'none';
		}
		if ( array() === $scope ) {
			$block['ops'] = array( 'health' );
		}
		return $block;
	}

	/** 'all' (owner and admins with company-wide figures), 'own' (anyone else allowed, by their initials) or 'none'. */
	public static function invoice_breadth( $uid, $r = null, $company = null ) {
		$r = $r ? $r : self::resolve( $uid );
		if ( 'allow' !== ( $r['charts_access'] ?? 'deny' ) || 'allow' !== ( $r['charts_invoices'] ?? 'deny' ) ) {
			return 'none';
		}
		if ( null === $company ) {
			$company = 'allow' === $r['charts_company'];
		}
		// A card shows a job's line items. Someone set to "Individual line items: Deny" gets no
		// cards at all; an owner or admin needs the line-items right for every job's card.
		$over = get_user_meta( (int) $uid, self::META, true );
		if ( is_array( $over ) && 'deny' === ( $over['charts_line_items'] ?? '' ) ) {
			return 'none';
		}
		$user = get_userdata( (int) $uid );
		$role = $user ? self::governing_role( $user ) : null;
		if ( $company && in_array( $role, self::ALL_INVOICE_ROLES, true ) && 'allow' === $r['charts_line_items'] ) {
			return 'all';
		}
		return preg_match( self::INITIALS, self::initials( $uid ) ) ? 'own' : 'none';
	}

	/**
	 * The Charts tile for each person Charts allows, through the platform's ADD-only per-person grant
	 * (zdz_extra_allowed_apps). The platform checks Safe Mode and the person's denied apps before it, so neither can
	 * be overridden here, and the shared device never holds Charts (allowed() refuses it).
	 */
	public static function extra_allowed( $apps, $user_id = 0 ) {
		$apps = is_array( $apps ) ? $apps : array();
		$uid  = (int) $user_id;
		if ( $uid > 0 && ! in_array( ZCH_APP_ID, $apps, true ) && self::allowed( $uid ) ) {
			$apps[] = ZCH_APP_ID;
		}
		return $apps;
	}

	/** May this user open Charts at all (menu, shortcode, REST permission)? */
	public static function allowed( $uid = null ) {
		$uid = null === $uid ? get_current_user_id() : (int) $uid;
		if ( ! $uid || ! self::can( $uid, 'charts_access' ) ) {
			return false;
		}
		// A two-factor plugin returns false here for a person who has not set it up.
		return (bool) apply_filters( 'zch_user_has_2fa', true, $uid );
	}

	/* ── The user screen ─────────────────────────────────────────────────── */

	public static function init_admin() {
		add_action( 'show_user_profile', array( __CLASS__, 'profile_fields' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'profile_fields' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile' ) );
	}

	private static function labels() {
		return array(
			'charts_access'     => __( 'Open Charts', 'zorderz' ),
			'charts_ask'        => __( 'Ask questions', 'zorderz' ),
			'charts_company'    => __( 'Whole-company figures (otherwise only their own jobs)', 'zorderz' ),
			'charts_by_person'  => __( 'Figures for other salespeople', 'zorderz' ),
			'charts_money'      => __( 'Dollar figures', 'zorderz' ),
			'charts_line_items' => __( 'Individual line items', 'zorderz' ),
			'charts_admin'      => __( 'Data status and import log', 'zorderz' ),
			'charts_accounts'   => __( 'Named business accounts (charts by account name)', 'zorderz' ),
			'charts_invoices'   => __( 'Invoice cards (owner and admins: every job; others: their own jobs only). Off when "Individual line items" is set to Deny here.', 'zorderz' ),
			'charts_compare'    => __( 'Comparisons across salespeople as job counts (never dollars), for someone without whole-company figures. Follows the "Others\' data" permission unless set here.', 'zorderz' ),
		);
	}

	/** Only site administrators may set overrides; everyone may see their own. */
	public static function profile_fields( $user ) {
		$editable = current_user_can( 'manage_options' );
		if ( ! $editable && get_current_user_id() !== (int) $user->ID ) {
			return;
		}
		$over = get_user_meta( $user->ID, self::META, true );
		if ( ! is_array( $over ) ) {
			$over = array();
		}
		$eff = self::resolve( $user->ID );
		echo '<h2>' . esc_html__( 'Charts access', 'zorderz' ) . '</h2><table class="form-table" role="presentation">';
		if ( 'zdz_general' === self::governing_role( $user ) || self::is_kiosk( $user->ID ) ) {
			echo '<tr><td colspan="2">' . esc_html__( 'The shared device never has Charts access.', 'zorderz' ) . '</td></tr></table>';
			return;
		}
		wp_nonce_field( 'zch_perm_' . $user->ID, 'zch_perm_nonce' );
		foreach ( self::labels() as $k => $label ) {
			$cur = isset( $over[ $k ] ) ? $over[ $k ] : 'default';
			echo '<tr><th>' . esc_html( $label ) . '</th><td>';
			if ( $editable ) {
				echo '<select name="zch_perm[' . esc_attr( $k ) . ']">';
				foreach ( array( 'default' => __( 'Role default', 'zorderz' ), 'allow' => __( 'Allow', 'zorderz' ), 'deny' => __( 'Deny', 'zorderz' ) ) as $v => $t ) {
					echo '<option value="' . esc_attr( $v ) . '"' . selected( $cur, $v, false ) . '>' . esc_html( $t ) . '</option>';
				}
				echo '</select> ';
			}
			echo '<span class="description">' . esc_html__( 'Now:', 'zorderz' ) . ' <strong>' . esc_html( 'allow' === $eff[ $k ] ? __( 'allowed', 'zorderz' ) : __( 'denied', 'zorderz' ) ) . '</strong></span></td></tr>';
		}
		echo '</table>';
	}

	public static function save_profile( $uid ) {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['zch_perm'] ) ) {
			return;
		}
		check_admin_referer( 'zch_perm_' . $uid, 'zch_perm_nonce' );
		$in   = (array) wp_unslash( $_POST['zch_perm'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each value is sanitize_key()'d below.
		$over = array();
		foreach ( self::KEYS as $k ) {
			$v = isset( $in[ $k ] ) ? sanitize_key( $in[ $k ] ) : 'default';
			if ( in_array( $v, array( 'allow', 'deny' ), true ) ) {
				$over[ $k ] = $v;
			}
		}
		$before = get_user_meta( $uid, self::META, true );
		update_user_meta( $uid, self::META, $over );
		self::flush( $uid );
		if ( $before !== $over && class_exists( 'ZCH_Store' ) ) {
			ZCH_Store::audit( 'perm_change', true, 'user_' . (int) $uid, null, null, wp_json_encode( $over ) );
		}
	}
}
