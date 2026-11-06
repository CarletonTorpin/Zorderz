<?php
/**
 * ZDZ_Person_Color — one colour and one shape per person, the same on every screen.
 *
 * Initials are stored per person (`zdz_user_initials`), but nothing gave a person a
 * colour, so the same person could be blue in one chart and pink in the next. Every
 * person with initials now gets a tone from one quiet palette (two hues and a gray,
 * three steps of lightness each; vermilion is never handed out — it means "needs
 * you", see ZDZ_App_Groups). An admin can choose a person's tone (user meta
 * `zdz_user_color`, Zorderz → User Management); everyone else gets the next free tone
 * in user-id order, so a new hire never changes an existing person's tone. Automatic
 * tones can shift when initials are given to an older account, when an account is
 * removed, or when an admin chooses a tone someone had automatically — an admin who
 * wants a tone fixed chooses it. Nothing is written until an admin chooses.
 *
 * The SHAPE comes from the person's highest role, so it carries meaning everywhere:
 * the more sides, the more privileges.
 *   triangle — sales, field and shop roles · square — operator · pentagon — admin ·
 *   hexagon — owner · circle — WordPress administrator · no shape, plain gray — a
 *   shared device.
 *
 * The palette can be replaced with the `zdz_person_tones` filter (tone => [ink, edge,
 * name]); every ink must keep 4.5:1 on its tone. Read by zdzData.people (initials,
 * tone and shape only — no names, no e-mails) for every app's badges.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Person_Color {

	const META          = 'zdz_user_color';
	const META_INITIALS = 'zdz_user_initials';

	/**
	 * Tone => [ ink (the initials on it), edge (outline: the hue's middle step), name ].
	 * Order = the order tones are handed out: the two hues alternate, middle steps first,
	 * gray last. Every ink reaches 4.5:1 on its tone; the edge keeps a light tone visible
	 * on a white page and a dark tone visible on a dark one.
	 */
	const TONES = array(
		'#3569B5' => array( '#FFFFFF', '#3569B5', 'Blue' ),
		'#227A8C' => array( '#FFFFFF', '#227A8C', 'Teal' ),
		'#1B3F73' => array( '#FFFFFF', '#3569B5', 'Dark blue' ),
		'#13505D' => array( '#FFFFFF', '#227A8C', 'Dark teal' ),
		'#D3E3F6' => array( '#0E2F57', '#3569B5', 'Light blue' ),
		'#CDE9EF' => array( '#0B3A45', '#227A8C', 'Light teal' ),
		'#5E6976' => array( '#FFFFFF', '#5E6976', 'Gray' ),
		'#343C46' => array( '#FFFFFF', '#5E6976', 'Dark gray' ),
		'#E1E5EA' => array( '#1F2933', '#5E6976', 'Light gray' ),
	);

	/** A shared device's plain gray (no shape, never handed to a person). */
	const KIOSK = array( 'color' => '#EEF0F3', 'ink' => '#4A5560', 'edge' => '#B8C0C9' );

	/** Role => shape, most privileged first (the first a person holds wins). */
	const SHAPES = array(
		'administrator' => 'circle',
		'zdz_owner'     => 'hexagon',
		'zdz_admin'     => 'pentagon',
		'zdz_operator'  => 'square',
		'zdz_sales'     => 'triangle',
		'zdz_tech'      => 'triangle',
		'zdz_mfg'       => 'triangle',
		'zdz_general'   => 'kiosk',
	);

	/** @var array|null request memo */
	private static $memo = null;

	/** @var array|null the palette after the filter */
	private static $tones = null;

	public static function tones(): array {
		if ( null !== self::$tones ) {
			return self::$tones;
		}
		$t = function_exists( 'apply_filters' ) ? apply_filters( 'zdz_person_tones', self::TONES ) : self::TONES;
		$ok = array();
		foreach ( (array) $t as $hex => $row ) {
			$hex = strtoupper( (string) $hex );
			if ( preg_match( '/^#[0-9A-F]{6}$/', $hex ) && is_array( $row ) && count( $row ) >= 3 ) {
				$ok[ $hex ] = array( (string) $row[0], (string) $row[1], (string) $row[2] );
			}
		}
		return self::$tones = $ok ? $ok : self::TONES;
	}

	public static function palette(): array {
		return array_keys( self::tones() );
	}

	public static function ink( string $color ): string {
		$t = self::tones();
		return $t[ strtoupper( $color ) ][0] ?? '#FFFFFF';
	}

	public static function edge( string $color ): string {
		$t = self::tones();
		return $t[ strtoupper( $color ) ][1] ?? '#5E6976';
	}

	/** The tones for the picker: [ {color, ink, edge, name}, … ] in hand-out order. */
	public static function tones_for_js(): array {
		$out = array();
		foreach ( self::tones() as $c => $t ) {
			$out[] = array( 'color' => $c, 'ink' => $t[0], 'edge' => $t[1], 'name' => $t[2] );
		}
		return $out;
	}

	/** Spoken names for the picker ("Dark teal"), never a hex. */
	public static function names(): array {
		$out = array();
		foreach ( self::tones() as $c => $t ) {
			$out[ $c ] = $t[2];
		}
		return $out;
	}

	public static function is_palette( string $color ): bool {
		$t = self::tones();
		return isset( $t[ strtoupper( $color ) ] );
	}

	/** What each shape stands for, from the business's own role labels. */
	public static function shape_labels(): array {
		$labels = class_exists( 'ZDZ_User_Roles' ) ? ZDZ_User_Roles::get_role_labels() : array();
		$l      = function ( $slug, $fallback ) use ( $labels ) {
			return (string) ( $labels[ $slug ] ?? $fallback );
		};
		return array(
			'triangle' => implode( ', ', array( $l( 'zdz_sales', 'Sales' ), $l( 'zdz_tech', 'Field Tech' ), $l( 'zdz_mfg', 'Shop' ) ) ),
			'square'   => $l( 'zdz_operator', 'Operator' ),
			'pentagon' => $l( 'zdz_admin', 'Administrator' ),
			'hexagon'  => $l( 'zdz_owner', 'Owner' ),
			'circle'   => $l( 'administrator', 'WordPress Administrator' ),
			'kiosk'    => $l( 'zdz_general', 'Shared device' ),
		);
	}

	/** The shape for a set of roles: the most privileged role's; anyone else with initials is a triangle. */
	public static function shape_for_roles( array $roles ): string {
		foreach ( self::SHAPES as $role => $shape ) {
			if ( in_array( $role, $roles, true ) ) {
				return $shape;
			}
		}
		return 'triangle';
	}

	/**
	 * Pure: assign tones and shapes. $people = [ uid => [ 'initials' => 'AB', 'chosen' => '#3569B5' | '',
	 * 'roles' => [ 'zdz_sales' ] ], … ]. Chosen tones are kept; everyone else, in ascending uid order,
	 * takes the first tone nobody has; when all are taken the tones are reused in order. A shared
	 * device is always plain gray.
	 *
	 * @return array uid => [ 'initials', 'color', 'ink', 'edge', 'shape', 'chosen' (bool) ]
	 */
	public static function assign( array $people ): array {
		ksort( $people, SORT_NUMERIC );
		$taken = array();
		$out   = array();
		foreach ( $people as $uid => $p ) {
			$shape = self::shape_for_roles( (array) ( $p['roles'] ?? array() ) );
			if ( 'kiosk' === $shape ) {
				$out[ $uid ] = array( 'initials' => (string) $p['initials'], 'color' => self::KIOSK['color'], 'ink' => self::KIOSK['ink'],
					'edge' => self::KIOSK['edge'], 'shape' => 'kiosk', 'chosen' => false );
				continue;
			}
			$c = strtoupper( (string) ( $p['chosen'] ?? '' ) );
			if ( '' !== $c && self::is_palette( $c ) ) {
				$out[ $uid ]  = array( 'initials' => (string) $p['initials'], 'color' => $c, 'ink' => self::ink( $c ), 'edge' => self::edge( $c ),
					'shape' => $shape, 'chosen' => true );
				$taken[ $c ] = true;
			}
		}
		$pal = self::palette();
		$i   = 0;
		foreach ( $people as $uid => $p ) {
			if ( isset( $out[ $uid ] ) ) {
				continue;
			}
			$free = array_values( array_filter( $pal, static function ( $c ) use ( $taken ) { return ! isset( $taken[ $c ] ); } ) );
			if ( $free ) {
				$c = $free[0];
			} else {
				$c = $pal[ $i % count( $pal ) ];
				$i++;
			}
			$taken[ $c ] = true;
			$out[ $uid ]  = array( 'initials' => (string) $p['initials'], 'color' => $c, 'ink' => self::ink( $c ), 'edge' => self::edge( $c ),
				'shape' => self::shape_for_roles( (array) ( $p['roles'] ?? array() ) ), 'chosen' => false );
		}
		ksort( $out, SORT_NUMERIC );
		return $out;
	}

	/** Every person with initials, coloured. Memoized per request. */
	public static function all(): array {
		if ( null !== self::$memo ) {
			return self::$memo;
		}
		$people = array();
		if ( function_exists( 'get_users' ) ) {
			$users = get_users( array(
				'meta_key'     => self::META_INITIALS,
				'meta_compare' => 'EXISTS',
				'fields'       => 'all',
				'number'       => 500,
			) );
			$ids = array_map( static function ( $u ) { return (int) ( is_object( $u ) ? $u->ID : $u ); }, (array) $users );
			if ( $ids && function_exists( 'update_meta_cache' ) ) {
				update_meta_cache( 'user', $ids ); // one query for everyone's meta
			}
			foreach ( (array) $users as $u ) {
				$uid = (int) ( is_object( $u ) ? $u->ID : $u );
				$ini = trim( (string) get_user_meta( $uid, self::META_INITIALS, true ) );
				if ( $uid <= 0 || '' === $ini ) {
					continue;
				}
				$roles = ( is_object( $u ) && isset( $u->roles ) ) ? (array) $u->roles : array();
				if ( ! $roles && function_exists( 'get_userdata' ) ) {
					$ud    = get_userdata( $uid );
					$roles = ( $ud && isset( $ud->roles ) ) ? (array) $ud->roles : array();
				}
				$people[ $uid ] = array( 'initials' => $ini, 'chosen' => (string) get_user_meta( $uid, self::META, true ), 'roles' => array_values( $roles ) );
			}
		}
		self::$memo = self::assign( $people );
		return self::$memo;
	}

	/** One person's tone, or empty values when they have no initials. */
	public static function for_user( int $uid ): array {
		$all = self::all();
		return $all[ $uid ] ?? array( 'initials' => '', 'color' => '', 'ink' => '', 'edge' => '', 'shape' => '', 'chosen' => false );
	}

	/** Choose (or, with '', clear) a person's tone. Admins only — the caller checks. */
	public static function set( int $uid, string $color ): bool {
		$color      = strtoupper( trim( $color ) );
		self::$memo = null;
		if ( '' === $color ) {
			return (bool) delete_user_meta( $uid, self::META );
		}
		if ( ! self::is_palette( $color ) ) {
			return false;
		}
		update_user_meta( $uid, self::META, $color );
		return true;
	}

	/** For the front end: [ {id, initials, color, ink, edge, shape}, … ] — no names, no e-mails. */
	public static function for_js(): array {
		$out = array();
		foreach ( self::all() as $uid => $p ) {
			$out[] = array( 'id' => (int) $uid, 'initials' => $p['initials'], 'color' => $p['color'], 'ink' => $p['ink'],
				'edge' => $p['edge'], 'shape' => $p['shape'] );
		}
		return $out;
	}

	/** Reset the request memo (tests, and after an admin save). */
	public static function flush(): void {
		self::$memo  = null;
		self::$tones = null;
	}
}
