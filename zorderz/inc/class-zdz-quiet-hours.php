<?php
/**
 * ZDZ_Quiet_Hours — when a phone notification waits.
 *
 * A notice or a message that arrives during a person's quiet hours is held, never dropped, and
 * goes out when the window ends. The window is the person's own when they set one (kept by the
 * app that owns the push, which answers the `zdz_quiet_hours_for_user` filter); otherwise the
 * business's (Zorderz → Settings → Quiet hours, option zdz_quiet_hours, which a pack may write);
 * otherwise Core's: 21:00 to 07:00 in the site's time zone, an overnight window that keeps a
 * night's notices off a sleeping person's phone.
 *
 * A business may turn quiet hours off for everyone who has not chosen their own by saving the
 * same time in both boxes. A window that crosses midnight (start later than end) is read as
 * "from start tonight until end tomorrow morning".
 *
 * The decisions here are pure (in_window, release_at): they take the local time and the
 * window, so they can be checked without a clock or a database. The messaging app asks this
 * class when it is present; the push it holds is the push the alert router hands it.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Quiet_Hours {

	/** The business's own window: [ 'start' => 'HH:MM', 'end' => 'HH:MM' ]. */
	const OPT = 'zdz_quiet_hours';

	/** Core's window when neither the person nor the business chose one. */
	const CORE_DEFAULT = array( 'start' => '21:00', 'end' => '07:00' );

	public static function boot(): void {
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_action( 'zdz_core_settings_rows', array( __CLASS__, 'render_setting_row' ) );
	}

	/* ── The window ───────────────────────────────────────────────────────────────── */

	/** A 24-hour "HH:MM", or ''. */
	public static function hm( $v ): string {
		return is_string( $v ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $v ) ? $v : '';
	}

	/**
	 * A window as stored, checked. Returns [ start, end ] for a real window, [] for "no quiet
	 * hours" (both times the same), or null when it is not a window at all (missing, malformed).
	 */
	public static function normalize( $w ): ?array {
		if ( ! is_array( $w ) ) {
			return null;
		}
		$s = self::hm( $w['start'] ?? '' );
		$e = self::hm( $w['end'] ?? '' );
		if ( '' === $s || '' === $e ) {
			return null;
		}
		return $s === $e ? array() : array( 'start' => $s, 'end' => $e );
	}

	/** The business's window: its setting, then the `zdz_quiet_hours_default` filter, then Core's. */
	public static function business_default(): array {
		$w = self::normalize( function_exists( 'get_option' ) ? get_option( self::OPT, null ) : null );
		if ( function_exists( 'apply_filters' ) ) {
			$f = self::normalize( apply_filters( 'zdz_quiet_hours_default', $w ?? self::CORE_DEFAULT ) );
			$w = null !== $f ? $f : $w;
		}
		return null !== $w ? $w : self::CORE_DEFAULT;
	}

	/** This person's window: their own (from the app that keeps it), else the business's. [] holds nothing. */
	public static function for_user( int $uid ): array {
		if ( $uid > 0 && function_exists( 'apply_filters' ) ) {
			$own = self::normalize( apply_filters( 'zdz_quiet_hours_for_user', null, $uid ) );
			if ( null !== $own ) {
				return $own;
			}
		}
		return self::business_default();
	}

	/* ── The decisions (pure) ─────────────────────────────────────────────────────── */

	/** Is $local inside the window? A window with no start/end holds nothing. */
	public static function in_window( \DateTimeImmutable $local, array $w ): bool {
		return null !== self::window_end( $local, $w );
	}

	/**
	 * When something that arrives at $local may go out: $local itself outside quiet hours,
	 * else the moment the window ends (today's end, or tomorrow's for a window that crosses
	 * midnight and was entered before midnight). Same time zone as $local.
	 */
	public static function release_at( \DateTimeImmutable $local, array $w ): \DateTimeImmutable {
		$end = self::window_end( $local, $w );
		return null === $end ? $local : $end;
	}

	/** The end of the window $local is in, or null when it is in none. */
	private static function window_end( \DateTimeImmutable $local, array $w ): ?\DateTimeImmutable {
		$s = self::hm( $w['start'] ?? '' );
		$e = self::hm( $w['end'] ?? '' );
		if ( '' === $s || '' === $e || $s === $e ) {
			return null;
		}
		list( $sh, $sm ) = array_map( 'intval', explode( ':', $s ) );
		list( $eh, $em ) = array_map( 'intval', explode( ':', $e ) );
		$start = $local->setTime( $sh, $sm, 0 );
		$end   = $local->setTime( $eh, $em, 0 );
		if ( $start < $end ) {
			// A window within one day (13:00 to 14:00).
			return ( $local >= $start && $local < $end ) ? $end : null;
		}
		// A window across midnight (21:00 to 07:00).
		if ( $local >= $start ) {
			return $end->modify( '+1 day' );   // after the start, before midnight: tomorrow's end
		}
		if ( $local < $end ) {
			return $end;                        // after midnight, before the end: today's end
		}
		return null;
	}

	/** For a person, now (or $now): when may a push go out, as UTC 'Y-m-d H:i:s'. */
	public static function release_at_utc( int $uid, ?int $now = null ): string {
		$tz    = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		$local = ( new \DateTimeImmutable( '@' . ( null === $now ? time() : $now ) ) )->setTimezone( $tz );
		return self::release_at( $local, self::for_user( $uid ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/* ── Zorderz → Settings ───────────────────────────────────────────────────────── */

	public static function register_setting(): void {
		register_setting( 'zdz_core_settings_group', self::OPT, array(
			'type'              => 'array',
			'default'           => null,
			'sanitize_callback' => array( __CLASS__, 'sanitize_setting' ),
		) );
	}

	/** Two valid times, or nothing stored (Core's window then applies). */
	public static function sanitize_setting( $v ) {
		$w = is_array( $v ) ? array( 'start' => (string) ( $v['start'] ?? '' ), 'end' => (string) ( $v['end'] ?? '' ) ) : null;
		return null !== self::normalize( $w ) ? $w : null;
	}

	public static function render_setting_row(): void {
		$raw = get_option( self::OPT, null );
		$w   = null !== self::normalize( $raw ) ? $raw : self::business_default();
		$s   = self::hm( $w['start'] ?? '' );
		$e   = self::hm( $w['end'] ?? '' );
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Quiet hours', 'zorderz' ); ?></th>
			<td>
				<label><?php esc_html_e( 'From', 'zorderz' ); ?>
					<input type="time" name="<?php echo esc_attr( self::OPT ); ?>[start]" value="<?php echo esc_attr( $s ); ?>" /></label>
				<label><?php esc_html_e( 'until', 'zorderz' ); ?>
					<input type="time" name="<?php echo esc_attr( self::OPT ); ?>[end]" value="<?php echo esc_attr( $e ); ?>" /></label>
				<p class="description">
					<?php esc_html_e( 'Phone notifications that arrive in these hours wait until they end (nothing is dropped). Each person can choose their own; this is for everyone who has not. The same time in both boxes turns quiet hours off.', 'zorderz' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}
}
