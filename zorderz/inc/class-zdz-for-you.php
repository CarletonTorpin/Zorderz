<?php
/**
 * ZDZ_For_You — each person's apps, at work and after hours.
 *
 * The home screen groups the app icons a person is most likely to need first, and that set can differ between their
 * working hours ("at work") and the rest of the time ("after hours"). Three pieces, all per person:
 *
 *   1. Work hours: user meta `zdz_work_hours` = { days: [1..7] (ISO, 1 = Monday), start: 'HH:MM', end: 'HH:MM' },
 *      set by an administrator on the person's profile, read in the site's time zone. Unset = the business's usual
 *      hours (option `zdz_work_hours_default`, which an org pack writes, then the filter of the same name), else Core's
 *      Monday to Friday, 09:00 to 17:00.
 *   2. A starting list per role and mode (filter `zdz_for_you_defaults`): what someone in that job opens first.
 *   3. What the person actually opens: the platform's own usage records (`app_open`, last 60 days, only for people whose
 *      usage may be recorded at all), each open counted for the mode it happened in, newer opens weighing more
 *      (half-life 14 days).
 *
 * The hour: each open is also counted in its cell -- the mode, the kind of day (one of the person's work days, or a day
 * off) and the hour band (BANDS: early, morning, midday, afternoon, evening, night). A cell's order is the role's
 * starting list for the mode, plus the opens in that cell (weighed most), plus the opens in the whole mode (weighed
 * less, so a quiet cell still learns). The page gets every cell whose order differs from its mode's (zdzData.forYou.cells)
 * and picks the cell for the site's clock. It only orders: an app the person may open is never taken off home, and a
 * row it cannot fill falls back to the mode's list.
 *
 *   - Zorderz -> Settings, "Learn each person's apps": off = the role's starting lists only (option zdz_for_you_learn,
 *     on by default).
 *   - Settings -> "Reset my app order" in the app (POST /zorderz/v1/for-you/reset): opens before that moment are no
 *     longer counted (user meta zdz_for_you_reset_at); the role's lists lead again until new opens accumulate.
 *
 * The page gets both ranked lists and the hours (zdzData.forYou) and decides the mode itself from the clock, so a page
 * left open across the end of the day changes over without a reload. Nothing here is shown to anyone but the person,
 * and the shared device gets none of it (its home stays as it is).
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_For_You {

	const META_HOURS   = 'zdz_work_hours';
	const OPT_HOURS    = 'zdz_work_hours_default';   // the business's usual hours (an org pack writes it)
	const SIZE         = 8;    // apps in the "At work" / "After hours" row(s): two rows of four on a phone
	const DAYS_BACK    = 60;
	const HALF_LIFE    = 14;   // days
	const MAX_ROWS     = 3000;
	const CACHE_TTL    = 3600; // the ranking moves slowly; an hour is fresh enough
	const CACHE_PREFIX = 'zdz_fy_';
	const OPT_LEARN    = 'zdz_for_you_learn';      // '1' (default) learns from opens; '0' = role lists only
	const META_RESET   = 'zdz_for_you_reset_at';   // "Reset my app order": opens before this are not counted
	const CELL_WEIGHT  = 1.5;                      // opens in this hour band and kind of day
	const MODE_WEIGHT  = 0.5;                      // opens anywhere in the mode, so a quiet cell still learns

	/** Core's usual hours when the business has not said: Monday to Friday, 09:00 to 17:00. */
	const CORE_HOURS = array( 'days' => array( 1, 2, 3, 4, 5 ), 'start' => '09:00', 'end' => '17:00' );

	/**
	 * The hour bands, [ name, first hour, hour after the last ] in the site's zone; night runs past midnight.
	 * Filter zdz_for_you_bands (each hour must land in exactly one band, or the default is used).
	 */
	const BANDS = array(
		array( 'early', 5, 8 ),
		array( 'morning', 8, 11 ),
		array( 'midday', 11, 14 ),
		array( 'afternoon', 14, 17 ),
		array( 'evening', 17, 21 ),
		array( 'night', 21, 5 ),
	);

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_action( 'zdz_core_settings_rows', array( __CLASS__, 'render_setting_row' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'render_profile' ), 20 );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_profile' ), 20 );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile' ) );
		add_action( 'admin_notices', array( __CLASS__, 'profile_notice' ) );
	}

	/**
	 * Why the hours were left as they were, shown once at the top of the profile page after the save. Not an error in
	 * user_profile_update_errors: an error there stops WordPress saving the rest of the profile too.
	 */
	private static function note( $msg ) {
		set_transient( 'zdz_work_hours_note_' . get_current_user_id(), $msg, 120 );
	}

	public static function profile_notice() {
		$k   = 'zdz_work_hours_note_' . get_current_user_id();
		$msg = get_transient( $k );
		if ( ! is_string( $msg ) || '' === $msg ) {
			return;
		}
		delete_transient( $k );
		echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}

	/* ── Work hours ─────────────────────────────────────────────────────────────────────────────────────── */

	/** The business's usual hours: its setting, then the filter, then Core's. */
	public static function default_hours() {
		$stored = self::sanitize_hours( function_exists( 'get_option' ) ? get_option( self::OPT_HOURS, null ) : null );
		$d      = $stored ? $stored : self::CORE_HOURS;
		if ( function_exists( 'apply_filters' ) ) {
			$f = self::sanitize_hours( apply_filters( self::OPT_HOURS, $d ) );
			$d = $f ? $f : $d;
		}
		$clean = self::sanitize_hours( $d );
		return $clean ? $clean : self::CORE_HOURS;
	}

	/**
	 * A clean hours array, or null when the input is not one. Days are ISO numbers 1..7, unique and sorted;
	 * times are 24-hour 'HH:MM'. An end at or before the start means the hours run past midnight.
	 */
	public static function sanitize_hours( $in ) {
		if ( ! is_array( $in ) ) {
			return null;
		}
		$days = array();
		foreach ( (array) ( $in['days'] ?? array() ) as $d ) {
			if ( ! is_scalar( $d ) ) {
				continue;
			}
			$d = (int) $d;
			if ( $d >= 1 && $d <= 7 ) {
				$days[ $d ] = $d;
			}
		}
		ksort( $days );
		$t = function ( $v ) {
			$v = is_scalar( $v ) ? trim( (string) $v ) : '';
			if ( ! preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $v, $m ) ) {
				return null;
			}
			return sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
		};
		$start = $t( $in['start'] ?? '' );
		$end   = $t( $in['end'] ?? '' );
		if ( null === $start || null === $end || $start === $end ) {
			return null;
		}
		return array( 'days' => array_values( $days ), 'start' => $start, 'end' => $end );
	}

	/** This person's hours, and whether an administrator set them (false = the business's usual hours). */
	public static function hours_for( $user_id ) {
		$raw   = get_user_meta( (int) $user_id, self::META_HOURS, true );
		$clean = self::sanitize_hours( $raw );
		if ( $clean ) {
			$clean['set'] = true;
			return $clean;
		}
		$d        = self::default_hours();
		$d['set'] = false;
		return $d;
	}

	/** Minutes since midnight for 'HH:MM'. */
	private static function mins( $hhmm ) {
		list( $h, $m ) = array_map( 'intval', explode( ':', $hhmm ) );
		return $h * 60 + $m;
	}

	/**
	 * 'work' or 'off' for a moment, given hours. ISO day $dow (1..7) and minutes since midnight, both in the site's
	 * time zone. Hours that run past midnight belong to the day they start on.
	 */
	public static function mode_at( array $hours, $dow, $minute ) {
		$s    = self::mins( $hours['start'] );
		$e    = self::mins( $hours['end'] );
		$days = array_map( 'intval', (array) $hours['days'] );
		if ( $e === $s ) {
			return 'off';   // no span of hours (cleaned hours never carry one; the page reads it the same way)
		}
		if ( $e > $s ) {
			return ( in_array( (int) $dow, $days, true ) && $minute >= $s && $minute < $e ) ? 'work' : 'off';
		}
		$prev = ( (int) $dow + 5 ) % 7 + 1; // ISO day before
		if ( in_array( (int) $dow, $days, true ) && $minute >= $s ) {
			return 'work';
		}
		if ( in_array( $prev, $days, true ) && $minute < $e ) {
			return 'work';
		}
		return 'off';
	}

	/* ── What a role opens first ────────────────────────────────────────────────────────────────────────── */

	/**
	 * The starting list per role and mode, by Core role slug ('_default' for any other role). A business replaces any
	 * of it with the filter `zdz_for_you_defaults`; an app id nobody has installed is simply never shown.
	 */
	public static function role_defaults() {
		$admin_work = array( 'jobs', 'estimate-creator', 'scheduler', 'inbox', 'internal-messaging', 'leads', 'sales-analytics', 'commission-calculator' );
		$admin_off  = array( 'internal-messaging', 'inbox', 'scheduler', 'jobs', 'commission-calculator', 'sales-analytics', 'game', 'zdz-media' );
		$d = array(
			'work' => array(
				'administrator' => $admin_work,
				'zdz_owner'     => $admin_work,
				'zdz_admin'     => $admin_work,
				'zdz_sales'     => array( 'estimate-creator', 'leads', 'scheduler', 'jobs', 'dotplot', 'zdz-camera', 'internal-messaging', 'commission-calculator' ),
				'zdz_operator'  => array( 'jobs', 'scheduler', 'inbox', 'internal-messaging', 'surveys', 'receipts', 'knowledge-vault', 'estimate-creator' ),
				'zdz_mfg'       => array( 'prep', 'jobs', 'stock', 'zdz-camera', 'scheduler', 'internal-messaging', 'knowledge-vault', 'zdz-media' ),
				'zdz_tech'      => array( 'jobs', 'scheduler', 'zdz-camera', 'stock', 'internal-messaging', 'knowledge-vault', 'receipts', 'zdz-media' ),
				'_default'      => array( 'jobs', 'scheduler', 'internal-messaging', 'zdz-camera', 'knowledge-vault', 'inbox', 'zdz-media', 'zdz-sketch-pad' ),
			),
			'off'  => array(
				'administrator' => $admin_off,
				'zdz_owner'     => $admin_off,
				'zdz_admin'     => $admin_off,
				'zdz_sales'     => array( 'internal-messaging', 'commission-calculator', 'leads', 'scheduler', 'inbox', 'estimate-creator', 'game', 'zdz-media' ),
				'_default'      => array( 'internal-messaging', 'scheduler', 'inbox', 'zdz-media', 'game', 'zdz-sketch-pad', 'knowledge-vault', 'zdz-camera' ),
			),
		);
		if ( function_exists( 'apply_filters' ) ) {
			$f = apply_filters( 'zdz_for_you_defaults', $d );
			$d = is_array( $f ) ? $f : $d;
		}
		return array(
			'work' => is_array( $d['work'] ?? null ) ? $d['work'] : array(),
			'off'  => is_array( $d['off'] ?? null ) ? $d['off'] : array(),
		);
	}

	/** The role's starting list for a mode as weights: the first app 3, the last a little over 1. */
	public static function prior_for( $role, $mode ) {
		$d    = self::role_defaults();
		$list = $d[ $mode ][ $role ] ?? ( $d[ $mode ]['_default'] ?? array() );
		$out  = array();
		$n    = max( 1, count( (array) $list ) );
		foreach ( array_values( (array) $list ) as $i => $id ) {
			if ( is_string( $id ) && preg_match( '/^[a-z0-9_\-]{1,64}$/', $id ) && ! isset( $out[ $id ] ) ) {
				$out[ $id ] = 3.0 - 2.0 * $i / $n;
			}
		}
		return $out;
	}

	/* ── The hour, the switch and the reset ──────────────────────────────────────────────────────────────── */

	/** The bands, checked: every hour 0..23 in exactly one. */
	public static function bands() {
		$b    = function_exists( 'apply_filters' ) ? apply_filters( 'zdz_for_you_bands', self::BANDS ) : self::BANDS;
		$ok   = is_array( $b ) && $b;
		$seen = array();
		if ( $ok ) {
			foreach ( $b as $x ) {
				if ( ! is_array( $x ) || 3 !== count( $x ) || ! is_string( $x[0] ) || ! preg_match( '/^[a-z]{1,16}$/', $x[0] ) ) {
					$ok = false;
					break;
				}
				foreach ( self::band_hours( (int) $x[1], (int) $x[2] ) as $h ) {
					$seen[ $h ] = ( $seen[ $h ] ?? 0 ) + 1;
				}
			}
		}
		if ( ! $ok || 24 !== count( $seen ) || max( $seen ) > 1 ) {
			return self::BANDS;
		}
		return array_values( array_map( function ( $x ) { return array( (string) $x[0], (int) $x[1], (int) $x[2] ); }, $b ) );
	}

	private static function band_hours( $from, $to ) {
		$out = array();
		if ( $from < 0 || $from > 23 || $to < 0 || $to > 24 || $from === $to % 24 ) {
			return $out;
		}
		$to = $to % 24;
		$h  = $from;
		do {
			$out[] = $h;
			$h     = ( $h + 1 ) % 24;
		} while ( $h !== $to && count( $out ) < 24 );
		return $out;
	}

	/** The band an hour (0..23, the site's zone) falls in. */
	public static function band_of( $hour, $bands = null ) {
		$bands = $bands ?: self::bands();
		foreach ( $bands as $b ) {
			if ( in_array( (int) $hour, self::band_hours( $b[1], $b[2] ), true ) ) {
				return $b[0];
			}
		}
		return $bands[0][0];
	}

	/** 'workday' when $dow (ISO) is one of the person's work days, else 'dayoff'. */
	public static function day_type( array $hours, $dow ) {
		return in_array( (int) $dow, array_map( 'intval', (array) $hours['days'] ), true ) ? 'workday' : 'dayoff';
	}

	/** Is the learned row on? Zorderz -> Settings, "Learn each person's apps" (on by default). */
	public static function learning() {
		return ! function_exists( 'get_option' ) || '0' !== (string) get_option( self::OPT_LEARN, '1' );
	}

	public static function register_setting() {
		register_setting( 'zdz_core_settings_group', self::OPT_LEARN, array(
			'type'              => 'string',
			'default'           => '1',
			'sanitize_callback' => function ( $v ) { return '1' === (string) $v ? '1' : '0'; },
		) );
	}

	/** The switch, on Zorderz -> Settings. */
	public static function render_setting_row() {
		$on = self::learning();
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( self::OPT_LEARN ); ?>"><?php esc_html_e( 'Learn each person\'s apps', 'zorderz' ); ?></label></th>
			<td>
				<input type="hidden" name="<?php echo esc_attr( self::OPT_LEARN ); ?>" value="0" />
				<label><input type="checkbox" id="<?php echo esc_attr( self::OPT_LEARN ); ?>" name="<?php echo esc_attr( self::OPT_LEARN ); ?>" value="1" <?php checked( $on ); ?> />
				<?php esc_html_e( 'Order each person\'s home apps by what they open, at work and after hours.', 'zorderz' ); ?></label>
				<p class="description"><?php esc_html_e( 'Off: everyone sees their role\'s starting list. It only ever orders the apps a person may open, and only counts opens where usage may be recorded.', 'zorderz' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/** When this person last pressed "Reset my app order" (0 = never). */
	public static function reset_at( $user_id ) {
		$v = get_user_meta( (int) $user_id, self::META_RESET, true );
		return is_numeric( $v ) ? (int) $v : 0;
	}

	/** The shared device (or a person with no account) has no order of its own. */
	public static function is_shared( $user_id, $role = '' ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return true;
		}
		$shared = class_exists( 'ZDZ_User_Roles' ) && defined( 'ZDZ_User_Roles::SHARED_DEVICE_ROLE' ) ? ZDZ_User_Roles::SHARED_DEVICE_ROLE : 'zdz_general';
		if ( $shared === (string) $role ) {
			return true;
		}
		if ( class_exists( 'ZDZ_Hierarchy' ) && method_exists( 'ZDZ_Hierarchy', 'is_kiosk' ) && ZDZ_Hierarchy::is_kiosk( $user_id ) ) {
			return true;
		}
		return class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $user_id );
	}

	public static function routes() {
		register_rest_route( ZDZ_REST_NS, '/for-you/reset', array(
			'methods'             => 'POST',
			'permission_callback' => function () { return is_user_logged_in(); },
			'callback'            => array( __CLASS__, 'rest_reset' ),
		) );
	}

	public static function rest_reset() {
		$uid  = (int) get_current_user_id();
		$role = '';
		$u    = get_userdata( $uid );
		if ( $u ) {
			$r    = (array) $u->roles;
			$role = (string) reset( $r );
		}
		if ( self::is_shared( $uid, $role ) || ( class_exists( 'ZDZ_Kiosk_Demo' ) && ZDZ_Kiosk_Demo::is_active() ) ) {
			return new WP_Error( 'zdz_fy_shared', __( 'The shared account has no app order of its own.', 'zorderz' ), array( 'status' => 403 ) );
		}
		// The row the page shows: View As draws the viewed role's.
		if ( class_exists( 'ZDZ_View_As' ) && ZDZ_View_As::is_emulating() && ZDZ_View_As::get_emulated_role() ) {
			$role = (string) ZDZ_View_As::get_emulated_role();
		}
		update_user_meta( $uid, self::META_RESET, time() );
		return rest_ensure_response( array( 'success' => true, 'forYou' => self::for_js( $uid, $role ) ) );
	}

	/* ── What the person opens ──────────────────────────────────────────────────────────────────────────── */

	/**
	 * The person's app opens since $since: [ { app_id, ts } ], newest first. created_at is written by the database in
	 * its own session zone, so the database turns it into a moment itself (UNIX_TIMESTAMP) and the window starts at
	 * FROM_UNIXTIME: right across a daylight-saving change too, whatever zone the database runs in.
	 */
	protected static function opens( $user_id, $since ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}
		$table = $wpdb->prefix . 'zdz_audit_log';
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT app_id, UNIX_TIMESTAMP(created_at) AS ts FROM {$table} WHERE user_id = %d AND action_type = 'app_open' AND created_at >= FROM_UNIXTIME(%d) ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			(int) $user_id,
			(int) $since,
			self::MAX_ROWS
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Weighted opens per app and mode, plus 'cells' (the same weights per mode|day type|band, e.g.
	 * 'work|workday|morning'), counting nothing before the person's "Reset my app order".
	 *
	 * @param array      $hours
	 * @param array      $rows    [ { app_id, ts } ] (unix seconds)
	 * @param int        $now     unix seconds
	 * @param int        $since   unix seconds: nothing earlier counts
	 * @param \DateTimeZone $tz   the site's zone
	 * @return array{work: array<string,float>, off: array<string,float>, cells: array<string,array<string,float>>}
	 */
	public static function weigh( array $hours, array $rows, $now, $since, $tz ) {
		$out   = array( 'work' => array(), 'off' => array(), 'cells' => array() );
		$bands = self::bands();
		foreach ( $rows as $r ) {
			$id = (string) ( $r['app_id'] ?? '' );
			if ( '' === $id || ! preg_match( '/^[a-z0-9_\-]{1,64}$/', $id ) ) {
				continue;
			}
			$ts = isset( $r['ts'] ) && is_numeric( $r['ts'] ) ? (int) $r['ts'] : 0;
			if ( $ts <= 0 || $ts < $since || $ts > $now + 300 ) {
				continue;   // unreadable, before "Reset my app order", or from the future
			}
			$local = ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz );
			$dow   = (int) $local->format( 'N' );
			$hour  = (int) $local->format( 'G' );
			$mode  = self::mode_at( $hours, $dow, $hour * 60 + (int) $local->format( 'i' ) );
			$age   = max( 0, ( $now - $ts ) / 86400 );
			$w     = pow( 0.5, $age / self::HALF_LIFE );
			$out[ $mode ][ $id ] = ( $out[ $mode ][ $id ] ?? 0 ) + $w;
			$cell  = $mode . '|' . self::day_type( $hours, $dow ) . '|' . self::band_of( $hour, $bands );
			$out['cells'][ $cell ][ $id ] = ( $out['cells'][ $cell ][ $id ] ?? 0 ) + $w;
		}
		return $out;
	}

	/** usage_for(): the person's own opens, weighed (see weigh()). */
	public static function usage_for( $user_id, array $hours, $now_ts = null ) {
		$now   = null === $now_ts ? time() : (int) $now_ts;
		$since = max( $now - self::DAYS_BACK * 86400, self::reset_at( $user_id ) );
		return self::weigh( $hours, static::opens( $user_id, $since ), $now, $since, wp_timezone() );
	}

	/**
	 * Apps ranked for one mode: the role's starting list plus the person's own opens. An app opened a few times a
	 * week overtakes the starting list within a couple of weeks; one opened once a month barely moves.
	 */
	public static function rank( array $prior, array $usage ) {
		return self::rank_cell( $prior, array(), $usage, 1.5 );
	}

	/**
	 * One cell's order. The role's starting list for the mode, plus this cell's opens (CELL_WEIGHT), plus the mode's
	 * opens ($mode_weight, MODE_WEIGHT by default): an app opened every weekday at 8 leads the weekday morning, while a
	 * quiet cell still follows the person's mode as a whole. Ties keep the starting list's order, then the app id's.
	 */
	public static function rank_cell( array $prior, array $cell, array $mode, $mode_weight = null ) {
		$mw    = null === $mode_weight ? self::MODE_WEIGHT : (float) $mode_weight;
		$score = array();
		foreach ( $prior as $id => $p ) {
			$score[ $id ] = $p;
		}
		foreach ( $cell as $id => $u ) {
			$score[ $id ] = ( $score[ $id ] ?? 0 ) + self::CELL_WEIGHT * min( 20.0, (float) $u );
		}
		foreach ( $mode as $id => $u ) {
			$score[ $id ] = ( $score[ $id ] ?? 0 ) + $mw * min( 20.0, (float) $u );
		}
		$order = array_flip( array_keys( $prior ) );
		uksort( $score, function ( $a, $b ) use ( $score, $order ) {
			if ( abs( $score[ $a ] - $score[ $b ] ) > 1e-9 ) {
				return $score[ $b ] <=> $score[ $a ];
			}
			$oa = $order[ $a ] ?? 999;
			$ob = $order[ $b ] ?? 999;
			return $oa === $ob ? strcmp( (string) $a, (string) $b ) : $oa <=> $ob;
		} );
		return array_map( 'strval', array_keys( $score ) );
	}

	/**
	 * The ranked lists for one person: { work, off, cells }. A cell is kept only when its first 12 apps differ from its
	 * mode's (the page falls back to the mode's list for any cell it does not get), so a person with little history
	 * sends nothing extra.
	 */
	public static function ranking( $role, array $usage ) {
		$rank = array(
			'work'  => self::rank( self::prior_for( $role, 'work' ), (array) ( $usage['work'] ?? array() ) ),
			'off'   => self::rank( self::prior_for( $role, 'off' ), (array) ( $usage['off'] ?? array() ) ),
			'cells' => array(),
		);
		foreach ( (array) ( $usage['cells'] ?? array() ) as $cell => $u ) {
			$mode = strtok( (string) $cell, '|' );
			if ( 'work' !== $mode && 'off' !== $mode ) {
				continue;
			}
			$list = self::rank_cell( self::prior_for( $role, $mode ), (array) $u, (array) ( $usage[ $mode ] ?? array() ) );
			if ( array_slice( $list, 0, 12 ) !== array_slice( $rank[ $mode ], 0, 12 ) ) {
				$rank['cells'][ (string) $cell ] = array_slice( $list, 0, 16 );
			}
		}
		return $rank;
	}

	/**
	 * What the page gets as zdzData.forYou, or null for the shared device (its home stays as it is).
	 */
	public static function for_js( $user_id, $role ) {
		$user_id = (int) $user_id;
		if ( self::is_shared( $user_id, $role ) ) {
			return null;
		}
		$hours = self::hours_for( $user_id );
		// Usage records follow consent: a person who said no is never learned from.
		$learn = self::learning() && ( ! class_exists( 'ZDZ_Admin_Dashboard' ) || ! method_exists( 'ZDZ_Admin_Dashboard', 'may_record' ) || ZDZ_Admin_Dashboard::may_record( $user_id ) );
		$reset = self::reset_at( $user_id );
		$bands = self::bands();
		$key   = self::CACHE_PREFIX . $user_id . '_' . substr( md5( wp_json_encode( array( $hours, $role, $learn, $reset, $bands, self::role_defaults() ) ) ), 0, 10 );
		$rank  = get_transient( $key );
		if ( ! is_array( $rank ) || ! isset( $rank['work'], $rank['off'] ) ) {
			$usage = $learn ? self::usage_for( $user_id, $hours ) : array( 'work' => array(), 'off' => array(), 'cells' => array() );
			$rank  = self::ranking( (string) $role, $usage );
			set_transient( $key, $rank, self::CACHE_TTL );
		}
		return array(
			'tz'    => wp_timezone_string(),
			'hours' => array( 'days' => $hours['days'], 'start' => $hours['start'], 'end' => $hours['end'], 'set' => (bool) $hours['set'] ),
			'work'  => array_values( $rank['work'] ),
			'off'   => array_values( $rank['off'] ),
			'size'  => max( 1, min( 16, (int) apply_filters( 'zdz_for_you_size', self::SIZE ) ) ),
			'bands' => $bands,
			'cells' => (object) ( $rank['cells'] ?? array() ),
			'learn' => $learn,
		);
	}

	/* ── The profile page (administrators set it) ───────────────────────────────────────────────────────── */

	public static function render_profile( $user ) {
		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}
		$h     = self::hours_for( $user->ID );
		$names = array( 1 => __( 'Mon', 'zorderz' ), 2 => __( 'Tue', 'zorderz' ), 3 => __( 'Wed', 'zorderz' ), 4 => __( 'Thu', 'zorderz' ), 5 => __( 'Fri', 'zorderz' ), 6 => __( 'Sat', 'zorderz' ), 7 => __( 'Sun', 'zorderz' ) );
		wp_nonce_field( 'zdz_save_work_hours', 'zdz_work_hours_nonce' );
		?>
		<h3><?php esc_html_e( 'Work hours', 'zorderz' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'Days', 'zorderz' ); ?></th>
				<td>
					<?php foreach ( $names as $n => $label ) : ?>
						<label style="margin-right:12px"><input type="checkbox" name="zdz_work_days[]" value="<?php echo (int) $n; ?>" <?php checked( in_array( $n, $h['days'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th><label for="zdz_work_start"><?php esc_html_e( 'Hours', 'zorderz' ); ?></label></th>
				<td>
					<input type="time" id="zdz_work_start" name="zdz_work_start" value="<?php echo esc_attr( $h['start'] ); ?>"> –
					<input type="time" id="zdz_work_end" name="zdz_work_end" value="<?php echo esc_attr( $h['end'] ); ?>" aria-label="<?php esc_attr_e( 'Hours end', 'zorderz' ); ?>">
					<label style="margin-left:12px"><input type="checkbox" id="zdz_work_hours_default" name="zdz_work_hours_default" value="1" <?php checked( ! $h['set'] ); ?>> <?php esc_html_e( 'Use the usual hours', 'zorderz' ); ?></label>
					<p class="description"><?php esc_html_e( 'In the site\'s time zone. Home shows this person\'s work apps in these hours and their after-hours apps the rest of the time.', 'zorderz' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
		// Changing a day or a time unticks "Use the usual hours"; ticking it puts the usual hours back in the fields,
		// so what is saved is what the page shows.
		$d = self::default_hours();
		?>
		<script>
		(function () {
			var box = document.getElementById('zdz_work_hours_default');
			if (!box) return;
			var usual = <?php echo wp_json_encode( array( 'days' => $d['days'], 'start' => $d['start'], 'end' => $d['end'] ) ); ?>;
			var days = document.querySelectorAll('input[name="zdz_work_days[]"]');
			var st = document.getElementById('zdz_work_start'), en = document.getElementById('zdz_work_end');
			var edited = function () { box.checked = false; };
			Array.prototype.forEach.call(days, function (c) { c.addEventListener('change', edited); });
			[st, en].forEach(function (i) { if (i) { i.addEventListener('input', edited); i.addEventListener('change', edited); } });
			box.addEventListener('change', function () {
				if (!box.checked) return;
				Array.prototype.forEach.call(days, function (c) { c.checked = usual.days.indexOf(parseInt(c.value, 10)) !== -1; });
				if (st) st.value = usual.start;
				if (en) en.value = usual.end;
			});
		})();
		</script>
		<?php
	}

	public static function save_profile( $user_id ) {
		if ( ! current_user_can( 'edit_users' ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		if ( ! isset( $_POST['zdz_work_hours_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['zdz_work_hours_nonce'] ) ), 'zdz_save_work_hours' ) ) {
			return;
		}
		$sent  = array(
			'days'  => isset( $_POST['zdz_work_days'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['zdz_work_days'] ) ) : array(),
			'start' => sanitize_text_field( wp_unslash( $_POST['zdz_work_start'] ?? '' ) ),
			'end'   => sanitize_text_field( wp_unslash( $_POST['zdz_work_end'] ?? '' ) ),
		);
		$clean = self::sanitize_hours( $sent );
		$same  = function ( $a, $b ) {
			return $a && $b && $a['days'] === $b['days'] && $a['start'] === $b['start'] && $a['end'] === $b['end'];
		};
		if ( ! empty( $_POST['zdz_work_hours_default'] ) ) {
			// Ticked: the usual hours. The fields count only if someone changed them from what the page showed (the
			// person's current hours) and from the usual hours, with the box left ticked: a browser that did not run
			// the page's script. Then the changed hours are kept and the page says so.
			$shown = self::hours_for( $user_id );
			if ( ! $clean || $same( $clean, self::default_hours() ) || $same( $clean, $shown ) ) {
				delete_user_meta( $user_id, self::META_HOURS );
				return;
			}
			if ( ! $clean['days'] ) {
				self::note( __( 'Work hours were not changed: pick at least one work day.', 'zorderz' ) );
				return;
			}
			self::note( __( 'Work hours: "Use the usual hours" was ticked, but the days or times were changed, so the changed hours were saved.', 'zorderz' ) );
			update_user_meta( $user_id, self::META_HOURS, $clean );
			return;
		}
		if ( ! $clean ) {
			self::note( __( 'Work hours were not changed: the start and end must be times, and not the same time.', 'zorderz' ) );
			return;
		}
		if ( ! $clean['days'] ) {
			self::note( __( 'Work hours were not changed: pick at least one work day.', 'zorderz' ) );
			return;
		}
		update_user_meta( $user_id, self::META_HOURS, $clean );
	}
}
