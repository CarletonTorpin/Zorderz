<?php
/**
 * ZL_Counts — the registry verb `lead.counts`: the home screen's lead figures, from the app that records contacts.
 *
 * Leads records contact_status / contacted_at (stamped once, in UTC) and assigned_user_id on every outreach lead.
 * This verb answers from those columns only; it never calls the CRM.
 *
 * What these leads are: re-engagement leads the lead generator builds from past billing customers and hands to a
 * salesperson to call or e-mail — NOT the CRM's inbound leads (web forms, calls). Which "Contacted" a home tile
 * should mean is the business's call; the definitions below say exactly what each number counts.
 *
 * The counts. Test batches are left out everywhere. Two kinds, kept apart so a past month's answer never carries
 * today's backlog under that month's label:
 *
 *   In the month asked for (the business's time zone):
 *     mine.contacted                my assigned leads marked contacted in the month;
 *     company.contacted             every lead marked contacted in the month.
 *   Right now, whatever month is asked for:
 *     mine_now.to_contact           my assigned leads still pending (not contacted, not skipped);
 *     mine_now.new_today            of those, assigned to me since midnight today;
 *     company_now.to_contact        every pending lead;
 *     company_now.unassigned        pending leads assigned to nobody;
 *     company_now.contacted_undated leads marked contacted before contacted_at existed (they cannot be put in a month).
 *
 * Who asks, and what they get. "Lead admin" is the app's own rule, ZL_Lead_Assignment::is_lead_admin(), never
 * re-derived here:
 *
 *   | Who asks                          | Gets                                                             |
 *   |-----------------------------------|------------------------------------------------------------------|
 *   | anyone with an app tier           | `mine` and `mine_now`: counts of their own assigned leads        |
 *   | + a lead admin                    | also `company` and `company_now`                                 |
 *   | the shared device                 | refused (no per-person view on the shared device)                |
 *   | signed out                        | refused by the registry                                          |
 *   | anyone                            | counts only: never a name, phone, e-mail, note or amount         |
 *
 * Freshness: the app writes these columns as people act, so `read_at` (when the table was read) is the data's age.
 * A failed read is an error (`success: false`), never zeros.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZL_Counts {

	const VERB = 'lead.counts';

	/** How far back a month may be asked for. */
	const MAX_MONTHS_BACK = 36;

	/** Injected in tests: "now" as a unix time. */
	public static $now = null;

	public static function init() {
		add_filter( 'zdz_register_capabilities', array( __CLASS__, 'register' ) );
	}

	public static function register( $caps ) {
		if ( ! is_array( $caps ) ) {
			$caps = array();
		}
		$caps[ self::VERB ] = array(
			'callable'   => array( __CLASS__, 'from_capability' ),
			'descriptor' => array(
				'verb'          => self::VERB,
				'source'        => 'leads',
				'app'           => 'leads',
				'min_tier'      => 'viewer',
				'kiosk'         => false,
				'kiosk_bounded' => false,
				'side_effect'   => false,
				'confirm'       => false,
				// "My leads to call" belongs on the home screen of anyone leads are assigned to, Leads app or not.
				'needs_app'     => false,
				'summary'       => 'Leads outreach counts: contacted in one month, and right now my leads to contact and new today; lead admins also get the business\'s. Counts only, test batches left out; not the shared device.',
			),
		);
		return $caps;
	}

	/**
	 * The verb. $args: month (optional, 'Y-m'; default this month in the site's time zone).
	 * Identity keys (user_id, tier, is_kiosk) are stamped by the registry and are the only ones trusted.
	 */
	public static function from_capability( array $args ): array {
		$uid  = (int) ( $args['user_id'] ?? 0 );
		$tier = (string) ( $args['tier'] ?? '' );
		if ( $uid <= 0 ) {
			return array( 'success' => false, 'denied' => true, 'reason' => 'signed_out', 'verb' => self::VERB );
		}
		$kiosk = ! empty( $args['is_kiosk'] ) || 'kiosk' === $tier
			|| ( class_exists( 'ZL_Lead_Assignment' ) && ZL_Lead_Assignment::is_kiosk( $uid ) );
		if ( $kiosk ) {
			// The registry refuses the shared device first; this is the app's own check, in case of a direct call.
			return array( 'success' => false, 'denied' => true, 'reason' => 'kiosk', 'verb' => self::VERB );
		}
		$admin = class_exists( 'ZL_Lead_Assignment' ) ? ZL_Lead_Assignment::is_lead_admin( $uid ) : ( 'admin' === $tier );

		$win = self::month_window( (string) ( $args['month'] ?? '' ) );
		if ( isset( $win['error'] ) ) {
			return array( 'success' => false, 'error' => $win['error'], 'verb' => self::VERB );
		}

		global $wpdb;
		$l   = $wpdb->prefix . 'zl_leads';
		$b   = $wpdb->prefix . 'zl_batches';
		$in  = 'l.contacted_at >= %s AND l.contacted_at < %s';
		$sql = "SELECT COUNT(*) AS n,
			SUM(CASE WHEN l.assigned_user_id = %d AND l.contact_status = 'pending' THEN 1 ELSE 0 END) AS mine_to_contact,
			SUM(CASE WHEN l.assigned_user_id = %d AND l.contact_status = 'pending' AND l.assigned_at >= %s THEN 1 ELSE 0 END) AS mine_new_today,
			SUM(CASE WHEN l.assigned_user_id = %d AND l.contact_status = 'contacted' AND {$in} THEN 1 ELSE 0 END) AS mine_contacted,
			SUM(CASE WHEN l.contact_status = 'contacted' AND {$in} THEN 1 ELSE 0 END) AS company_contacted,
			SUM(CASE WHEN l.contact_status = 'pending' THEN 1 ELSE 0 END) AS company_to_contact,
			SUM(CASE WHEN l.contact_status = 'pending' AND ( l.assigned_user_id IS NULL OR l.assigned_user_id = 0 ) THEN 1 ELSE 0 END) AS company_unassigned,
			SUM(CASE WHEN l.contact_status = 'contacted' AND l.contacted_at IS NULL THEN 1 ELSE 0 END) AS company_contacted_undated
			FROM {$l} l INNER JOIN {$b} b ON b.id = l.batch_id
			WHERE b.is_test = 0";
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $uid, $uid, $win['today_utc'], $uid, $win['from_utc'], $win['to_utc'], $win['from_utc'], $win['to_utc'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the fixed statement above: {$l} and {$b} are $wpdb->prefix plus fixed names, {$in} a fixed fragment with placeholders
		if ( ! is_array( $row ) || ! isset( $row['n'] ) || '' !== (string) $wpdb->last_error ) {
			error_log( 'ZL_Counts: lead.counts could not read the leads table.' );
			return array( 'success' => false, 'error' => 'The lead counts could not be read just now.', 'verb' => self::VERB );
		}

		$out = array(
			'success'     => true,
			'verb'        => self::VERB,
			'shaped_for'  => $admin ? 'lead_admin' : 'person',
			'month'       => $win['month'],
			'window'      => array( 'from' => $win['from'], 'to' => $win['to'], 'tz' => $win['tz'] ),
			'mine'        => array(
				'contacted' => (int) $row['mine_contacted'],
			),
			'mine_now'    => array(
				'to_contact' => (int) $row['mine_to_contact'],
				'new_today'  => (int) $row['mine_new_today'],
			),
			'read_at'     => self::now(),
			'definitions' => self::definitions( $admin ),
			'source'      => 'leads',
		);
		if ( $admin ) {
			$out['company']     = array(
				'contacted' => (int) $row['company_contacted'],
			);
			$out['company_now'] = array(
				'to_contact'        => (int) $row['company_to_contact'],
				'unassigned'        => (int) $row['company_unassigned'],
				'contacted_undated' => (int) $row['company_contacted_undated'],
			);
		}
		return $out;
	}

	/** One sentence per count, for a tile's "what this counts" line and the tap-through question. */
	public static function definitions( bool $admin = false ): array {
		$d = array(
			'mine.contacted'      => 'Your assigned outreach leads marked Contacted in this month; test batches left out.',
			'mine_now.to_contact' => 'Right now: outreach leads assigned to you that are not contacted or skipped yet.',
			'mine_now.new_today'  => 'Right now: of those, leads assigned to you since midnight today.',
		);
		if ( $admin ) {
			$d += array(
				'company.contacted'             => 'Outreach leads marked Contacted in this month, by anyone. These are past customers the lead generator picked, not the CRM\'s inbound leads.',
				'company_now.to_contact'        => 'Right now: outreach leads not contacted or skipped yet.',
				'company_now.unassigned'        => 'Right now: of those, leads assigned to nobody.',
				'company_now.contacted_undated' => 'Leads marked Contacted before the contact time was recorded; they cannot be placed in a month.',
			);
		}
		return $d;
	}

	/** The business's time zone: the Business Profile's, else the site's, else UTC. */
	public static function timezone(): DateTimeZone {
		$name = class_exists( 'ZDZ_Business_Profile' ) ? (string) ZDZ_Business_Profile::get( 'locale.timezone', '' ) : '';
		if ( '' !== $name ) {
			try {
				return new DateTimeZone( $name );
			} catch ( \Exception $e ) {
				unset( $e ); // an unknown name falls through to the site's zone
			}
		}
		return function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	}

	/**
	 * A calendar month in the business's time zone, with its edges in UTC (contacted_at and assigned_at are stored in UTC).
	 * '' = this month. Refuses a malformed month, a future month and one more than MAX_MONTHS_BACK months back.
	 */
	public static function month_window( string $month ): array {
		$tz  = self::timezone();
		$utc = new DateTimeZone( 'UTC' );
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
		$today = $now->setTime( 0, 0, 0 );
		return array(
			'month'     => $month,
			'from'      => $start->format( 'Y-m-d' ),
			'to'        => $next->modify( '-1 day' )->format( 'Y-m-d' ),
			'tz'        => $tz->getName(),
			'from_utc'  => $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'to_utc'    => $next->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'today_utc' => $today->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
		);
	}

	protected static function now(): int {
		return null !== self::$now ? (int) self::$now : time();
	}
}
