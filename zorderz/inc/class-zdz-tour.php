<?php
/**
 * The guided tour: a click-through of the Home Screen made for the person taking it.
 *
 * Two tours, chosen by who the person is:
 *   - sample: for an administrator, once the sample company is loaded (Tools → Zorderz Data). It finds the way
 *     around, then runs one test job from estimate to invoice with the sample company's made-up data.
 *   - team: for everyone else, on any site. It shows the apps the person's role uses and what each one lets them
 *     do, as that role does it: whose jobs and estimates they see, what they may hand off, schedule and close, what
 *     money they see, and that the business settings belong to an administrator. It only points and explains: no
 *     step asks for anything that writes a record.
 *
 * What the tour knows about the person is read from the same rules the apps use (profile()): the apps on their
 * Home Screen, their data permissions, how Jobs scopes them, which home figures they get, whether they write
 * estimate stubs, whether they lead a crew, and whether they can open the site's settings screens. While an
 * administrator previews a role or a person (View As), the tour is the one that role or person gets, and nothing
 * the preview does is saved.
 *
 * The steps are data. Each one names the thing on screen it points at (a CSS selector), says what it is in a sentence
 * or two, may say what to try, may name the apps it needs, and may name what the person must (`needs`) or must not
 * (`unless`) be able to do (abilities()). A step the person does not fit is left out of their tour. The page script
 * assets/js/zdz-tour.js plays them; a step whose target is not on screen still shows its card, so a changed screen
 * never strands anyone. Add, change or reorder steps with `zdz_tour_steps` (the sample tour) and
 * `zdz_tour_team_steps` (the team tour, given the profile); adjust what the tour knows with `zdz_tour_profile`.
 *
 * Never offered to the shared device, in Demo Mode, or to an account in Privacy View. An administrator can stop
 * offering the team tours (Settings → Guided tour); `zdz_tour_available` can widen or narrow either tour.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Tour {

	/** Each person's place in the sample tour: array{step:string,status:string,at:int}. */
	const META = 'zdz_tour_state';

	/** Each person's place in the team tour, kept apart so a change of role starts the other tour fresh. */
	const TEAM_META = 'zdz_tour_team_state';

	/** Set when the sample company is loaded: array{name:string,at:int,by:int}. */
	const SAMPLE_OPTION = 'zdz_sample_loaded';

	/** '0' stops offering the team tours; unset or anything else offers them. */
	const TEAM_OPTION = 'zdz_tour_team';

	/** new: never started; running / paused: has a step; done: reached the end; hidden: "not now" on the offer. */
	const STATUSES = array( 'new', 'running', 'paused', 'done', 'hidden' );

	/** The longest title and text a step may carry, so a card stays a card. */
	const TITLE_MAX = 60;
	const BODY_MAX  = 360;

	/** A step's list: at most this many lines, each at most this long. */
	const LIST_MAX = 8;
	const ITEM_MAX = 140;

	/** The apps the team tour gives a step of their own; the person's other apps are listed together. */
	const TEAM_APPS = array( 'estimate-creator', 'jobs', 'scheduler', 'projects', 'leads', 'commission-calculator', 'hours', 'internal-messaging', 'sales-analytics' );

	/** @var bool|null this request's answer to sample_loaded(). */
	private static $sample = null;

	/** @var array<int,array> this request's profiles, by account. */
	private static $profiles = array();

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/* ------------------------------------------------------------------ the sample company */

	/**
	 * Whether the sample company is on this site: the loader's marker, or (a sample loaded before the marker existed)
	 * one of the demo accounts the loader made. Checked once per request.
	 */
	public static function sample_loaded(): bool {
		if ( null !== self::$sample ) {
			return self::$sample;
		}
		$on = is_array( get_option( self::SAMPLE_OPTION, null ) );
		if ( ! $on && function_exists( 'get_users' ) && class_exists( 'ZDZ_Data_Portability' ) ) {
			$found = get_users(
				array(
					'meta_key' => ZDZ_Data_Portability::SAMPLE_USER_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one row, once per request, only until the marker is written.
					'number'   => 1,
					'fields'   => 'ID',
				)
			);
			$on = ! empty( $found );
			if ( $on ) {
				self::mark_sample_loaded( 0 );
			}
		}
		self::$sample = $on;
		return $on;
	}

	/** Called by the sample loader after a real (not preview) load. */
	public static function mark_sample_loaded( int $by ): void {
		update_option(
			self::SAMPLE_OPTION,
			array(
				'name' => 'TestCo',
				'at'   => time(),
				'by'   => $by,
			),
			false
		);
		self::$sample = true;
	}

	/** Whether the team tours are offered (an administrator can stop them in Settings → Guided tour). */
	public static function team_on(): bool {
		return '0' !== (string) get_option( self::TEAM_OPTION, '1' );
	}

	/* ------------------------------------------------------------------ who it is for */

	/**
	 * What the tour knows about the person on this account's Home Screen. While its administrator previews a person
	 * or a role (ZDZ_View_As), the person or role previewed: an account's own settings, or the role's defaults.
	 *
	 * @return array{uid:int,preview:string,role:string,role_label:string,first_name:string,admin:bool,office:bool,
	 *               kiosk:bool,apps:string[],names:array<string,string>,descs:array<string,string>,tiles:string,
	 *               jobs:string,crew:int,stub:bool,self_schedule:bool,can:array<string,bool>}
	 */
	public static function profile( int $uid ): array {
		if ( isset( self::$profiles[ $uid ] ) ) {
			return self::$profiles[ $uid ];
		}
		$p = array( 'uid' => $uid );
		if ( $uid > 0 ) {
			$who  = $uid;
			$role = '';
			if ( class_exists( 'ZDZ_View_As' ) && ZDZ_View_As::is_emulating() && function_exists( 'get_current_user_id' ) && (int) get_current_user_id() === $uid ) {
				$emu = (int) ( ZDZ_View_As::get_emulated_user_id() ?? 0 );
				if ( $emu > 0 && function_exists( 'get_userdata' ) && get_userdata( $emu ) ) {
					$who = $emu;
				} else {
					$role = (string) ( ZDZ_View_As::get_emulated_role() ?? '' );
				}
			}
			$p = ( '' !== $role ? self::of_role( $role ) : self::of_person( $who ) ) + $p;

			$labels          = class_exists( 'ZDZ_User_Roles' ) ? ZDZ_User_Roles::get_role_labels() : array();
			$r               = (string) ( $p['role'] ?? '' );
			$p['role_label'] = (string) ( $labels[ $r ] ?? ( '' !== $r ? ucfirst( str_replace( array( 'zdz_', '_' ), array( '', ' ' ), $r ) ) : '' ) );
			if ( $who !== $uid || '' !== $role ) {
				$p['preview'] = '' !== $p['role_label'] ? $p['role_label'] : __( 'team member', 'zorderz' );
			}

			// The apps on the Home Screen this account shows (a preview shows the previewed list).
			if ( class_exists( 'ZDZ_Plugin_API' ) && method_exists( 'ZDZ_Plugin_API', 'get_user_app_ids' ) ) {
				$api        = ZDZ_Plugin_API::get_instance();
				$p['apps']  = $api->get_user_app_ids( $uid );
				$all        = $api->get_all_apps();
				$p['names'] = array();
				$p['descs'] = array();
				foreach ( $p['apps'] as $id ) {
					if ( isset( $all[ $id ] ) ) {
						$c                 = ZDZ_Plugin_API::display_config( $all[ $id ], (string) $id );
						$p['names'][ $id ] = (string) ( $c['nm'] ?? $id );
						$p['descs'][ $id ] = (string) ( $c['desc'] ?? '' );
					}
				}
			}
			if ( class_exists( 'ZJOB_Jobs' ) && method_exists( 'ZJOB_Jobs', 'workers_may_self_schedule' ) ) {
				$p['self_schedule'] = ZJOB_Jobs::workers_may_self_schedule();
			}
		}
		$p = self::normalize_profile( (array) apply_filters( 'zdz_tour_profile', $p, $uid ) );
		self::$profiles[ $uid ] = $p;
		return $p;
	}

	/** What an account's own settings let it do. */
	private static function of_person( int $who ): array {
		$user = function_exists( 'get_userdata' ) ? get_userdata( $who ) : null;
		if ( ! $user ) {
			return array();
		}
		$roles = array_values( (array) ( $user->roles ?? array() ) );
		$can   = array();
		if ( class_exists( 'ZDZ_Data_Permissions' ) ) {
			foreach ( ZDZ_Data_Permissions::resolve( $who ) as $k => $v ) {
				$can[ $k ] = 'allow' === $v;
			}
		}
		$jobs = class_exists( 'ZJOB_Scope' ) ? (string) ( ZJOB_Scope::for_user( $who )['tier'] ?? '' ) : '';
		$crew = 0;
		if ( 'crew' === $jobs && class_exists( 'ZDZ_Hierarchy' ) ) {
			$crew = count( array_diff( array_map( 'intval', ZDZ_Hierarchy::get_crew( $who ) ), array( $who ) ) );
		}
		$first = trim( (string) ( $user->first_name ?? '' ) );
		if ( '' === $first ) {
			$first = (string) strtok( trim( (string) ( $user->display_name ?? '' ) ), ' ' );
		}
		$office = function_exists( 'user_can' ) && user_can( $who, 'manage_options' );
		return array(
			'role'       => (string) ( $roles[0] ?? '' ),
			'first_name' => $first,
			'admin'      => class_exists( 'ZDZ_Hierarchy' ) ? ZDZ_Hierarchy::is_admin( $who ) : $office,
			'office'     => $office,
			'kiosk'      => class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $who ),
			'tiles'      => class_exists( 'ZDZ_KPI_Metrics' ) ? ZDZ_KPI_Metrics::set_for( $who ) : '',
			'jobs'       => $jobs,
			'crew'       => $crew,
			'stub'       => function_exists( 'user_can' ) && user_can( $who, 'zest_create_zero_estimates' ),
			'can'        => $can,
		);
	}

	/** What a role's defaults let the person holding it do: an administrator previewing the role. */
	private static function of_role( string $role ): array {
		$admin = class_exists( 'ZDZ_User_Roles' ) ? ZDZ_User_Roles::is_admin_role( $role ) : in_array( $role, array( 'administrator', 'zdz_owner', 'zdz_admin' ), true );
		$kiosk = class_exists( 'ZDZ_User_Roles' ) && ZDZ_User_Roles::SHARED_DEVICE_ROLE === $role;
		$can   = array();
		if ( class_exists( 'ZDZ_Data_Permissions' ) ) {
			$all = ZDZ_Data_Permissions::ROLE_DEFAULTS;
			$row = (array) ( 'administrator' === $role ? ( $all['zdz_admin'] ?? array() ) : ( $all[ $role ] ?? array() ) );
			foreach ( ZDZ_Data_Permissions::ALL_KEYS as $k ) {
				$can[ $k ] = 'allow' === ( $row[ $k ] ?? 'deny' );
			}
		}
		$jobs = 'own';
		if ( $kiosk ) {
			$jobs = '';
		} elseif ( $admin ) {
			$jobs = 'all';
		} elseif ( class_exists( 'ZJOB_Scope' ) && in_array( $role, ZJOB_Scope::shop_roles(), true ) ) {
			$jobs = 'shop';
		} elseif ( ! empty( $can['lead_crew'] ) ) {
			$jobs = 'crew';
		}
		$wp_role = function_exists( 'get_role' ) ? get_role( $role ) : null;
		return array(
			'role'   => $role,
			'admin'  => $admin,
			'office' => $admin, // an administrator's role carries the site's administrator rights
			'kiosk'  => $kiosk,
			'tiles'  => class_exists( 'ZDZ_KPI_Metrics' ) && method_exists( 'ZDZ_KPI_Metrics', 'set_for_role' ) ? ZDZ_KPI_Metrics::set_for_role( $role ) : '',
			'jobs'   => $jobs,
			'crew'   => 0,
			'stub'   => $wp_role && $wp_role->has_cap( 'zest_create_zero_estimates' ),
			'can'    => $can,
		);
	}

	/** A profile with every key, each of its type. */
	public static function normalize_profile( array $p ): array {
		$apps = array_values( array_unique( array_filter( array_map( 'sanitize_key', array_map( 'strval', (array) ( $p['apps'] ?? array() ) ) ) ) ) );
		$text = function ( $map, int $max ) use ( $apps ): array {
			$out = array();
			foreach ( (array) $map as $id => $v ) {
				$id = sanitize_key( (string) $id );
				if ( in_array( $id, $apps, true ) ) {
					$out[ $id ] = self::plain( $v, $max );
				}
			}
			return $out;
		};
		$can = array();
		foreach ( (array) ( $p['can'] ?? array() ) as $k => $v ) {
			$can[ sanitize_key( (string) $k ) ] = (bool) $v;
		}
		$tiles = (string) ( $p['tiles'] ?? '' );
		$jobs  = (string) ( $p['jobs'] ?? '' );
		$admin = ! empty( $p['admin'] );
		return array(
			'uid'           => (int) ( $p['uid'] ?? 0 ),
			'preview'       => self::plain( $p['preview'] ?? '', 40 ),
			'role'          => sanitize_key( (string) ( $p['role'] ?? '' ) ),
			'role_label'    => self::plain( $p['role_label'] ?? '', 40 ),
			'first_name'    => self::plain( $p['first_name'] ?? '', 40 ),
			'admin'         => $admin,
			'office'        => ! empty( $p['office'] ),
			'kiosk'         => ! empty( $p['kiosk'] ),
			'apps'          => $apps,
			'names'         => $text( $p['names'] ?? array(), 40 ),
			'descs'         => $text( $p['descs'] ?? array(), 110 ),
			'tiles'         => in_array( $tiles, array( 'company', 'sales', 'operator', 'tech', 'shop' ), true ) ? $tiles : '',
			'jobs'          => in_array( $jobs, array( 'all', 'shop', 'crew', 'own' ), true ) ? $jobs : '',
			'crew'          => max( 0, (int) ( $p['crew'] ?? 0 ) ),
			'stub'          => ! $admin && ! empty( $p['stub'] ), // the administrator's tier always prices
			'self_schedule' => ! empty( $p['self_schedule'] ),
			'can'           => $can,
		);
	}

	/**
	 * What the person can do, as the names a step's `needs` and `unless` use. Only what the person's apps make
	 * real: no Jobs app, no job abilities.
	 *
	 * @return array<string,bool>
	 */
	public static function abilities( array $p ): array {
		$p    = self::normalize_profile( $p );
		$has  = function ( string $app ) use ( $p ): bool {
			return in_array( $app, $p['apps'], true );
		};
		$can  = function ( string $k ) use ( $p ): bool {
			return ! empty( $p['can'][ $k ] );
		};
		$jobs = $has( 'jobs' ) ? $p['jobs'] : '';
		$lead = 'crew' === $jobs && $p['crew'] > 0;
		return array(
			'admin'         => $p['admin'],
			'staff'         => ! $p['admin'],
			'office'        => $p['office'],
			'preview'       => '' !== $p['preview'],
			'sample'        => self::sample_loaded(),
			'jobs_all'      => 'all' === $jobs,
			'jobs_shop'     => 'shop' === $jobs,
			'jobs_crew'     => $lead,
			'jobs_own'      => 'own' === $jobs || ( 'crew' === $jobs && ! $lead ),
			'lead'          => $lead,
			'may_lead'      => 'crew' === $jobs && ! $lead && ! $p['admin'],
			'sends_jobs'    => '' !== $jobs && $can( 'handoff_jobs' ),
			'self_schedule' => '' !== $jobs && $p['self_schedule'],
			'seller'        => $has( 'estimate-creator' ) && ! $p['stub'],
			'stub'          => $has( 'estimate-creator' ) && $p['stub'],
			'revenue'       => $can( 'view_company_revenue' ),
			'others'        => $can( 'view_others_data' ),
			'own_pay'       => $can( 'view_own_commission' ),
			'team_projects' => $can( 'view_team_projects' ),
			'vault_upload'  => $can( 'upload_to_knowledge_vault' ),
			'web'           => $can( 'access_web_research' ),
			'tiles_company' => 'company' === $p['tiles'],
			'tiles_sales'   => 'sales' === $p['tiles'],
			'tiles_operator' => 'operator' === $p['tiles'],
			'tiles_tech'    => 'tech' === $p['tiles'],
			'tiles_shop'    => 'shop' === $p['tiles'],
		);
	}

	/** Which tour a person gets: 'sample' for an administrator, 'team' for everyone else. */
	public static function audience( array $p ): string {
		return ! empty( $p['admin'] ) ? 'sample' : 'team';
	}

	/* ------------------------------------------------------------------ when it is offered */

	/** Whether this person is offered a tour (the sample tour or the team tour, by who they are). */
	public static function available_for( int $uid ): bool {
		return self::offered( $uid, self::profile( $uid ) );
	}

	/** Whether the Home Screen loads the tour's script: a tour is offered, or the person can switch the team tours. */
	public static function page_needs( int $uid ): bool {
		$p = self::profile( $uid );
		return self::offered( $uid, $p ) || null !== self::team_controls( $uid, $p );
	}

	private static function offered( int $uid, array $p ): bool {
		$aud = self::audience( $p );
		$ok  = $uid > 0 && ! $p['kiosk'];
		if ( $ok && class_exists( 'ZDZ_Hierarchy' ) && method_exists( 'ZDZ_Hierarchy', 'is_kiosk' ) && ZDZ_Hierarchy::is_kiosk( $uid ) ) {
			$ok = false; // the shared device is not one person
		}
		if ( $ok && class_exists( 'ZDZ_Kiosk_Demo' ) && method_exists( 'ZDZ_Kiosk_Demo', 'is_active' ) && ZDZ_Kiosk_Demo::is_active() ) {
			$ok = false; // Demo Mode shows the app to visitors; the tour is for the people who use it
		}
		if ( $ok && class_exists( 'ZDZ_Privacy_View' ) && method_exists( 'ZDZ_Privacy_View', 'is_on' ) && ZDZ_Privacy_View::is_on( $uid ) ) {
			$ok = false; // a look-only account
		}
		if ( $ok ) {
			// The sample tour runs a test job on the sample company. The team tour needs nothing on the site; a preview
			// shows it even while it is not offered, so an administrator can look before turning it on.
			$ok = 'sample' === $aud ? self::sample_loaded() : ( '' !== $p['preview'] || self::team_on() );
		}
		return (bool) apply_filters( 'zdz_tour_available', $ok, $uid, $aud );
	}

	/* ------------------------------------------------------------------ the steps */

	/** A tour's chapters, in order: id => title. */
	public static function chapters( string $audience = 'sample' ): array {
		if ( 'team' === $audience ) {
			return array(
				'role' => __( 'You and your role', 'zorderz' ),
				'find' => __( 'Find your way', 'zorderz' ),
				'work' => __( 'Your apps', 'zorderz' ),
				'team' => __( 'Your team', 'zorderz' ),
			);
		}
		return array(
			'find' => __( 'Find your way', 'zorderz' ),
			'job'  => __( 'Do a test job', 'zorderz' ),
			'team' => __( 'Your team', 'zorderz' ),
			'own'  => __( 'Make it yours', 'zorderz' ),
		);
	}

	/**
	 * Every step of a tour, before the person's apps and abilities are taken into account. The team tour is written
	 * for a profile (its words name the person's role and apps).
	 *
	 * A step: id (unique), chapter, title, body; optional `do` (what to try, one sentence), `list` (short lines shown
	 * as a list), `target` (a CSS selector the ring goes around), `reach` (a selector clicked when the target is not on
	 * screen yet, such as the tab that shows it), `app` (an app id, or a list, the step needs: left out when the person
	 * does not have one), `needs` / `unless` (abilities() the person must all have / must have none of), `screen`
	 * ('home' for the Home Screen, 'settings', or an app id to have that app open), `show` (what "Show me" does:
	 * {open_app}, {click}, {settings}, {home} or {admin: a wp-admin page, label}), `next_on` ('click': the step moves
	 * on when its target is clicked) and `note` (a smaller line under the text).
	 */
	public static function steps( string $audience = 'sample', array $profile = array() ): array {
		if ( 'team' === $audience ) {
			$p   = self::normalize_profile( $profile );
			$raw = apply_filters( 'zdz_tour_team_steps', self::team_builtin( $p ), $p );
		} else {
			$raw = apply_filters( 'zdz_tour_steps', self::builtin() );
		}
		$out  = array();
		$seen = array();
		foreach ( is_array( $raw ) ? $raw : array() as $s ) {
			$s = self::clean_step( $s, $audience );
			if ( null === $s || isset( $seen[ $s['id'] ] ) ) {
				continue;
			}
			$seen[ $s['id'] ] = true;
			$out[]            = $s;
		}
		return $out;
	}

	/** The steps this person's tour has. */
	public static function steps_for( int $uid ): array {
		return self::steps_for_profile( self::profile( $uid ), $uid );
	}

	/** The steps a profile's tour has: those whose apps it has, whose needs it meets and whose unless it does not. */
	public static function steps_for_profile( array $profile, int $uid = 0 ): array {
		$p   = self::normalize_profile( $profile );
		$ab  = self::abilities( $p );
		$out = array();
		foreach ( self::steps( self::audience( $p ), $p ) as $s ) {
			if ( self::fits( $s, $p, $ab, $uid ) ) {
				$out[] = $s;
			}
		}
		return $out;
	}

	private static function fits( array $s, array $p, array $ab, int $uid ): bool {
		foreach ( $s['apps'] as $app ) {
			if ( ! apply_filters( 'zdz_tour_can_open', in_array( $app, $p['apps'], true ), $uid, $app ) ) {
				return false;
			}
		}
		foreach ( $s['needs'] as $k ) {
			if ( empty( $ab[ $k ] ) ) {
				return false; // an ability nobody knows is one nobody has
			}
		}
		foreach ( $s['unless'] as $k ) {
			if ( ! empty( $ab[ $k ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * One step made safe and complete, or null when it cannot be shown (no id, title or text).
	 *
	 * @param mixed  $s
	 * @param string $audience whose chapters it may name.
	 */
	public static function clean_step( $s, string $audience = 'sample' ): ?array {
		if ( ! is_array( $s ) ) {
			return null;
		}
		$id    = sanitize_key( (string) ( $s['id'] ?? '' ) );
		$title = self::plain( $s['title'] ?? '', self::TITLE_MAX );
		$body  = self::plain( $s['body'] ?? '', self::BODY_MAX );
		if ( '' === $id || '' === $title || '' === $body ) {
			return null;
		}
		$chapters = self::chapters( $audience );
		$chapter  = sanitize_key( (string) ( $s['chapter'] ?? '' ) );
		$screen   = sanitize_key( (string) ( $s['screen'] ?? '' ) );
		$show     = self::clean_show( $s['show'] ?? null );
		$apps     = self::keys( $s['app'] ?? array() );
		if ( '' !== $screen && ! in_array( $screen, array( 'home', 'settings' ), true ) && ! in_array( $screen, $apps, true ) ) {
			$apps[] = $screen; // a step that opens an app needs that app
		}
		return array(
			'id'      => $id,
			'chapter' => isset( $chapters[ $chapter ] ) ? $chapter : (string) array_key_first( $chapters ),
			'title'   => $title,
			'body'    => $body,
			'do'      => self::plain( $s['do'] ?? '', 200 ),
			'note'    => self::plain( $s['note'] ?? '', 200 ),
			'list'    => self::clean_list( $s['list'] ?? array() ),
			'target'  => self::selector( $s['target'] ?? '' ),
			'reach'   => self::selector( $s['reach'] ?? '' ),
			'apps'    => $apps,
			'needs'   => self::keys( $s['needs'] ?? array() ),
			'unless'  => self::keys( $s['unless'] ?? array() ),
			'screen'  => $screen,
			'show'    => $show,
			'next_on' => 'click' === ( $s['next_on'] ?? '' ) ? 'click' : '',
		);
	}

	/** A step as the page gets it: what to show, not who it is for. */
	private static function for_page( array $s ): array {
		unset( $s['needs'], $s['unless'] );
		return $s;
	}

	/** What "Show me" may do. Anything else is dropped. */
	private static function clean_show( $show ): ?array {
		if ( ! is_array( $show ) ) {
			return null;
		}
		if ( ! empty( $show['open_app'] ) ) {
			return array( 'open_app' => sanitize_key( (string) $show['open_app'] ) );
		}
		if ( ! empty( $show['click'] ) ) {
			$sel = self::selector( $show['click'] );
			return '' !== $sel ? array( 'click' => $sel ) : null;
		}
		if ( ! empty( $show['settings'] ) ) {
			return array( 'settings' => true );
		}
		if ( ! empty( $show['home'] ) ) {
			return array( 'home' => true );
		}
		if ( ! empty( $show['admin'] ) ) {
			$path = ltrim( (string) $show['admin'], '/' );
			if ( ! preg_match( '#^[a-z0-9._\-]+\.php(\?[a-z0-9_\-=&.]*)?$#i', $path ) ) {
				return null; // a wp-admin page of this site, never an outside address
			}
			return array(
				'admin' => admin_url( $path ),
				'label' => self::plain( $show['label'] ?? '', 60 ),
			);
		}
		return null;
	}

	/** A step's list: plain lines, none empty, at most LIST_MAX of them. */
	private static function clean_list( $list ): array {
		$out = array();
		foreach ( is_array( $list ) ? $list : array() as $line ) {
			$line = is_scalar( $line ) ? self::plain( $line, self::ITEM_MAX ) : '';
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return array_slice( $out, 0, self::LIST_MAX );
	}

	/** Keys (app ids, ability names): one or a list, each sanitized, none empty, none twice. */
	private static function keys( $v ): array {
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', array_map( 'strval', array_filter( (array) $v, 'is_scalar' ) ) ) ) ) );
	}

	/** Plain text: no tags, one line of spaces, at most $max characters. */
	private static function plain( $s, int $max ): string {
		$s = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $s ) ) );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $s ) > $max ) {
			$s = rtrim( mb_substr( $s, 0, $max - 1 ) ) . '…';
		}
		return $s;
	}

	/** A CSS selector the page can hand to querySelector: printable, no markup, no script. */
	private static function selector( $s ): string {
		$s = trim( (string) $s );
		if ( '' === $s || strlen( $s ) > 300 || preg_match( '/[<>{};`\\\\]|javascript:/i', $s ) ) {
			return '';
		}
		return $s;
	}

	/** "A, B and C", in the site's language. */
	private static function and_list( array $items ): string {
		$items = array_values(
			array_filter(
				array_map( 'strval', $items ),
				function ( $v ) {
					return '' !== $v;
				}
			)
		);
		if ( count( $items ) < 2 ) {
			return (string) ( $items[0] ?? '' );
		}
		$last = array_pop( $items );
		/* translators: 1: names separated by commas, 2: the last name. */
		return sprintf( __( '%1$s and %2$s', 'zorderz' ), implode( __( ', ', 'zorderz' ), $items ), $last );
	}

	/* ------------------------------------------------------------------ each person's place */

	/** The user meta that keeps a person's place in a tour. */
	public static function meta_key( string $audience ): string {
		return 'team' === $audience ? self::TEAM_META : self::META;
	}

	public static function state( int $uid, string $audience = 'sample' ): array {
		$st = $uid > 0 ? get_user_meta( $uid, self::meta_key( $audience ), true ) : array();
		$st = is_array( $st ) ? $st : array();
		return array(
			'step'   => sanitize_key( (string) ( $st['step'] ?? '' ) ),
			'status' => in_array( $st['status'] ?? '', self::STATUSES, true ) ? (string) $st['status'] : 'new',
			'at'     => (int) ( $st['at'] ?? 0 ),
		);
	}

	/**
	 * A place the page sent, checked against the steps that exist; null when it is not one.
	 *
	 * @param mixed    $in
	 * @param string[] $ids
	 */
	public static function clean_state( $in, array $ids ): ?array {
		if ( ! is_array( $in ) ) {
			return null;
		}
		$status = (string) ( $in['status'] ?? '' );
		$step   = sanitize_key( (string) ( $in['step'] ?? '' ) );
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return null;
		}
		if ( '' !== $step && ! in_array( $step, $ids, true ) ) {
			return null;
		}
		return array(
			'step'   => $step,
			'status' => $status,
			'at'     => time(),
		);
	}

	public static function routes(): void {
		$ns = defined( 'ZDZ_REST_NS' ) ? ZDZ_REST_NS : 'zorderz/v1';
		register_rest_route(
			$ns,
			'/tour/state',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'rest_get' ),
					'permission_callback' => array( __CLASS__, 'rest_can' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'rest_save' ),
					'permission_callback' => array( __CLASS__, 'rest_can' ),
				),
			)
		);
		register_rest_route(
			$ns,
			'/tour/team',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'rest_team' ),
					'permission_callback' => array( __CLASS__, 'rest_team_can' ),
				),
			)
		);
	}

	public static function rest_can(): bool {
		return self::available_for( get_current_user_id() );
	}

	public static function rest_get(): array {
		$uid = get_current_user_id();
		return self::state( $uid, self::audience( self::profile( $uid ) ) );
	}

	/** @param WP_REST_Request $req */
	public static function rest_save( $req ) {
		$uid = get_current_user_id();
		$p   = self::profile( $uid );
		if ( '' !== $p['preview'] ) {
			return new WP_Error( 'zdz_tour_preview', __( 'A preview is not saved.', 'zorderz' ), array( 'status' => 400 ) );
		}
		$ids = array_column( self::steps_for_profile( $p, $uid ), 'id' );
		$st  = self::clean_state( $req->get_json_params(), $ids );
		if ( null === $st ) {
			return new WP_Error( 'zdz_tour_state', __( 'That is not a step of the tour.', 'zorderz' ), array( 'status' => 400 ) );
		}
		update_user_meta( $uid, self::meta_key( self::audience( $p ) ), $st );
		return $st;
	}

	/** Turning the team tours on or off: someone who can change the site's settings, as themselves. */
	public static function rest_team_can(): bool {
		$uid = get_current_user_id();
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( class_exists( 'ZDZ_View_As' ) && ZDZ_View_As::is_emulating() ) {
			return false; // while previewing someone else
		}
		return ! ( class_exists( 'ZDZ_Privacy_View' ) && method_exists( 'ZDZ_Privacy_View', 'is_on' ) && ZDZ_Privacy_View::is_on( $uid ) );
	}

	/** @param WP_REST_Request $req */
	public static function rest_team( $req ) {
		$in = $req->get_json_params();
		if ( ! is_array( $in ) || ! array_key_exists( 'on', $in ) || ! is_bool( $in['on'] ) ) {
			return new WP_Error( 'zdz_tour_team', __( 'Say on or off.', 'zorderz' ), array( 'status' => 400 ) );
		}
		update_option( self::TEAM_OPTION, $in['on'] ? '1' : '0', false );
		return array( 'on' => self::team_on() );
	}

	/* ------------------------------------------------------------------ for the page */

	/**
	 * What the Home Screen gets as zdzData.tour: everything the player needs, or {available:false}. For someone who
	 * can change the site's settings, also `team`: whether the team tours are offered, and a preview link per role.
	 */
	public static function for_js( int $uid ): array {
		$p    = self::profile( $uid );
		$aud  = self::audience( $p );
		$out  = array( 'available' => false );
		$team = self::team_controls( $uid, $p );
		if ( self::offered( $uid, $p ) ) {
			$steps = self::steps_for_profile( $p, $uid );
			if ( $steps ) {
				$preview = '' !== $p['preview'];
				$out     = array(
					'available' => true,
					'audience'  => $aud,
					'preview'   => $preview,
					// A preview is never saved: the administrator's own place in their own tour stays as it was.
					'rest'      => $preview ? '' : esc_url_raw( rest_url( ( defined( 'ZDZ_REST_NS' ) ? ZDZ_REST_NS : 'zorderz/v1' ) . '/tour/state' ) ),
					'state'     => $preview ? array( 'step' => '', 'status' => 'new', 'at' => 0 ) : self::state( $uid, $aud ),
					'chapters'  => self::chapters( $aud ),
					'steps'     => array_map( array( __CLASS__, 'for_page' ), $steps ),
					'labels'    => self::labels( $aud, $p ),
					'phrases'   => self::phrases(),
				);
			}
		}
		if ( $team ) {
			$out['team'] = $team;
			if ( empty( $out['labels'] ) ) {
				$out['labels'] = self::labels( $aud, $p );
			}
		}
		return $out;
	}

	/** The team tours' switch and a preview link for each role an administrator can preview; null for anyone else. */
	private static function team_controls( int $uid, array $p ): ?array {
		if ( $uid <= 0 || '' !== $p['preview'] || ! function_exists( 'user_can' ) || ! user_can( $uid, 'manage_options' ) ) {
			return null;
		}
		if ( class_exists( 'ZDZ_Privacy_View' ) && method_exists( 'ZDZ_Privacy_View', 'is_on' ) && ZDZ_Privacy_View::is_on( $uid ) ) {
			return null;
		}
		$roles  = array();
		$labels = class_exists( 'ZDZ_User_Roles' ) ? ZDZ_User_Roles::get_role_labels() : array();
		$all    = class_exists( 'ZDZ_View_As' ) && method_exists( 'ZDZ_View_As', 'roles' ) ? ZDZ_View_As::roles() : array();
		foreach ( $all as $r ) {
			$r = (string) $r;
			if ( ! class_exists( 'ZDZ_User_Roles' ) || ZDZ_User_Roles::is_admin_role( $r ) || ZDZ_User_Roles::SHARED_DEVICE_ROLE === $r ) {
				continue; // an administrator's role gets the sample tour; the shared device gets none
			}
			if ( function_exists( 'get_role' ) && ! get_role( $r ) ) {
				continue;
			}
			$roles[] = array(
				'label' => self::plain( $labels[ $r ] ?? $r, 40 ),
				// The role's defaults: a person previewed earlier (zdz_view_as_user) would otherwise win over the role.
				'url'   => esc_url_raw(
					add_query_arg(
						array(
							'zdz_view_as'      => $r,
							'zdz_view_as_user' => 0,
							'zdz_tour'         => 'start',
						),
						home_url( '/' )
					)
				),
			);
		}
		return array(
			'on'    => self::team_on(),
			'rest'  => esc_url_raw( rest_url( ( defined( 'ZDZ_REST_NS' ) ? ZDZ_REST_NS : 'zorderz/v1' ) . '/tour/team' ) ),
			'roles' => $roles,
		);
	}

	/** Words in the ask bar that offer the tour as the first row ("tour" anywhere always does). */
	public static function phrases(): array {
		$list = apply_filters(
			'zdz_tour_phrases',
			array(
				__( 'guided tour', 'zorderz' ),
				__( 'show me around', 'zorderz' ),
				__( 'getting started', 'zorderz' ),
				__( 'get started', 'zorderz' ),
				__( 'how does this work', 'zorderz' ),
				__( 'walk me through', 'zorderz' ),
				__( 'walkthrough', 'zorderz' ),
			)
		);
		$out = array();
		foreach ( (array) $list as $p ) {
			$p = self::plain( $p, 60 );
			if ( '' !== $p ) {
				$out[] = $p;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Every fixed word the player shows, so a translation reaches it. */
	public static function labels( string $audience = 'sample', array $profile = array() ): array {
		$team    = 'team' === $audience;
		$preview = self::plain( $profile['preview'] ?? '', 40 );
		return array(
			'offer_title'      => __( 'Take the guided tour', 'zorderz' ),
			'offer_body'       => $team
				? __( 'A few minutes on the apps your role uses and what each one lets you do. It only points and explains, so nothing is changed.', 'zorderz' )
				: __( 'About ten minutes with the sample company, TestCo: find your way around, then run one test job from estimate to invoice. Its customers are made up, so nothing you do here reaches a real person.', 'zorderz' ),
			'offer_resume'     => __( 'Pick up the guided tour where you left it.', 'zorderz' ),
			'start'            => __( 'Start the tour', 'zorderz' ),
			'resume'           => __( 'Pick up where I left off', 'zorderz' ),
			'restart'          => __( 'Start over', 'zorderz' ),
			'not_now'          => __( 'Not now', 'zorderz' ),
			'hide'             => __( 'Hide this', 'zorderz' ),
			'next'             => __( 'Next', 'zorderz' ),
			'back'             => __( 'Back', 'zorderz' ),
			'show'             => __( 'Show me', 'zorderz' ),
			'end'              => __( 'End tour', 'zorderz' ),
			'finish'           => __( 'Finish', 'zorderz' ),
			'your_turn'        => __( 'Your turn:', 'zorderz' ),
			/* translators: 1: this step's number, 2: how many steps there are. */
			'step_of'          => __( 'Step %1$d of %2$d', 'zorderz' ),
			'not_on_screen'    => __( 'This part is not on the screen right now. Use Show me, or read on and press Next.', 'zorderz' ),
			'covering'         => __( 'This card covers part of the screen. Hide tucks it into a corner, and so does a click or tap outside it. The tab brings it back.', 'zorderz' ),
			'hide_card'        => __( 'Hide', 'zorderz' ),
			'hide_card_aria'   => __( 'Hide the tour card while you work', 'zorderz' ),
			'show_card'        => __( 'Show the tour', 'zorderz' ),
			'cmd_name'         => __( 'Start the guided tour', 'zorderz' ),
			'settings_row'     => __( 'Guided tour', 'zorderz' ),
			'settings_label'   => __( 'Show me around', 'zorderz' ),
			'settings_hint'    => $team
				? __( 'A click-through of your apps and what your role can do.', 'zorderz' )
				: __( 'A click-through of the app and one test job.', 'zorderz' ),
			/* translators: 1: the step the tour was paused at, 2: how many steps there are. */
			'settings_paused'  => __( 'Paused at step %1$d of %2$d.', 'zorderz' ),
			'settings_preview' => '' !== $preview
				/* translators: %s: the role being previewed, such as Salesperson. */
				? sprintf( __( 'Preview of the %s tour.', 'zorderz' ), $preview )
				: '',
			'ended'            => __( 'Tour paused. Settings → Guided tour picks it up again.', 'zorderz' ),
			'dialog'           => __( 'Guided tour', 'zorderz' ),
			'team_title'       => __( 'Tours for your team', 'zorderz' ),
			'team_on'          => __( 'Everyone else gets a tour of the apps their role uses and what each one lets them do.', 'zorderz' ),
			'team_off'         => __( 'Off: nobody else is offered a tour.', 'zorderz' ),
			'team_switch'      => __( 'Offer tours to your team', 'zorderz' ),
			'team_preview'     => __( 'See the tour a role gets:', 'zorderz' ),
			'team_failed'      => __( 'That did not save. Try again.', 'zorderz' ),
		);
	}

	/* ------------------------------------------------------------------ the sample tour */

	/**
	 * The sample tour Zorderz ships: one idea per card, plain words. The test job uses only what works with no keys:
	 * an estimate priced from the catalog and kept on the site, a job assigned to yourself (no message goes out for
	 * your own action), a time on the schedule, and its Project. Receipts, payments and the assistant are described,
	 * with where their keys go.
	 */
	private static function builtin(): array {
		return array(
			// ── Find your way
			array(
				'id'      => 'welcome',
				'chapter' => 'find',
				'screen'  => 'home',
				'title'   => __( 'Welcome to Zorderz', 'zorderz' ),
				'body'    => __( 'This tour uses TestCo, a made-up company loaded as sample data, so you can try things safely. First you\'ll find your way around. Then you\'ll run one test job from estimate to invoice.', 'zorderz' ),
				'note'    => __( 'End tour stops it at any point, and Settings → Guided tour picks it up again.', 'zorderz' ),
			),
			array(
				'id'      => 'apps',
				'chapter' => 'find',
				'screen'  => 'home',
				'target'  => '#app-grid-container',
				'title'   => __( 'Your apps', 'zorderz' ),
				'body'    => __( 'Each tile opens one app. Its colour shows the kind of work: orange for sales, green for field work, light blue for the team, dark blue for the office, and pink for tools.', 'zorderz' ),
				'note'    => __( 'The top row changes with the time of day: work apps in working hours, the rest after hours.', 'zorderz' ),
			),
			array(
				'id'      => 'ask',
				'chapter' => 'find',
				'screen'  => 'home',
				'target'  => '#dash-ask',
				'title'   => __( 'Search or ask', 'zorderz' ),
				'body'    => __( 'Type an app\'s name here to open it, or a few words about what you want to do. Once the Ai assistant is set up, questions about your business work here too.', 'zorderz' ),
			),
			array(
				'id'      => 'today',
				'chapter' => 'find',
				'screen'  => 'home',
				'target'  => '#quick-stats',
				'title'   => __( 'Today\'s figures', 'zorderz' ),
				'body'    => __( 'Your numbers at a glance. Tap one to see what it counts and where it comes from. Money comes from your invoicing service, so those figures read zero until one is connected.', 'zorderz' ),
			),
			array(
				'id'      => 'nav',
				'chapter' => 'find',
				'screen'  => 'home',
				'target'  => 'nav.bnav',
				'title'   => __( 'Getting around', 'zorderz' ),
				'body'    => __( 'This bar switches between your apps, your jobs, the team\'s messages, the Chat assistant and your notices. It tucks away while an app is open, and Back brings it back.', 'zorderz' ),
			),
			array(
				'id'      => 'settings',
				'chapter' => 'find',
				'screen'  => 'home',
				'target'  => '#bnav-logo',
				'show'    => array( 'settings' => true ),
				'title'   => __( 'Settings', 'zorderz' ),
				'body'    => __( 'Your logo opens Settings: light, dark or sunlight mode, larger text and phone notifications. This tour is there too, under Guided tour.', 'zorderz' ),
			),

			// ── Do a test job
			array(
				'id'      => 'job_intro',
				'chapter' => 'job',
				'screen'  => 'home',
				'title'   => __( 'Now, a test job', 'zorderz' ),
				'body'    => __( 'Every job takes the same path: an estimate, the work, then the invoice. You\'ll write an estimate, turn it into a job for yourself, give it a time and finish it.', 'zorderz' ),
			),
			array(
				'id'      => 'estimate_new',
				'chapter' => 'job',
				'screen'  => 'estimate-creator',
				'target'  => '#zest-widget .zest-w-tab[data-tab="new"]',
				'show'    => array( 'click' => '#zest-widget .zest-w-tab[data-tab="new"]' ),
				'next_on' => 'click',
				'title'   => __( 'Estimates', 'zorderz' ),
				'body'    => __( 'Estimates are written and followed up here. Open lists the ones waiting on a customer, and History has every estimate TestCo has made.', 'zorderz' ),
				'do'      => __( 'Click New estimate.', 'zorderz' ),
			),
			array(
				'id'      => 'estimate_type',
				'chapter' => 'job',
				'screen'  => 'estimate-creator',
				'target'  => '#zest-widget .zest-w-panel[data-panel="new"]',
				'reach'   => '#zest-widget .zest-w-tab[data-tab="new"]',
				'title'   => __( 'Describe the work', 'zorderz' ),
				'body'    => __( 'Write one item per line, the quantity first, and each line is priced from your catalog. TestCo sells school testing supplies. With the Ai assistant set up, this box also reads whole sentences and photos of handwritten notes.', 'zorderz' ),
				'do'      => __( 'Type 10 pencil, then 2 calculator on the next line, and click Parse estimate.', 'zorderz' ),
			),
			array(
				'id'      => 'estimate_save',
				'chapter' => 'job',
				'screen'  => 'estimate-creator',
				'target'  => '#zest-create',
				'title'   => __( 'Save the estimate', 'zorderz' ),
				'body'    => __( 'Check the lines and the total. Once an invoicing service is connected, this button sends the estimate there. Until then the estimate is kept here, in Zorderz.', 'zorderz' ),
				'do'      => __( 'Click Send to billing.', 'zorderz' ),
				'note'    => __( 'Choosing a customer needs the invoicing service too, so this practice estimate has none.', 'zorderz' ),
			),
			array(
				'id'      => 'estimate_to_job',
				'chapter' => 'job',
				'screen'  => 'estimate-creator',
				'app'     => 'jobs',
				'target'  => '#zest-widget .zjobx-send',
				'reach'   => '#zest-widget .zest-w-tab[data-tab="open"]',
				'title'   => __( 'Turn it into a job', 'zorderz' ),
				'body'    => __( 'Send as job turns estimate lines into work for your team. You choose the lines, who does them and what kind of work each one is.', 'zorderz' ),
				'do'      => __( 'On your new estimate, at the top of Open, click Send as job.', 'zorderz' ),
				'next_on' => 'click',
			),
			array(
				'id'      => 'job_pick',
				'chapter' => 'job',
				'screen'  => 'estimate-creator',
				'app'     => 'jobs',
				'target'  => '#zjobx-modal .zjobx-dialog',
				'title'   => __( 'Pick the lines and the person', 'zorderz' ),
				'body'    => __( 'Each ticked line becomes one job, for the person you choose. They hear about it in Messages once the job has a time.', 'zorderz' ),
				'do'      => __( 'Tick a line, choose someone from TestCo\'s team, and click Send jobs.', 'zorderz' ),
				'note'    => __( 'TestCo\'s people are made up, so their messages reach no one.', 'zorderz' ),
			),
			array(
				'id'      => 'job_list',
				'chapter' => 'job',
				'screen'  => 'jobs',
				'target'  => '#zjob-widget .zjob-chip[data-bucket="present"]',
				'title'   => __( 'Your job', 'zorderz' ),
				'body'    => __( 'Jobs lists the work by when it happens: next, present, future and past. A new job sits under Present until it has a time.', 'zorderz' ),
				'do'      => __( 'Click Present to find your new job.', 'zorderz' ),
				'next_on' => 'click',
			),
			array(
				'id'      => 'job_time',
				'chapter' => 'job',
				'screen'  => 'jobs',
				'target'  => '#zjob-list [data-act="sched-edit"]',
				'reach'   => '#zjob-widget .zjob-chip[data-bucket="present"]',
				'title'   => __( 'Give it a time', 'zorderz' ),
				'body'    => __( 'Set time puts the job on the team calendar and tells the person doing it. The job then moves to Future: it\'s their move.', 'zorderz' ),
				'do'      => __( 'Click Set time, choose a time, and click Schedule.', 'zorderz' ),
			),
			array(
				'id'      => 'job_work',
				'chapter' => 'job',
				'screen'  => 'jobs',
				'target'  => '#zjob-list .zjob-card',
				'reach'   => '#zjob-widget .zjob-chip[data-bucket="future"]',
				'title'   => __( 'Do the work', 'zorderz' ),
				'body'    => __( 'Present is your move, Future is waiting on someone else, and Past is finished. In the field, the worker starts the job and finishes their part with a photo, and then you close it out.', 'zorderz' ),
				'do'      => __( 'Under Future, press Mark done on your job.', 'zorderz' ),
			),
			array(
				'id'      => 'schedule',
				'chapter' => 'job',
				'screen'  => 'scheduler',
				'target'  => '#zsch-widget',
				'title'   => __( 'On the schedule', 'zorderz' ),
				'body'    => __( 'The time you set is now an appointment here, beside the rest of the team\'s. Schedule also shows who is free. With Microsoft 365 connected, it keeps Outlook calendars in step.', 'zorderz' ),
			),
			array(
				'id'      => 'project',
				'chapter' => 'job',
				'screen'  => 'projects',
				'target'  => '#zjob-projects',
				'title'   => __( 'One place for the whole job', 'zorderz' ),
				'body'    => __( 'Every estimate becomes a Project, which gathers its jobs, photos and notes. Yours is here with the job you just did.', 'zorderz' ),
				'do'      => __( 'Open the newest Project.', 'zorderz' ),
			),
			array(
				'id'      => 'receipt',
				'chapter' => 'job',
				'screen'  => 'receipts',
				'target'  => '#zrcpt-widget',
				'title'   => __( 'The customer\'s receipt', 'zorderz' ),
				'body'    => __( 'When the work is done, Receipts writes up what was done for the customer to read and sign. It looks the job up in your invoicing service and writes with the Ai assistant, so it starts working once both are set up.', 'zorderz' ),
			),
			array(
				'id'      => 'invoice',
				'needs'   => array( 'office' ),
				'chapter' => 'job',
				'screen'  => 'invoice',
				'target'  => '#dash-widget-zone .zic-widget-btn',
				'show'    => array(
					'admin' => 'admin.php?page=zic_new_invoice',
					'label' => __( 'Open New Invoice', 'zorderz' ),
				),
				'title'   => __( 'Send the invoice', 'zorderz' ),
				'body'    => __( 'Invoices are made on the office screens. New Invoice makes an invoice with a link the customer can pay from. Taking card payments needs a Stripe account.', 'zorderz' ),
				'note'    => __( 'It opens in a new tab, so the tour stays here.', 'zorderz' ),
			),

			// ── Your team
			array(
				'id'      => 'messages',
				'chapter' => 'team',
				'screen'  => 'internal-messaging',
				'target'  => '#zim-w-sidebar',
				'title'   => __( 'Messages', 'zorderz' ),
				'body'    => __( 'Talk with your team in channels, like #announcements, or one to one. People get new messages as a phone notification, or by e-mail.', 'zorderz' ),
			),
			array(
				'id'      => 'chat',
				'chapter' => 'team',
				'screen'  => 'home',
				'target'  => 'nav.bnav .ni-chat',
				'title'   => __( 'Chat', 'zorderz' ),
				'body'    => __( 'Chat is the Ai assistant: ask about your estimates, jobs and figures in plain words. It needs an Ai key first, which goes on Zorderz → Credentials, with models and a monthly budget under Zorderz → Ai usage.', 'zorderz' ),
			),
			array(
				'id'      => 'notices',
				'chapter' => 'team',
				'screen'  => 'home',
				'target'  => 'nav.bnav [data-zdz-bell="nav"]',
				'title'   => __( 'Notices', 'zorderz' ),
				'body'    => __( 'What needs you lands here: an estimate a customer accepted, a job ready to close out, a new message.', 'zorderz' ),
			),

			// ── Make it yours
			array(
				'id'      => 'profile',
				'needs'   => array( 'office' ),
				'chapter' => 'own',
				'screen'  => 'home',
				'show'    => array(
					'admin' => 'admin.php?page=zdz-business-profile',
					'label' => __( 'Open Business Profile', 'zorderz' ),
				),
				'title'   => __( 'Make it your business', 'zorderz' ),
				'body'    => __( 'Your name, logo, colours, address and country live in the Business Profile. Set the country first: money, dates, the clock and measurements follow it. Your products and prices go in the catalog, under Zorderz → Item Engine.', 'zorderz' ),
			),
			array(
				'id'      => 'credentials',
				'needs'   => array( 'office' ),
				'chapter' => 'own',
				'screen'  => 'home',
				'show'    => array(
					'admin' => 'admin.php?page=zdz-core-credentials',
					'label' => __( 'Open Credentials', 'zorderz' ),
				),
				'title'   => __( 'Connect your tools', 'zorderz' ),
				'body'    => __( 'Keys for your invoicing service, your customer relationship manager and the Ai assistant all go on one page, Credentials. Each app picks up its key from there, and a saved key is never shown again.', 'zorderz' ),
			),
			array(
				'id'      => 'office_elsewhere',
				'chapter' => 'own',
				'screen'  => 'home',
				'unless'  => array( 'office' ),
				'title'   => __( 'The settings screens', 'zorderz' ),
				'body'    => __( 'The Business Profile, the keys for your tools and new invoices are kept on the site\'s settings screens, which this account cannot open. Ask whoever runs the site to set them up.', 'zorderz' ),
			),
			array(
				'id'      => 'team_tours',
				'chapter' => 'own',
				'screen'  => 'home',
				'target'  => '#bnav-logo',
				'show'    => array( 'settings' => true ),
				'unless'  => array( 'preview' ),
				'title'   => __( 'Your team\'s tours', 'zorderz' ),
				'body'    => __( 'Everyone else on your team gets a tour of their own: the apps their role uses and what each one lets them do. Settings → Guided tour shows the tour each role gets, and can stop offering them.', 'zorderz' ),
			),
			array(
				'id'      => 'done',
				'chapter' => 'own',
				'screen'  => 'home',
				'title'   => __( 'That\'s the tour', 'zorderz' ),
				'body'    => __( 'TestCo stays on this site as practice data. When you\'re ready for real work, set up a fresh site with your own Business Profile, or bring a business over from another Zorderz site with Tools → Zorderz Data.', 'zorderz' ),
				'note'    => __( 'Settings → Guided tour runs it again any time.', 'zorderz' ),
			),
		);
	}


	/* ------------------------------------------------------------------ the team tour */

	/**
	 * The team tour, written for one person: their role, their apps, and what each app lets them do. Every step only
	 * points and explains, and a step's "Your turn" never asks for anything that saves.
	 */
	private static function team_builtin( array $p ): array {
		$ab    = self::abilities( $p );
		$has   = function ( string $app ) use ( $p ): bool {
			return in_array( $app, $p['apps'], true );
		};
		$named = function ( string $app, string $fallback ) use ( $p ): string {
			return '' !== ( $p['names'][ $app ] ?? '' ) ? $p['names'][ $app ] : $fallback;
		};
		$role  = $p['role_label'];

		$steps = array();

		// ── You and your role
		$steps[] = array(
			'id'      => 'team_welcome',
			'chapter' => 'role',
			'screen'  => 'home',
			'title'   => '' !== $p['first_name']
				/* translators: %s: the person's first name. */
				? sprintf( __( 'Welcome, %s', 'zorderz' ), $p['first_name'] )
				: __( 'Welcome to Zorderz', 'zorderz' ),
			'body'    => '' !== $role
				/* translators: %s: the person's role, such as Salesperson. */
				? sprintf( __( 'Your role here is %s. This tour shows you the apps that come with it, what each one lets you do, and who to ask for the rest. It only points and explains, so nothing is changed.', 'zorderz' ), $role )
				: __( 'This tour shows you your apps, what each one lets you do, and who to ask for the rest. It only points and explains, so nothing is changed.', 'zorderz' ),
			'note'    => '' !== $p['preview']
				/* translators: %s: the role being previewed, such as Salesperson. */
				? sprintf( __( 'Preview of the %s tour. The lists behind it show what your own account can see. Exit Preview, at the top of the page, ends the preview.', 'zorderz' ), $p['preview'] )
				: __( 'End tour stops it at any point, and Settings → Guided tour picks it up again.', 'zorderz' ),
		);
		$steps[] = array(
			'id'      => 'team_role',
			'chapter' => 'role',
			'screen'  => 'home',
			'title'   => __( 'What your role can do', 'zorderz' ),
			'body'    => __( 'Your role decides your apps and what they let you do. An administrator sets it, and can change it.', 'zorderz' ),
			'list'    => self::role_list( $p, $ab ),
		);

		// ── Find your way
		$steps[] = array(
			'id'      => 'team_apps',
			'chapter' => 'find',
			'screen'  => 'home',
			'target'  => '#app-grid-container',
			'title'   => __( 'Your apps', 'zorderz' ),
			'body'    => __( 'Each tile opens one of your apps. Its colour shows the kind of work: orange for sales, green for field work, light blue for the team, dark blue for the office, and pink for tools.', 'zorderz' ),
			'note'    => __( 'The top row changes with the time of day: work apps in working hours, the rest after hours.', 'zorderz' ),
		);
		$steps[] = array(
			'id'      => 'team_ask',
			'chapter' => 'find',
			'screen'  => 'home',
			'target'  => '#dash-ask',
			'title'   => __( 'Search or ask', 'zorderz' ),
			'body'    => __( 'Type an app\'s name here to open it, or a few words about what you want to do.', 'zorderz' ),
		);
		$figures = array(
			'sales'    => __( 'Your own numbers at a glance: the estimates and invoices you wrote, your leads and your installed jobs.', 'zorderz' ),
			'operator' => __( 'Your calls at a glance, from Surveys: who to call, who you reached, call notes to read, and reviews.', 'zorderz' ),
			'tech'     => __( 'Your hours come first, then three of your jobs, appointments, photos and messages, in the order you use them.', 'zorderz' ),
			'shop'     => __( 'What is waiting in Prep comes first, then how many of the main items have sold.', 'zorderz' ),
		);
		foreach ( $figures as $set => $words ) {
			$steps[] = array(
				'id'      => 'team_figures_' . $set,
				'chapter' => 'find',
				'screen'  => 'home',
				'target'  => '#quick-stats',
				'needs'   => array( 'tiles_' . $set ),
				'title'   => __( 'Your figures', 'zorderz' ),
				'body'    => $words . ' ' . __( 'Tap one to see what it counts and where it comes from.', 'zorderz' ),
			);
		}
		// The bar's own words: each app that has a place on it adds its label there.
		$bar = array( __( 'Apps', 'zorderz' ) );
		foreach ( array(
			'jobs'               => __( 'Jobs', 'zorderz' ),
			'internal-messaging' => __( 'Team (your messages)', 'zorderz' ),
			'sales-analytics'    => __( 'Chat', 'zorderz' ),
		) as $app => $word ) {
			if ( $has( $app ) ) {
				$bar[] = $word;
			}
		}
		$bar[]   = __( 'Notices', 'zorderz' );
		$steps[] = array(
			'id'      => 'team_nav',
			'chapter' => 'find',
			'screen'  => 'home',
			'target'  => 'nav.bnav',
			'title'   => __( 'Getting around', 'zorderz' ),
			/* translators: %s: the places on the bottom bar, such as "Apps, Jobs, Chat and Notices". */
			'body'    => sprintf( __( 'The bar along the bottom holds %s. It tucks away while an app is open, and Back brings it back.', 'zorderz' ), self::and_list( $bar ) ),
		);
		$steps[] = array(
			'id'      => 'team_settings',
			'chapter' => 'find',
			'screen'  => 'home',
			'target'  => '#bnav-logo',
			'show'    => array( 'settings' => true ),
			'title'   => __( 'Settings', 'zorderz' ),
			'body'    => __( 'Your logo opens Settings: light, dark or sunlight mode, larger text and phone notifications. This tour is there too, under Guided tour.', 'zorderz' ),
		);

		// ── Your apps
		$steps[] = array(
			'id'      => 'team_estimates',
			'chapter' => 'work',
			'screen'  => 'estimate-creator',
			'target'  => '#zest-widget .zest-w-tabs',
			'needs'   => array( 'seller' ),
			'title'   => $named( 'estimate-creator', __( 'Estimates', 'zorderz' ) ),
			'body'    => __( 'Write an estimate under New estimate: one item per line, the quantity first, and each line is priced from the catalog. Open lists yours that are waiting on a customer, and History has every estimate you have written.', 'zorderz' ),
			'do'      => __( 'Click History to see your estimates.', 'zorderz' ),
			'note'    => __( 'You see your own estimates. Other people\'s stay with them.', 'zorderz' ),
		);
		$steps[] = array(
			'id'      => 'team_stubs',
			'chapter' => 'work',
			'screen'  => 'estimate-creator',
			'target'  => '#zest-widget .zest-w-tabs',
			'needs'   => array( 'stub' ),
			'title'   => __( 'Estimate stubs', 'zorderz' ),
			'body'    => __( 'Your estimates are stubs: who the customer is and the work they want, with every price left at zero. A salesperson then prices each one. Open lists your stubs, and History has every one you have written.', 'zorderz' ),
			'do'      => __( 'Click History to see your stubs.', 'zorderz' ),
		);
		$steps[] = array(
			'id'      => 'team_send_work',
			'chapter' => 'work',
			'app'     => array( 'jobs' ),
			'screen'  => 'estimate-creator',
			'target'  => '#zest-widget .zest-w-tab[data-tab="open"]',
			'needs'   => array( 'sends_jobs' ),
			'title'   => __( 'Send work as jobs', 'zorderz' ),
			'body'    => __( 'On an estimate under Open, Send as job turns its lines into jobs for your teammates. You choose the lines, who does them and what kind of work each one is.', 'zorderz' ),
			'note'    => __( 'The jobs you send stay in your Jobs list, so you can follow them.', 'zorderz' ),
		);
		$jobs_title = $named( 'jobs', __( 'Jobs', 'zorderz' ) );
		$buckets    = __( 'My next lists the steps that are yours to do now. Present is your move, Future is waiting on someone else, and Past is finished.', 'zorderz' );
		foreach ( array(
			'jobs_own'  => __( 'Jobs shows the jobs you are doing and the ones you sent.', 'zorderz' ),
			'jobs_crew' => __( 'Jobs shows your own jobs and your crew\'s.', 'zorderz' ),
			'jobs_shop' => __( 'Jobs shows every job, so the shop can plan its work.', 'zorderz' ),
		) as $tier => $words ) {
			$steps[] = array(
				'id'      => 'team_' . $tier,
				'chapter' => 'work',
				'screen'  => 'jobs',
				'target'  => '#zjob-widget .zjob-w-filters',
				'needs'   => array( $tier ),
				'title'   => $jobs_title,
				'body'    => $words . ' ' . $buckets,
				'do'      => __( 'Click Present to see what is yours to do now.', 'zorderz' ),
			);
		}
		$steps[] = array(
			'id'      => 'team_jobs_given',
			'chapter' => 'work',
			'screen'  => 'jobs',
			'target'  => '#zjob-list',
			'title'   => __( 'Jobs given to you', 'zorderz' ),
			'body'    => __( 'On a job of yours, On my way tells the team you are heading there, and Running late warns them. When your part is done, Mark my part complete asks for a photo, and the person who sent the job closes it out.', 'zorderz' ),
			'note'    => $ab['self_schedule']
				? __( 'You can set its time yourself with Set time.', 'zorderz' )
				: __( 'Its time is set by whoever sent it. Once it has one, the job moves to Present.', 'zorderz' ),
		);
		$steps[] = array(
			'id'      => 'team_jobs_sent',
			'chapter' => 'work',
			'screen'  => 'jobs',
			'target'  => '#zjob-list',
			'needs'   => array( 'sends_jobs' ),
			'title'   => __( 'Jobs you send', 'zorderz' ),
			'body'    => __( 'A job you send needs a time: Set time puts it on the team calendar and tells the person doing it. When they finish their part, you close it out.', 'zorderz' ),
		);
		$steps[] = array(
			'id'      => 'team_crew',
			'chapter' => 'work',
			'screen'  => 'jobs',
			'target'  => '#zjob-list',
			'needs'   => array( 'lead' ),
			'title'   => __( 'Your crew', 'zorderz' ),
			'body'    => __( 'As a crew lead you see your crew\'s jobs too. You can set their times, and close out their work when they finish.', 'zorderz' ),
		);
		$steps[] = array(
			'id'      => 'team_schedule',
			'chapter' => 'work',
			'screen'  => 'scheduler',
			'target'  => '#zsch-widget',
			'title'   => $named( 'scheduler', __( 'Schedule', 'zorderz' ) ),
			'body'    => __( 'Schedule is the team calendar: the shared appointments, your own, and any you have been added to. You can change the ones you made, and an administrator can change any of them.', 'zorderz' ),
		);
		$steps[] = array(
			'id'      => 'team_projects',
			'chapter' => 'work',
			'screen'  => 'projects',
			'target'  => '#zjob-projects',
			'title'   => $named( 'projects', __( 'Projects', 'zorderz' ) ),
			'body'    => __( 'Every estimate becomes a Project, which gathers its jobs, photos and notes.', 'zorderz' ) . ' '
				. ( $ab['team_projects']
					? __( 'You can read every Project\'s work, never its amounts, and you act on the ones you are part of.', 'zorderz' )
					: __( 'You see the Projects you are part of.', 'zorderz' ) ),
			'note'    => $ab['own_pay'] && ! $ab['revenue'] ? __( 'Amounts show only on your own work.', 'zorderz' ) : '',
		);
		$steps[] = array(
			'id'      => 'team_leads',
			'chapter' => 'work',
			'screen'  => 'leads',
			'target'  => '#zl-widget-wrap',
			'title'   => $named( 'leads', __( 'Leads', 'zorderz' ) ),
			'body'    => __( 'Each week Leads finds people likely to buy, and gives each one to the salesperson who covers their area. The ones given to you are here.', 'zorderz' ),
		);
		$steps[] = array(
			'id'      => 'team_commission',
			'chapter' => 'work',
			'screen'  => 'commission-calculator',
			'needs'   => array( 'own_pay' ),
			'title'   => $named( 'commission-calculator', __( 'Commission', 'zorderz' ) ),
			'body'    => __( 'Commission works out pay from the invoices. You see your own: what you sold, and what you earned on it.', 'zorderz' ),
		);
		$steps[] = array(
			'id'      => 'team_hours',
			'chapter' => 'work',
			'screen'  => 'hours',
			'target'  => '#zhr-app',
			'title'   => $named( 'hours', __( 'Hours', 'zorderz' ) ),
			'body'    => __( 'Clock in and out here, or add hours by voice, text or a photo. Your week and month add up as you go.', 'zorderz' ),
		);
		$more = array();
		foreach ( $p['apps'] as $app ) {
			if ( in_array( $app, self::TEAM_APPS, true ) && ( 'commission-calculator' !== $app || $ab['own_pay'] ) ) {
				continue; // it has a step of its own
			}
			$name = $named( $app, '' );
			if ( '' === $name ) {
				continue;
			}
			$desc   = (string) ( $p['descs'][ $app ] ?? '' );
			$more[] = '' !== $desc
				/* translators: 1: an app's name, 2: what the app is for. */
				? sprintf( __( '%1$s: %2$s', 'zorderz' ), $name, $desc )
				: $name;
		}
		if ( $more ) {
			$steps[] = array(
				'id'      => 'team_more',
				'chapter' => 'work',
				'screen'  => 'home',
				'target'  => '#app-grid-container',
				'title'   => __( 'Your other apps', 'zorderz' ),
				'body'    => count( $more ) > self::LIST_MAX
					? __( 'Each opens from its tile. Here is what the first of them are for.', 'zorderz' )
					: __( 'Each opens from its tile. Here is what they are for.', 'zorderz' ),
				'list'    => $more,
			);
		}

		// ── Your team
		$steps[] = array(
			'id'      => 'team_messages',
			'chapter' => 'team',
			'screen'  => 'internal-messaging',
			'target'  => '#zim-w-sidebar',
			'title'   => $named( 'internal-messaging', __( 'Messages', 'zorderz' ) ),
			'body'    => __( 'Talk with your team in channels, or one to one. Administrators post news for everyone in #announcements. New messages reach you as a phone notification, or by e-mail.', 'zorderz' ),
		);
		$chat = __( 'Chat is the Ai assistant: ask about your work in plain words.', 'zorderz' ) . ' '
			. ( $ab['others']
				? __( 'It can answer about the whole team\'s work.', 'zorderz' )
				: __( 'It answers about your own work, not other people\'s.', 'zorderz' ) );
		if ( $ab['web'] ) {
			$chat .= ' ' . __( 'It can look things up on the web too.', 'zorderz' );
		}
		$steps[] = array(
			'id'      => 'team_chat',
			'chapter' => 'team',
			'app'     => array( 'sales-analytics' ),
			'screen'  => 'home',
			'target'  => 'nav.bnav .ni-chat',
			'title'   => $named( 'sales-analytics', __( 'Chat', 'zorderz' ) ),
			'body'    => $chat,
			'note'    => __( 'It answers once your business has the Ai assistant set up.', 'zorderz' ),
		);
		$steps[] = array(
			'id'      => 'team_notices',
			'chapter' => 'team',
			'screen'  => 'home',
			'target'  => 'nav.bnav [data-zdz-bell="nav"]',
			'title'   => __( 'Notices', 'zorderz' ),
			'body'    => __( 'What needs you lands here, such as a job that is ready for you or a new message.', 'zorderz' ),
		);
		$steps[] = array(
			'id'      => 'team_done',
			'chapter' => 'team',
			'screen'  => 'home',
			'title'   => __( 'That\'s the tour', 'zorderz' ),
			'body'    => __( 'When something needs changing, like your apps, your role or a price, ask an administrator: the business settings are theirs. Settings → Guided tour runs this tour again any time, and it changes when your role does.', 'zorderz' ),
		);
		return $steps;
	}

	/**
	 * "What your role can do", as short lines: the person's apps, then what the rules of those apps let them do and
	 * not do. The last line always says who looks after the rest.
	 */
	private static function role_list( array $p, array $ab ): array {
		$has  = function ( string $app ) use ( $p ): bool {
			return in_array( $app, $p['apps'], true );
		};
		$b    = array();
		$apps = array();
		foreach ( $p['apps'] as $app ) {
			if ( '' !== ( $p['names'][ $app ] ?? '' ) ) {
				$apps[] = $p['names'][ $app ];
			}
		}
		if ( $apps ) {
			$shown = array_slice( $apps, 0, 6 );
			if ( count( $apps ) > count( $shown ) ) {
				/* translators: %d: how many more apps the person has. */
				$shown[] = sprintf( __( '%d more', 'zorderz' ), count( $apps ) - count( $shown ) );
			}
			/* translators: %s: the person's apps, such as "Estimates, Jobs and Messages". */
			$b[] = sprintf( __( 'Your apps: %s.', 'zorderz' ), self::and_list( $shown ) );
		} else {
			$b[] = __( 'No apps yet: an administrator gives you yours.', 'zorderz' );
		}
		if ( $ab['seller'] ) {
			$b[] = __( 'Write estimates priced from the catalog. You see your own.', 'zorderz' );
		} elseif ( $ab['stub'] ) {
			$b[] = __( 'Write estimate stubs without prices, for a salesperson to price.', 'zorderz' );
		}
		if ( $ab['jobs_crew'] ) {
			$b[] = __( 'See your own jobs and your crew\'s, and set their times.', 'zorderz' );
		} elseif ( $ab['jobs_shop'] ) {
			$b[] = __( 'See every job, so the shop can plan its work.', 'zorderz' );
		} elseif ( $ab['jobs_own'] ) {
			$b[] = __( 'See the jobs you are doing and the ones you sent.', 'zorderz' );
		}
		if ( $ab['sends_jobs'] && $has( 'estimate-creator' ) ) {
			$b[] = __( 'Send estimate lines to teammates as jobs, and set their times.', 'zorderz' );
		}
		if ( $ab['may_lead'] ) {
			$b[] = __( 'Lead a crew: once an administrator gives you one, you see its jobs too.', 'zorderz' );
		}
		$b[] = $ab['revenue']
			? __( 'See the company\'s revenue.', 'zorderz' )
			: __( 'The company\'s revenue is not shown to you.', 'zorderz' );
		if ( $ab['own_pay'] && $has( 'commission-calculator' ) ) {
			$b[] = __( 'See your own commission.', 'zorderz' );
		}
		if ( $has( 'sales-analytics' ) ) {
			$b[] = ( $ab['others']
				? __( 'Ask Chat about the whole team\'s work.', 'zorderz' )
				: __( 'Ask Chat about your own work.', 'zorderz' ) )
				. ( $ab['web'] ? ' ' . __( 'It can search the web too.', 'zorderz' ) : '' );
		}
		if ( $has( 'knowledge-vault' ) ) {
			$b[] = $ab['vault_upload']
				? __( 'Read company documents in Knowledge, and add your own.', 'zorderz' )
				: __( 'Read company documents in Knowledge.', 'zorderz' );
		}
		$b   = array_slice( $b, 0, self::LIST_MAX - 1 );
		$b[] = __( 'Business settings, people, apps and prices are looked after by an administrator.', 'zorderz' );
		return $b;
	}
}

ZDZ_Tour::init();
