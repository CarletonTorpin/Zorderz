<?php
/**
 * ZDZ_Party — the authoritative "selectable people" roster.
 *
 * ONE source of truth for "which person?" everywhere the platform asks the
 * question: transcript participants, share targets, assignees, @-mentions.
 *
 * A person is SELECTABLE iff they are an ACTIVE registered user with a USABLE
 * email address — and NOTHING else gates them. In particular, selectability is
 * NOT filtered by whether the user happens to hold a given app's access grant.
 * That incidental-capability filter is exactly the bug this class exists to
 * kill: a real, registered, emailable user (the "Ron" case) was invisible in
 * an app's share/participant picker because an admin had never granted them
 * that app — so a transcript "shared with Ron" silently reached no one.
 *
 * This is the first concrete shape of the Zorderz **Party** core service
 * (BID-2): who counts as a selectable party must not depend on an incidental
 * grant. Consumers (a share picker, a Jobs assignee, a Surveys owner, future
 * @-mentions) READ this list instead of rolling their own get_users() /
 * capability filter.
 *
 * Exposure:
 *   - PHP  : ZDZ_Party::selectable_people( $args )   (canonical)
 *   - Filter: apply_filters( 'zdz_selectable_people', $people, $args )
 *   - REST : GET /wp-json/zorderz/v1/party/people[?search=&exclude=1,2]
 *
 * Privacy: the returned rows carry id / name / initials / role only. Raw email
 * is the eligibility signal and stays server-side; a caller that must actually
 * email a party resolves the address itself (it already holds the user id).
 *
 * Contract: PARTY-ROSTER-CONTRACT-v1.md
 *
 * @since   1.1.0
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Party {

	/**
	 * Roles that are NEVER a selectable party. zdz_general is the shared-kiosk
	 * hard floor — the kiosk is a device, not a person, and identity mapping
	 * excludes it for the same reason.
	 */
	const NEVER_PARTY_ROLES = array( 'zdz_general' );

	/** Per-request memo so repeated pickers on one page don't re-query. */
	private static $memo = array();

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * The authoritative selectable-people list.
	 *
	 * @param array $args {
	 *     @type int[]  $exclude      User ids to omit (e.g. the current user).
	 *     @type bool   $include_self Include the current user. Default true.
	 *     @type string $search       Optional case-insensitive name/email filter.
	 * }
	 * @return array[] List of rows: array{ id:int, name:string, initials:string, role:string }.
	 */
	public static function selectable_people( array $args = array() ): array {
		$args = wp_parse_args(
			$args,
			array(
				'exclude'      => array(),
				'include_self' => true,
				'search'       => '',
			)
		);

		$exclude = array_map( 'intval', (array) $args['exclude'] );
		if ( empty( $args['include_self'] ) ) {
			$exclude[] = get_current_user_id();
		}

		$people = self::all_active_emailable();

		// Apply per-call exclude + search over the memoized base list.
		$search = strtolower( trim( (string) $args['search'] ) );
		$out    = array();
		foreach ( $people as $row ) {
			if ( in_array( (int) $row['id'], $exclude, true ) ) {
				continue;
			}
			if ( '' !== $search ) {
				$hay = strtolower( $row['name'] . ' ' . $row['_email'] );
				if ( false === strpos( $hay, $search ) ) {
					continue;
				}
			}
			// Drop the private _email before handing the row out.
			unset( $row['_email'] );
			$out[] = $row;
		}

		/**
		 * Filter the authoritative selectable-people list.
		 *
		 * Consumers should READ this list, not rebuild it. Use this filter only
		 * to ADD people or adjust presentation — never to re-introduce an
		 * app-grant / capability filter, which is the exact defect ZDZ_Party
		 * fixes (a real user must not vanish from a picker for lack of a grant).
		 *
		 * @param array[] $out  The selectable people (id/name/initials/role).
		 * @param array   $args The resolved query args.
		 */
		return apply_filters( 'zdz_selectable_people', $out, $args );
	}

	/**
	 * Base list: every active user with a usable email, minus the kiosk role.
	 * Memoized per request. Carries a private `_email` used only for search;
	 * selectable_people() strips it before returning.
	 *
	 * @return array[]
	 */
	private static function all_active_emailable(): array {
		if ( isset( self::$memo['base'] ) ) {
			return self::$memo['base'];
		}

		// 'fields' => 'all' gives roles + email in one query. Ordered by display
		// name for stable UI.
		$users = get_users(
			array(
				'fields'  => 'all',
				'orderby' => 'display_name',
				'order'   => 'ASC',
			)
		);

		$base = array();
		foreach ( $users as $user ) {
			$roles = (array) $user->roles;
			if ( array_intersect( $roles, self::NEVER_PARTY_ROLES ) ) {
				continue; // shared kiosk is never a party
			}
			if ( ! self::is_active( $user ) ) {
				continue;
			}
			$email = trim( (string) $user->user_email );
			if ( '' === $email || ! is_email( $email ) ) {
				continue; // no usable email -> cannot be a real share/participant target
			}

			$name = self::display_name( $user );
			$base[] = array(
				'id'       => (int) $user->ID,
				'name'     => $name,
				'initials' => self::initials( $name ),
				'role'     => $roles ? (string) reset( $roles ) : '',
				'_email'   => $email,
			);
		}

		self::$memo['base'] = $base;
		return $base;
	}

	/**
	 * Is this a real, active account? Excludes multisite spam/deleted flags and
	 * a theme convention meta `zdz_inactive` (a former employee hidden from every
	 * picker without deleting their historical records).
	 */
	private static function is_active( WP_User $user ): bool {
		if ( isset( $user->spam ) && 1 === (int) $user->spam ) {
			return false;
		}
		if ( isset( $user->deleted ) && 1 === (int) $user->deleted ) {
			return false;
		}
		if ( get_user_meta( (int) $user->ID, 'zdz_inactive', true ) ) {
			return false;
		}
		// Read-time alias: honor the pre-rename key on installs upgraded from the
		// private lineage that set it before ZDZ_Rename_Migration ran.
		if ( get_user_meta( (int) $user->ID, 'ts_inactive', true ) ) {
			return false;
		}
		return true;
	}

	private static function display_name( WP_User $user ): string {
		$name = trim( (string) $user->display_name );
		if ( '' === $name ) {
			$name = trim( (string) $user->user_login );
		}
		return $name;
	}

	private static function initials( string $name ): string {
		$parts = preg_split( '/\s+/', trim( $name ) );
		$ini   = '';
		foreach ( (array) $parts as $p ) {
			if ( '' === $p ) {
				continue;
			}
			$ini .= strtoupper( mb_substr( $p, 0, 1 ) );
			if ( strlen( $ini ) >= 2 ) {
				break;
			}
		}
		return '' !== $ini ? $ini : '?';
	}

	/* ----------------------------------- REST ----------------------------------- */

	public static function register_routes(): void {
		register_rest_route(
			ZDZ_REST_NS,
			'/party/people',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_people' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
				'args'                => array(
					'search'  => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'exclude' => array( 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);
	}

	public static function rest_people( WP_REST_Request $request ) {
		$exclude = array();
		$raw     = (string) $request->get_param( 'exclude' );
		if ( '' !== $raw ) {
			$exclude = array_values( array_filter( array_map( 'intval', explode( ',', $raw ) ) ) );
		}

		$people = self::selectable_people(
			array(
				'search'  => (string) $request->get_param( 'search' ),
				'exclude' => $exclude,
			)
		);

		return rest_ensure_response( array( 'people' => $people ) );
	}
}

ZDZ_Party::init();
