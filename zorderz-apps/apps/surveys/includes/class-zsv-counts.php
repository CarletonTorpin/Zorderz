<?php
/**
 * Zorderz Surveys — the registry verb `survey.counts`: the survey figures a home screen shows.
 *
 * The app that owns the data answers through a read verb on the capability registry, shaped by
 * who asks. For one calendar month in the site's time zone it counts CUSTOMERS (one e-mail
 * address counts once, however many rows it has; a row with no address counts alone):
 *
 *   put_on_call_list  customers added to a survey batch that month (the follow-up call list);
 *   reached / not_reached / needs_attention / waiting / other
 *                     those customers split by the call outcome on their NEWEST row that month, so
 *                     each customer is in exactly one part and the parts add up:
 *                     reached = satisfied, no_primary_account, follow_up, no_online_review;
 *                     not_reached = left_vm; needs_attention; waiting = no note yet; other = the rest;
 *   emails_sent       customers whose most recent review e-mail went out that month;
 *   reviews_left      customers whose review was recorded that month;
 *   reviews_recorded  every customer whose review is recorded in Surveys, all time.
 *
 * With the CURRENT month it also answers `queue`: the call list as it stands now — customers
 * whose survey lead is still open on this side (a CRM lead, not Won / Lost / Cancelled, no review
 * e-mail sent, no review recorded) inside the CRM sync window — split by the outcome on their
 * newest open row (first_call, call_again, needs_attention, follow_up, other_open, open), and
 * `synced_at`, the oldest look at a customer on the list, so a stale list shows as stale. Each
 * read stands on its own: the call list failing never takes the month's counts down, and the
 * month failing never takes the call list down.
 *
 * Who asks: staff and administrators get the counts for the company — counts only, never a
 * name, an address, a note or an amount. A viewer is refused (tier), the shared device is
 * refused, a signed-out request is refused. The tier is worked out from the user id here, never
 * taken from the arguments. A failed read is an error (`success: false`), never zeros.
 *
 * Time frames: created_at, email_sent_at, review_checked_at and review_date are written in the
 * site's time (current_time('mysql')), so the month edges are compared as site-local strings.
 *
 * @package Zorderz\Surveys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSV_Counts {

	const VERB = 'survey.counts';

	/** How far back a month may be asked for. */
	const MAX_MONTHS_BACK = 36;

	/** Call outcomes that mean the customer was reached. */
	const REACHED = array( 'satisfied', 'no_primary_account', 'follow_up', 'no_online_review' );

	/** Injected in tests: "now" as a unix time. */
	public static $now = null;

	public static function init(): void {
		add_filter( 'zdz_register_capabilities', array( __CLASS__, 'register' ) );
	}

	public static function descriptor(): array {
		return array(
			'verb'          => self::VERB,
			'provider'      => 'surveys',
			'app'           => defined( 'ZSV_APP_ID' ) ? ZSV_APP_ID : 'surveys',
			'tier'          => 'staff',
			'needs_app'     => false, // the home screen asks for everyone it shows figures to
			'callback'      => array( __CLASS__, 'from_capability' ),
			'kiosk'         => false,
			'kiosk_bounded' => false,
			'side_effect'   => false,
			'summary'       => 'Survey counts for one month (the site\'s time zone): customers put on the call list and how the call went, review e-mails sent, reviews recorded; plus all reviews recorded and, for this month, the call list as it stands now. Counts only; staff and admins; not the shared device.',
		);
	}

	public static function register( $caps ) {
		if ( ! is_array( $caps ) ) {
			$caps = array();
		}
		$caps[ self::VERB ] = self::descriptor();
		return $caps;
	}

	/** Who is asking, worked out from the user id; '' when it cannot be. */
	protected static function tier_for( int $uid ): string {
		if ( class_exists( 'ZDZ_Capabilities' ) && method_exists( 'ZDZ_Capabilities', 'tier_of' ) ) {
			return (string) ZDZ_Capabilities::tier_of( $uid );
		}
		if ( class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $uid ) ) {
			return 'kiosk';
		}
		return '';   // no registry: refuse
	}

	/** The verb. $args: user_id (identity), month (optional 'Y-m'; default this month). */
	public static function from_capability( array $args ): array {
		$uid = (int) ( $args['user_id'] ?? 0 );
		if ( $uid <= 0 ) {
			return array( 'success' => false, 'denied' => true, 'reason' => 'signed_out', 'verb' => self::VERB );
		}
		$tier = self::tier_for( $uid );
		if ( 'kiosk' === $tier || ! empty( $args['is_kiosk'] ) ) {
			return array( 'success' => false, 'denied' => true, 'reason' => 'kiosk', 'verb' => self::VERB );
		}
		if ( ! in_array( $tier, array( 'staff', 'admin' ), true ) ) {
			return array( 'success' => false, 'denied' => true, 'reason' => 'tier', 'verb' => self::VERB );
		}
		$win = self::month_window( (string) ( $args['month'] ?? '' ) );
		if ( isset( $win['error'] ) ) {
			return array( 'success' => false, 'error' => $win['error'], 'verb' => self::VERB );
		}
		$out               = self::month_counts( $win );
		$out['shaped_for'] = 'admin' === $tier ? 'admin' : 'staff';
		return self::with_queue( $out, $win['month'] );
	}

	/** One customer = one address; no address = its own row. */
	private static function who(): string {
		return "COALESCE(NULLIF(LOWER(TRIM(email)), ''), CONCAT('#', id))";
	}

	/** The month's counts, or an error answer (never zeros for a failed read). */
	public static function month_counts( array $win ): array {
		global $wpdb;
		$t    = ZSV_DB::leads_table();
		$who  = self::who();
		$from = $win['from_local'];
		$to   = $win['to_local'];
		$st   = "LOWER(TRIM(COALESCE(n.operator_status, '')))";
		$rch  = "'" . implode( "','", self::REACHED ) . "'";

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is the prefixed survey table; $st, $who and $rch are fixed SQL built from literals and the REACHED constant; every value is a placeholder.
		$a = $wpdb->get_row( $wpdb->prepare(
			"SELECT
				COUNT(*) AS put_on_call_list,
				SUM(CASE WHEN {$st} IN ({$rch}) THEN 1 ELSE 0 END) AS reached,
				SUM(CASE WHEN {$st} = 'left_vm' THEN 1 ELSE 0 END) AS not_reached,
				SUM(CASE WHEN {$st} = 'needs_attention' THEN 1 ELSE 0 END) AS needs_attention,
				SUM(CASE WHEN {$st} IN ('', 'not_contacted') THEN 1 ELSE 0 END) AS waiting
			 FROM {$t} n
			 WHERE n.id IN ( SELECT MAX(id) FROM {$t} WHERE created_at >= %s AND created_at < %s GROUP BY {$who} )",
			$from,
			$to
		), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$e1 = (string) $wpdb->last_error;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is the prefixed survey table and $who a fixed SQL expression; every value is a placeholder.
		$b = $wpdb->get_row( $wpdb->prepare(
			"SELECT
				COUNT(DISTINCT CASE WHEN email_sent_at >= %s AND email_sent_at < %s THEN {$who} END) AS emails_sent,
				COUNT(DISTINCT CASE WHEN review_left = 1 AND review_date >= %s AND review_date < %s THEN {$who} END) AS reviews_left,
				COUNT(DISTINCT CASE WHEN review_left = 1 THEN {$who} END) AS reviews_recorded,
				MAX(review_checked_at) AS reviews_checked_at
			 FROM {$t}",
			$from,
			$to,
			$from,
			$to
		), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$e2 = (string) $wpdb->last_error;

		if ( ! is_array( $a ) || ! isset( $a['put_on_call_list'] ) || ! is_array( $b ) || '' !== $e1 || '' !== $e2 ) {
			error_log( 'Zorderz Surveys: survey.counts could not read the survey table.' ); // no customer data in the log
			return array( 'success' => false, 'error' => __( 'The survey counts could not be read just now.', 'zorderz' ), 'verb' => self::VERB, 'month' => $win['month'], 'read_at' => self::now() );
		}
		$c = array(
			'put_on_call_list' => (int) $a['put_on_call_list'],
			'reached'          => (int) $a['reached'],
			'not_reached'      => (int) $a['not_reached'],
			'needs_attention'  => (int) $a['needs_attention'],
			'waiting'          => (int) $a['waiting'],
		);
		$c['other']            = $c['put_on_call_list'] - $c['reached'] - $c['not_reached'] - $c['needs_attention'] - $c['waiting'];
		$c['emails_sent']      = (int) $b['emails_sent'];
		$c['reviews_left']     = (int) $b['reviews_left'];
		$c['reviews_recorded'] = (int) $b['reviews_recorded'];

		return array(
			'success'            => true,
			'verb'               => self::VERB,
			'month'              => $win['month'],
			'window'             => array( 'from' => $win['from'], 'to' => $win['to'], 'tz' => $win['tz'] ),
			'counts'             => $c,
			'read_at'            => self::now(),
			'reviews_checked_at' => self::local_to_unix( (string) ( $b['reviews_checked_at'] ?? '' ) ),
			'definitions'        => self::definitions(),
		);
	}

	/** A survey row still open on this side (what the auto-closer still considers). */
	protected static function open_sql(): string {
		return "crm_lead_id IS NOT NULL AND crm_lead_id <> '' AND email_sent_at IS NULL"
			. ' AND ( review_left = 0 OR review_left IS NULL )'
			. " AND ( crm_status IS NULL OR LOWER(TRIM(crm_status)) NOT IN ( 'won', 'lost', 'cancelled' ) )";
	}

	/** The CRM sync window in days, as the sync reads it (0: no window). */
	public static function sync_window_days(): int {
		$days = (int) get_option( 'zsv_sync_window_days', 60 );
		return max( 0, (int) apply_filters( 'zsv_sync_window_days', $days ) );
	}

	/** The open call list now, one customer once, by the outcome on their newest open row; null on a failed read. */
	public static function queue(): ?array {
		global $wpdb;
		$t    = ZSV_DB::leads_table();
		$who  = self::who();
		$days = self::sync_window_days();
		$open = self::open_sql();
		if ( $days > 0 ) {
			$tz     = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
			$cutoff = ( new DateTimeImmutable( '@' . self::now() ) )->setTimezone( $tz )->modify( '-' . $days . ' days' )->format( 'Y-m-d H:i:s' );
			$open  .= $wpdb->prepare( ' AND created_at >= %s', $cutoff );
		}
		$st = "LOWER(TRIM(COALESCE(n.operator_status, '')))";
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is the prefixed survey table; $st and $who are fixed SQL expressions; $open is fixed SQL plus a cutoff bound with prepare() above.
		$a  = $wpdb->get_row(
			"SELECT
				COUNT(*) AS open_count,
				SUM(CASE WHEN {$st} IN ('', 'not_contacted') THEN 1 ELSE 0 END) AS first_call,
				SUM(CASE WHEN {$st} = 'left_vm' THEN 1 ELSE 0 END) AS call_again,
				SUM(CASE WHEN {$st} = 'needs_attention' THEN 1 ELSE 0 END) AS needs_attention,
				SUM(CASE WHEN {$st} = 'follow_up' THEN 1 ELSE 0 END) AS follow_up,
				MIN(COALESCE(n.crm_synced_at, n.created_at)) AS synced_at
			 FROM {$t} n
			 WHERE n.id IN ( SELECT MAX(id) FROM {$t} WHERE {$open} GROUP BY {$who} )",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $a ) || ! isset( $a['open_count'] ) || '' !== (string) $wpdb->last_error ) {
			error_log( 'Zorderz Surveys: survey.counts could not read the call list.' );
			return null;
		}
		$q = array(
			'open'            => (int) $a['open_count'],
			'first_call'      => (int) $a['first_call'],
			'call_again'      => (int) $a['call_again'],
			'needs_attention' => (int) $a['needs_attention'],
			'follow_up'       => (int) $a['follow_up'],
		);
		$q['other_open']  = $q['open'] - $q['first_call'] - $q['call_again'] - $q['needs_attention'] - $q['follow_up'];
		$q['synced_at']   = self::local_to_unix( (string) ( $a['synced_at'] ?? '' ) );
		$q['window_days'] = $days;
		return $q;
	}

	/** The call list beside an answer for the CURRENT month only. */
	protected static function with_queue( array $out, string $month ): array {
		if ( self::month_window( '' )['month'] !== $month ) {
			return $out;
		}
		$q = self::queue();
		if ( null === $q ) {
			$out['queue_error'] = __( 'The call list could not be read just now.', 'zorderz' );
		} else {
			$out['queue']             = $q;
			$out['queue_definitions'] = self::queue_definitions();
		}
		return $out;
	}

	/** One sentence per count, for a tile's "what this counts" line. */
	public static function definitions(): array {
		return array(
			'put_on_call_list' => __( 'Customers added to a survey batch this month, counted once each.', 'zorderz' ),
			'reached'          => __( 'Of those, customers the follow-up call reached, by their newest call note this month.', 'zorderz' ),
			'not_reached'      => __( 'Of those, customers left a voicemail.', 'zorderz' ),
			'needs_attention'  => __( 'Of those, customers whose call note needs a person to read it.', 'zorderz' ),
			'waiting'          => __( 'Of those, customers with no call note yet.', 'zorderz' ),
			'other'            => __( 'Of those, customers with any other outcome (for example excluded from surveys).', 'zorderz' ),
			'emails_sent'      => __( 'Customers whose most recent review e-mail went out this month, counted once each.', 'zorderz' ),
			'reviews_left'     => __( 'Customers whose review was recorded this month.', 'zorderz' ),
			'reviews_recorded' => __( 'Every customer whose review is recorded in Surveys, all time.', 'zorderz' ),
		);
	}

	public static function queue_definitions(): array {
		return array(
			'open'            => __( 'Customers on the call list now: added within the CRM sync window, their survey lead still open, no review e-mail sent and no review recorded; counted once each.', 'zorderz' ),
			'first_call'      => __( 'Of those, customers with no call note yet.', 'zorderz' ),
			'call_again'      => __( 'Of those, customers left a voicemail and not reached since.', 'zorderz' ),
			'needs_attention' => __( 'Of those, customers whose call note needs a person to read it.', 'zorderz' ),
			'follow_up'       => __( 'Of those, customers reached with an issue that needs following up.', 'zorderz' ),
			'other_open'      => __( 'Of those, customers with any other outcome still open (reached and waiting for the review e-mail, for example).', 'zorderz' ),
			'synced_at'       => __( 'Call notes reach Surveys when it syncs with the CRM; this is the oldest look at a customer on the list.', 'zorderz' ),
			'window_days'     => __( 'Customers added longer ago than this many days are not synced again, so they are not on the list.', 'zorderz' ),
		);
	}

	/**
	 * A calendar month in the site's time zone. '' = this month. Refuses a malformed month, a
	 * future month and one more than MAX_MONTHS_BACK back. Edges as dates and as site-local
	 * datetimes (the columns' frame). Pure given now and the zone.
	 */
	public static function month_window( string $month ): array {
		$tz  = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$now = ( new DateTimeImmutable( '@' . self::now() ) )->setTimezone( $tz );
		$cur = $now->format( 'Y-m' );
		if ( '' === $month ) {
			$month = $cur;
		}
		if ( ! preg_match( '/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $m ) ) {
			return array( 'error' => 'month must be YYYY-MM.' );
		}
		if ( $month > $cur ) {
			return array( 'error' => 'That month has not started yet.' );
		}
		$back = ( (int) substr( $cur, 0, 4 ) - (int) $m[1] ) * 12 + ( (int) substr( $cur, 5, 2 ) - (int) $m[2] );
		if ( $back > self::MAX_MONTHS_BACK ) {
			return array( 'error' => 'That month is too far back.' );
		}
		$start = new DateTimeImmutable( $month . '-01 00:00:00', $tz );
		$next  = $start->modify( 'first day of next month' );
		return array(
			'month'      => $month,
			'from'       => $start->format( 'Y-m-d' ),
			'to'         => $next->modify( '-1 day' )->format( 'Y-m-d' ),
			'tz'         => $tz->getName(),
			'from_local' => $start->format( 'Y-m-d H:i:s' ),
			'to_local'   => $next->format( 'Y-m-d H:i:s' ),
		);
	}

	/** A site-local 'Y-m-d H:i:s' as a unix time, or null. */
	public static function local_to_unix( string $local ): ?int {
		if ( '' === $local || '0000-00-00 00:00:00' === $local ) {
			return null;
		}
		try {
			$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
			return ( new DateTimeImmutable( $local, $tz ) )->getTimestamp();
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	protected static function now(): int {
		return null !== self::$now ? (int) self::$now : time();
	}
}
