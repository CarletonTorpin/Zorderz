<?php
/**
 * ZJOB_Upcoming — "what work is coming up, and what was sold for it?", published as the read verb `jobs.upcoming_work`
 * for Prep's Upcoming production and its prep sheets.
 *
 * WHAT IT ANSWERS. The sold Projects (at least one job not cancelled; not finished) whose install visit falls on a day
 * in the window: the same visit My next uses (a live job's scheduled time, or the Project's linked appointment; the
 * next one coming, else the latest passed). Days are the business's local days. With `unscheduled`, also the sold
 * Projects with no visit yet made within IDLE_DAYS, each with install_day ''.
 *
 * INPUT   from, to      'Y-m-d' local, to >= from, at most MAX_DAYS apart (default today and the next 30 days)
 *         kinds         optional job kinds; a Project with any of them is kept
 *         unscheduled   optional bool
 *
 * WHAT IT RETURNS, BY WHO ASKS
 *   everyone the verb allows (staff tier and up, never the shared device, never a role Prep's settings hide
 *   upcoming production from):
 *       key 'project:<id>', jobs ['job:<id>', ...], number (the estimate's document number), kinds, install_day, city,
 *       installer (initials), lines [{name, description, qty}] with every amount taken out of the words
 *   + an asker who may open the Project: street (the street line) and cutter (the confirmed preparer's first name)
 *   never: a customer's name, phone, email or notes, and never money.
 *
 * A failed read is an error (success false), never "nothing coming up".
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZJOB_Upcoming {

	const VERB      = 'jobs.upcoming_work';
	const MAX_DAYS  = 30;
	const MAX_ITEMS = 150;
	const MAX_LINES = 40;
	const IDLE_DAYS = 45;

	/** Injected in tests: "now" (unix). */
	public static $now = null;

	public static function init(): void {
		add_filter( 'zdz_register_capabilities', array( __CLASS__, 'register' ) );
	}

	public static function register( $caps ) {
		$caps               = is_array( $caps ) ? $caps : array();
		$caps[ self::VERB ] = self::descriptor();
		return $caps;
	}

	public static function descriptor(): array {
		return array(
			'verb'          => self::VERB,
			'provider'      => 'zjob',
			'app'           => defined( 'ZJOB_APP_ID' ) ? ZJOB_APP_ID : 'jobs',
			'tier'          => 'staff',  // the shop, the office, sales, administrators; never a tech or the shared device
			'needs_app'     => false,    // asked by Prep for its own screen
			'callback'      => array( __CLASS__, 'from_capability' ),
			'kiosk'         => false,
			'kiosk_bounded' => false,
			'side_effect'   => false,
			'summary'       => 'The sold work whose install visit falls in a window of days (and, if asked, sold work not yet booked): kinds, day, town, installer initials and what was sold, with no amounts. Never a customer name or money.',
		);
	}

	private static function now(): int {
		return null !== self::$now ? (int) self::$now : time();
	}

	/**
	 * Amounts out of a line's words: a currency sign and a number (with an optional "/unit" or "per unit"), a bare
	 * price with cents and a rate, a rate after "@", a time rate, and a money word followed by a figure written as
	 * money. Sizes and counts stay ("36x60", "Total 6 pieces", "Track 120.00 long"). PURE.
	 */
	public static function strip_amounts( string $text ): string {
		$units = '(?:ft|foot|feet|linear\s*(?:ft|foot|feet)|lin\.?\s*ft|sq\.?\s?ft|sf|lf|m|m2|sqm|ea\.?|each|unit|pc|piece|roll|hr|hrs|hour|day|item)';
		$unit  = '(?:\s*(?:\/|per)\s*' . $units . ')?';
		$cur   = '(?:[A-Z]{1,3})?[\$\x{20AC}\x{00A3}\x{00A5}\x{20B9}]';
		$text  = (string) preg_replace( '/\s*[@=]?\s*' . $cur . '\s*(?:\d[\d,.\s]*\d|\d|\.\d+)\s*[kK]?\b' . $unit . '/u', ' ', $text );
		$text  = (string) preg_replace( '/\s*[@=]?\s*\b\d[\d,]*\.\d{2}\b(?!\s*["\x{201D}])\s*(?:\/|per)\s*' . $units . '\b/iu', ' ', $text );
		$text  = (string) preg_replace( '/\s*@\s*\d[\d,]*(?:\.\d+)?\s*(?:\/|per)\s*' . $units . '\b/iu', ' ', $text );
		$text  = (string) preg_replace( '/\s*\b\d[\d,]*(?:\.\d+)?\s*(?:\/|per)\s*(?:hr|hrs|hour)\b/iu', ' ', $text );
		$text  = (string) preg_replace( '/\b(total|sub-?total|deposit|down\s*payment|price|cost|balance(?:\s+due)?|amount(?:\s+due)?|paid|tax|discount|charge|fee)\b(\s*:?)\s*(?:\d{1,3}(?:,\d{3})+(?:\.\d{2})?|\d+\.\d{2})(?![\d.,])(?!\s*(?:["\x{201D}\'\x{2019}x\x{00D7}%-]|in\b|inch|ft\b|feet|mm\b|cm\b|m\b|long\b|wide\b|high\b|tall\b|sq\b|sf\b|lf\b|pcs\b|pieces?\b|units?\b))(?:\s*(?:ea\.?|each)\b)?/iu', '$1$2', $text );
		$text  = (string) preg_replace( '/\s+([,.;:])(?=\s|$)/', '$1', $text );
		return trim( (string) preg_replace( '/[ \t]{2,}/', ' ', $text ), " \t,;" );
	}

	/** One line as a prep sheet may carry it: name, description, quantity; never an amount. PURE. */
	public static function strip_line( array $ln ): array {
		$qty = $ln['qty'] ?? ( $ln['quantity'] ?? 0 );
		$qty = is_numeric( $qty ) ? (float) $qty : 0.0;
		return array(
			'name'        => self::strip_amounts( trim( (string) ( $ln['name'] ?? ( $ln['desc'] ?? '' ) ) ) ),
			'description' => self::strip_amounts( trim( (string) ( $ln['description'] ?? ( $ln['sub'] ?? '' ) ) ) ),
			'qty'         => ( abs( $qty - round( $qty ) ) < 0.001 ) ? (int) round( $qty ) : round( $qty, 2 ),
		);
	}

	/** The street line of an address (its first part); '' when there is no comma to tell it apart. PURE. */
	public static function street( string $address ): string {
		$parts = array_values( array_filter( array_map( 'trim', explode( ',', $address ) ), 'strlen' ) );
		return count( $parts ) > 1 ? (string) $parts[0] : '';
	}

	/** A person's initials from their display name (two letters at most). */
	private static function initials( int $uid ): string {
		$u = $uid > 0 && function_exists( 'get_userdata' ) ? get_userdata( $uid ) : null;
		if ( ! $u ) {
			return '';
		}
		$out = '';
		foreach ( preg_split( '/\s+/', trim( (string) $u->display_name ) ) as $w ) {
			if ( '' !== $w && strlen( $out ) < 2 ) {
				$out .= strtoupper( substr( $w, 0, 1 ) );
			}
		}
		return $out;
	}

	/**
	 * Which Projects are in: an install day within [from, to], or (when asked) no visit and made within IDLE_DAYS; kept
	 * when no kinds were asked, or any of its kinds was. PURE.
	 *
	 * @param array  $p    { install_day: 'Y-m-d'|'' , kinds: string[], created: 'Y-m-d' }
	 * @param string $from 'Y-m-d'
	 * @param string $to   'Y-m-d'
	 */
	public static function in_window( array $p, string $from, string $to, array $kinds, bool $unscheduled, string $idle_since ): bool {
		if ( $kinds && ! array_intersect( (array) ( $p['kinds'] ?? array() ), $kinds ) ) {
			return false;
		}
		$day = (string) ( $p['install_day'] ?? '' );
		if ( '' !== $day ) {
			return $day >= $from && $day <= $to;
		}
		return $unscheduled && (string) ( $p['created'] ?? '' ) >= $idle_since;
	}

	/** Is this person in a role Prep's settings hide upcoming production from (asked directly, the same answer)? */
	public static function hidden_from( int $uid ): bool {
		if ( ! class_exists( 'ZPREP_Upcoming' ) || ! function_exists( 'get_userdata' ) ) {
			return false;
		}
		$u = get_userdata( $uid );
		return (bool) ( $u && array_intersect( ZPREP_Upcoming::hidden_roles( get_option( ZPREP_Upcoming::HIDDEN_OPT, array() ) ), (array) ( $u->roles ?? array() ) ) );
	}

	public static function from_capability( array $args ): array {
		$uid   = (int) ( $args['user_id'] ?? 0 );
		$kiosk = ! empty( $args['is_kiosk'] ) || 'kiosk' === (string) ( $args['tier'] ?? '' ) || ( $uid > 0 && ! ZJOB_My_Next::is_person( $uid ) );
		if ( $uid <= 0 ) {
			return array( 'success' => false, 'denied' => true, 'reason' => 'signed_out', 'verb' => self::VERB );
		}
		if ( $kiosk ) {
			return array( 'success' => false, 'denied' => true, 'reason' => 'kiosk', 'verb' => self::VERB );
		}
		if ( self::hidden_from( $uid ) ) {
			return array( 'success' => false, 'denied' => true, 'reason' => 'role', 'verb' => self::VERB );
		}
		$tz    = ZJOB_My_Counts::tz();
		$now   = self::now();
		$today = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $tz )->format( 'Y-m-d' );
		$from  = ZJOB_My_Counts::day( $args['from'] ?? '' ) ?? $today;
		$to    = ZJOB_My_Counts::day( $args['to'] ?? '' ) ?? ( new DateTimeImmutable( $from, $tz ) )->modify( '+' . self::MAX_DAYS . ' days' )->format( 'Y-m-d' );
		if ( $to < $from ) {
			return array( 'success' => false, 'error' => 'The window must end on or after its first day.', 'verb' => self::VERB );
		}
		if ( (int) ( new DateTimeImmutable( $from ) )->diff( new DateTimeImmutable( $to ) )->days > self::MAX_DAYS ) {
			return array( 'success' => false, 'error' => 'The window is at most ' . self::MAX_DAYS . ' days.', 'verb' => self::VERB );
		}
		$kinds = array_values( array_filter( array_map( static function ( $k ) {
			return is_scalar( $k ) ? strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) ) : '';
		}, (array) ( $args['kinds'] ?? array() ) ) ) );
		$idle_since = ( new DateTimeImmutable( '@' . ( $now - self::IDLE_DAYS * 86400 ) ) )->setTimezone( $tz )->format( 'Y-m-d' );
		try {
			$data  = ZJOB_My_Next::read( $now );
			$items = array();
			foreach ( $data['projects'] as $pid => $row ) {
				$jobs = $data['jobs'][ $pid ] ?? array();
				if ( ! ZJOB_My_Next::is_sold( $jobs ) || ZJOB_My_Next::is_installed( (string) $row['state'], $jobs ) ) {
					continue;
				}
				$p            = self::facts( (string) $pid, $row, $jobs, $data, $tz );
				$p['created'] = ZJOB_My_Counts::local_day( (string) ( $row['created_at'] ?? '' ), $tz );
				if ( ! self::in_window( $p, $from, $to, $kinds, ! empty( $args['unscheduled'] ), $idle_since ) ) {
					continue;
				}
				$items[] = self::item( (string) $pid, $p, $row, $jobs, $data, $uid );
				if ( count( $items ) >= self::MAX_ITEMS ) {
					break;
				}
			}
		} catch ( \Throwable $e ) {
			error_log( 'Zorderz Jobs ' . self::VERB . ': ' . $e->getMessage() );
			return array( 'success' => false, 'error' => 'Projects could not be read just now.', 'verb' => self::VERB );
		}
		usort( $items, static function ( $a, $b ) {
			if ( $a['install_day'] !== $b['install_day'] ) {
				if ( '' === $a['install_day'] ) {
					return 1;
				}
				if ( '' === $b['install_day'] ) {
					return -1;
				}
				return strcmp( $a['install_day'], $b['install_day'] );
			}
			return strcmp( $a['key'], $b['key'] );
		} );
		return array(
			'success' => true,
			'verb'    => self::VERB,
			'window'  => array( 'from' => $from, 'to' => $to, 'today' => $today ),
			'items'   => $items,
			'read_at' => $now,
		);
	}

	/** The facts in_window() reads: the install day (business local) and the kinds. */
	private static function facts( string $pid, array $row, array $jobs, array $data, DateTimeZone $tz ): array {
		$cands = array();
		foreach ( $jobs as $j ) {
			if ( in_array( (string) ( $j['status'] ?? '' ), ZJOB_My_Next::LIVE, true ) && ! empty( $j['scheduled_start_utc'] ) ) {
				$aid = (int) ( $j['scheduled_appt_id'] ?? 0 );
				if ( $aid > 0 && isset( $data['appts'][ $aid ] ) && empty( $data['appts'][ $aid ]['live'] ) ) {
					continue;
				}
				$cands[] = array( 'start_utc' => (string) $j['scheduled_start_utc'], 'people' => array( (int) ( $j['assigned_user_id'] ?? 0 ) ), 'appt' => $aid );
			}
		}
		$link = (int) ( $data['links'][ $pid ] ?? 0 );
		if ( $link > 0 && ! empty( $data['appts'][ $link ]['live'] ) ) {
			$cands[] = array( 'start_utc' => (string) $data['appts'][ $link ]['start_utc'], 'people' => array( (int) $data['appts'][ $link ]['owner'] ), 'appt' => $link );
		}
		$now   = gmdate( 'Y-m-d H:i:s', self::now() );
		$floor = gmdate( 'Y-m-d H:i:s', self::now() - ZJOB_My_Next::VISIT_DAYS * 86400 );
		$visit = ZJOB_My_Next::pick_visit( $cands, $now, $floor );
		$kinds = array();
		foreach ( $jobs as $j ) {
			if ( 'cancelled' !== (string) ( $j['status'] ?? '' ) && '' !== (string) ( $j['component'] ?? '' ) ) {
				$kinds[ (string) $j['component'] ] = true;
			}
		}
		return array(
			'install_day' => $visit ? ZJOB_My_Counts::local_day( (string) $visit['start_utc'], $tz ) : '',
			'visit'       => $visit,
			'kinds'       => array_keys( $kinds ),
		);
	}

	private static function item( string $pid, array $p, array $row, array $jobs, array $data, int $uid ): array {
		$first = $jobs[0] ?? array();
		$lines = array();
		if ( class_exists( 'ZJOB_Project_Orders' ) ) {
			foreach ( array_slice( ZJOB_Project_Orders::estimate_lines( $pid ), 0, self::MAX_LINES ) as $ln ) {
				$s = self::strip_line( $ln );
				if ( '' !== $s['name'] || '' !== $s['description'] ) {
					$lines[] = $s;
				}
			}
		}
		$eids = array_values( array_unique( array_filter( array_map( static function ( $j ) {
			return (int) ( $j['estimate_id'] ?? 0 );
		}, $jobs ) ) ) );
		$installer = (array) ( $p['visit']['people'] ?? array() );
		$out       = array(
			'key'         => 'project:' . $pid,
			'jobs'        => array_values( array_map( static function ( $j ) {
				return 'job:' . (int) $j['id'];
			}, array_filter( $jobs, static function ( $j ) {
				return 'cancelled' !== (string) ( $j['status'] ?? '' );
			} ) ) ),
			'number'      => self::estimate_number( $eids ),
			'kinds'       => $p['kinds'],
			'install_day' => (string) $p['install_day'],
			'city'        => ZJOB_My_Next::city_of( (string) ( $first['customer_address'] ?? '' ) ),
			'installer'   => $installer ? self::initials( (int) $installer[0] ) : '',
			'lines'       => $lines,
		);
		$project = array( 'id' => $pid, 'created_by' => (int) ( $data['creators'][ $pid ] ?? 0 ), 'confirmed' => (array) ( $data['people'][ $pid ] ?? array() ), 'sp_code' => '' );
		// The street and the preparer only for someone who may open the Project (the read gate).
		if ( class_exists( 'Zjob_Project_Visibility' ) && Zjob_Project_Visibility::may_view_project( $uid, $project ) ) {
			$out['street'] = self::street( (string) ( $first['customer_address'] ?? '' ) );
			$cutter        = (int) ( $data['people'][ $pid ]['preparer'] ?? 0 );
			$u             = $cutter > 0 && function_exists( 'get_userdata' ) ? get_userdata( $cutter ) : null;
			$out['cutter'] = $u ? (string) strtok( (string) $u->display_name, ' ' ) : '';
		}
		return $out;
	}

	private static function estimate_number( array $ids ): string {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids || ! isset( $wpdb ) || ! class_exists( 'ZEST_DB' ) || ! is_callable( array( 'ZEST_DB', 'estimates_table' ) ) ) {
			return '';
		}
		// Only integers are inlined.
		$n = (string) $wpdb->get_var( 'SELECT doc_number FROM ' . ZEST_DB::estimates_table() . ' WHERE id IN (' . implode( ',', $ids ) . ") AND doc_number <> '' ORDER BY id ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (string) preg_replace( '/[^A-Za-z0-9\-]/', '', $n );
	}
}
