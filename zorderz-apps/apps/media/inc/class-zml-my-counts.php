<?php
/**
 * ZML_My_Counts — "how many photos did I add this week?" Published as the read verb `media.my_counts` on the
 * platform's capability registry (ZDZ_Capabilities), for a home-screen tile.
 *
 * Media is the app a person opens to see their photos, so it answers; the rows live in the theme's shared media
 * layer, so it asks the theme's ZDZ_User_Media::count_for() rather than reading the table itself.
 *
 * WHAT COUNTS
 * -----------
 * Media records this person added (user_id = theirs, from any app: Camera, Projects, Estimates, the gallery), by the
 * day they were added (created_at, the site's time), in the asked week and on the asked day:
 *   photos      media_type 'photo' or 'image', or any '<app>_photo' (Estimates keeps its photos as 'estimate_photo',
 *               which the theme treats as a photo: ZDZ_User_Media::MARKABLE)
 *   videos      media_type 'video', or any '<app>_video'
 *   other       everything else (sketches, documents)
 * A photo an administrator moved to its rightful owner counts for that owner.
 *
 * WHO ASKS, WHAT THEY GET
 * -----------------------
 *   anyone who holds Media (tier viewer and up), their OWN additions only (the registry stamps who asks)
 *   the shared device, signed out: refused
 *   anyone: counts only - never a file, a title, a place or a customer
 *
 * A failed read is an error (success false), never 0. `read_at` is when the records were read.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZML_My_Counts {

	const VERB     = 'media.my_counts';
	const MAX_DAYS = 14;

	/** Injected in tests: "now" (unix). */
	public static $now = null;

	public static function descriptor(): array {
		return array(
			'verb'          => self::VERB,
			'provider'      => 'zdz-media',
			'app'           => 'zdz-media',          // the Media app a person holds (the widget; its gallery is zdz-media-all)
			'tier'          => 'viewer',
			'needs_app'     => true,
			'callback'      => array( __CLASS__, 'from_capability' ),
			'kiosk'         => false,
			'kiosk_bounded' => false,
			'side_effect'   => false,
			'summary'       => 'How many photos (and videos, and other media) you added in a week and on a day, from any app. Counts only.',
		);
	}

	public static function register( $caps ) {
		if ( ! is_array( $caps ) ) {
			$caps = array();
		}
		$caps[ self::VERB ] = self::descriptor();
		return $caps;
	}

	/** A 'Y-m-d', or null. PURE. */
	public static function day( $v ): ?string {
		$v = is_scalar( $v ) ? trim( (string) $v ) : '';
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}
		return $v;
	}

	private static function tz(): DateTimeZone {
		return function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	}

	/** The day after a 'Y-m-d'. PURE. */
	private static function next_day( string $ymd ): string {
		return ( new DateTimeImmutable( $ymd . ' 12:00:00', new DateTimeZone( 'UTC' ) ) )->modify( '+1 day' )->format( 'Y-m-d' );
	}

	/** [ type => n ] as photos / videos / other. PURE. */
	public static function split( array $by_type ): array {
		$out = array( 'photos' => 0, 'videos' => 0, 'other' => 0 );
		foreach ( $by_type as $t => $n ) {
			$t = (string) $t;
			$k = ( 'photo' === $t || 'image' === $t || '_photo' === substr( $t, -6 ) ) ? 'photos' : ( ( 'video' === $t || '_video' === substr( $t, -6 ) ) ? 'videos' : 'other' );
			$out[ $k ] += (int) $n;
		}
		return $out;
	}

	public static function definitions(): array {
		return array(
			'photos' => 'Photos you added to the app (from Camera, a project, an estimate or the gallery), by the day they were added.',
			'videos' => 'Videos you added, the same way.',
			'other'  => 'Sketches and documents you added.',
		);
	}

	/**
	 * The verb. $args: from, to ('Y-m-d', the week; the tile sends its own, default the seven days ending today) and
	 * today ('Y-m-d', default the site's today). Identity is the registry's stamp only.
	 */
	public static function from_capability( array $args ): array {
		$uid   = (int) ( $args['user_id'] ?? 0 );
		$kiosk = ! empty( $args['is_kiosk'] ) || 'kiosk' === (string) ( $args['tier'] ?? '' )
			|| ( $uid > 0 && class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $uid ) );
		if ( $uid <= 0 ) {
			return array( 'success' => false, 'denied' => true, 'reason' => 'signed_out', 'verb' => self::VERB );
		}
		if ( $kiosk ) {
			return array( 'success' => false, 'denied' => true, 'reason' => 'kiosk', 'verb' => self::VERB );
		}
		if ( ! class_exists( 'ZDZ_User_Media' ) || ! method_exists( 'ZDZ_User_Media', 'count_for' ) ) {
			return array( 'success' => false, 'error' => 'Media needs a newer Zorderz theme to count.', 'verb' => self::VERB );
		}
		$now   = null !== self::$now ? (int) self::$now : time();
		$today = self::day( $args['today'] ?? '' ) ?? ( new DateTimeImmutable( '@' . $now ) )->setTimezone( self::tz() )->format( 'Y-m-d' );
		$to    = self::day( $args['to'] ?? '' ) ?? $today;
		$from  = self::day( $args['from'] ?? '' ) ?? ( new DateTimeImmutable( $to . ' 12:00:00', new DateTimeZone( 'UTC' ) ) )->modify( '-6 days' )->format( 'Y-m-d' );
		if ( $to < $from ) {
			return array( 'success' => false, 'error' => 'The week must end on or after its first day.', 'verb' => self::VERB );
		}
		$span = (int) ( new DateTimeImmutable( $from ) )->diff( new DateTimeImmutable( $to ) )->days;
		if ( $span >= self::MAX_DAYS ) {
			return array( 'success' => false, 'error' => 'The week is at most ' . self::MAX_DAYS . ' days.', 'verb' => self::VERB );
		}
		try {
			$week = ZDZ_User_Media::count_for( $uid, $from . ' 00:00:00', self::next_day( $to ) . ' 00:00:00' );
			$day  = null === $week ? null : ZDZ_User_Media::count_for( $uid, $today . ' 00:00:00', self::next_day( $today ) . ' 00:00:00' );
		} catch ( \Throwable $e ) {
			error_log( 'ZML ' . self::VERB . ': ' . $e->getMessage() );
			$week = null;
			$day  = null;
		}
		if ( ! is_array( $week ) || ! is_array( $day ) ) {
			return array( 'success' => false, 'error' => 'Your photos could not be counted just now.', 'verb' => self::VERB );
		}
		return array(
			'success'     => true,
			'verb'        => self::VERB,
			'week'        => self::split( $week ),
			'today'       => self::split( $day ),
			'window'      => array( 'from' => $from, 'to' => $to, 'today' => $today ),
			'definitions' => self::definitions(),
			'read_at'     => $now,
		);
	}
}
