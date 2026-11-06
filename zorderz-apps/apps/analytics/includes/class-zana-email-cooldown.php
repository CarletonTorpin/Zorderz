<?php
/**
 * ZANA_Email_Cooldown — a rest period between outreach emails to the same customer.
 *
 * A business may choose to wait a number of days between one solicitation email to a customer and the next
 * (option `zana_email_cooldown_days`, filter of the same name; Zorderz → Settings). Core ships it OFF (0 days): how long
 * to wait, or whether to wait at all, is the business's own rule. When it is on and the person asks the chat to write
 * an email, the chat is told, for each customer the email is for, whether an outreach email may go now:
 *
 *   - the most recent contact inside the period was an OUTREACH (solicitation)          → the rest period holds;
 *   - it was INBOUND (the customer reached out), or about WORK in progress (a job, an
 *     appointment, an invoice)                                                            → it does not apply;
 *   - no contact inside the period                                                        → may email.
 *
 * Contacts come from two places: emails the chat itself recorded as sent (`POST …/analytics/email/sent`, one row each,
 * table zana_email_cooldown), and what a CRM connector says through the filter `zana_cooldown_activity`
 * ( array(), $name, $email, $since_ymd ) → [ { date: 'Y-m-d H:i:s', note: string } ]. Each CRM note is read by its
 * words (inbound / work / outreach; an unread note counts as outreach, the careful reading); a note the platform's own
 * contact log wrote (it opens with ZDZ_Contacts::note_opening()) is skipped — it records a contact, it is not one.
 * The words are Core's, and a business may add its own (`zana_cooldown_keywords`: inbound, work, outreach lists).
 *
 * The rest period never blocks anything by itself: the chat is told to suggest a different kind of email, and a
 * job-related email or a reply to the customer's own message is always allowed. Nothing is ever sent from here.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Email_Cooldown {

	const DAYS_OPT  = 'zana_email_cooldown_days';
	const MAX_DAYS  = 365;
	const WORDS_OPT = 'zana_cooldown_keywords';

	/** Core words that mark a contact as the customer's own (inbound). */
	const INBOUND = array( 'customer called', 'they emailed', 'received request', 'customer asked', 'inquiry from', 'they reached out', 'customer contacted' );

	/** Core words that mark a contact as about work in progress. */
	const WORK = array( 'scheduled', 'installation', 'appointment', 'job #', 'invoice #', 'estimate #', 'work order', 'measure', 'follow-up on job', 'regarding their' );

	/** Core words that mark a contact as outreach. */
	const OUTREACH = array( 'following up', 'checking in', 'special offer', 'promotion', 'wanted to reach out', 'touch base', 'new product', 'seasonal' );

	/** Tests set "now" (unix seconds). */
	public static $now = null;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zana_email_cooldown';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta( "CREATE TABLE {$t} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_name VARCHAR(191) NOT NULL DEFAULT '',
			customer_email VARCHAR(191) NOT NULL DEFAULT '',
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			context TEXT NULL,
			sent_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			KEY idx_customer (customer_name),
			KEY idx_email (customer_email),
			KEY idx_sent_at (sent_at)
		) " . $wpdb->get_charset_collate() . ';' );
	}

	/** The rest period in days; 0 = off. */
	public static function days(): int {
		$v = function_exists( 'get_option' ) ? get_option( self::DAYS_OPT, 0 ) : 0;
		$v = is_numeric( $v ) ? (int) $v : 0;
		if ( function_exists( 'apply_filters' ) ) {
			$f = apply_filters( self::DAYS_OPT, $v );
			$v = is_numeric( $f ) ? (int) $f : $v;
		}
		return max( 0, min( self::MAX_DAYS, $v ) );
	}

	public static function sanitize_days( $v ): int {
		return max( 0, min( self::MAX_DAYS, is_numeric( $v ) ? (int) $v : 0 ) );
	}

	private static function now(): int {
		return null !== self::$now ? (int) self::$now : time();
	}

	/** The word lists: Core's, plus what the business added (never fewer). */
	public static function words(): array {
		$add = function_exists( 'get_option' ) ? get_option( self::WORDS_OPT, array() ) : array();
		$add = is_array( $add ) ? $add : array();
		if ( function_exists( 'apply_filters' ) ) {
			$f   = apply_filters( self::WORDS_OPT, $add );
			$add = is_array( $f ) ? $f : $add;
		}
		$out = array( 'inbound' => self::INBOUND, 'work' => self::WORK, 'outreach' => self::OUTREACH );
		foreach ( array_keys( $out ) as $k ) {
			foreach ( (array) ( $add[ $k ] ?? array() ) as $w ) {
				$w = is_string( $w ) ? strtolower( trim( $w ) ) : '';
				if ( '' !== $w && strlen( $w ) <= 60 ) {
					$out[ $k ][] = $w;
				}
			}
			$out[ $k ] = array_values( array_unique( $out[ $k ] ) );
		}
		return $out;
	}

	/**
	 * What one contact note was: 'work', 'inbound', 'outreach', or '' for the contact log's own note (skipped). Work
	 * first (it is never outreach), then inbound; anything unread counts as outreach.
	 */
	public static function classify( string $note ): string {
		$n = strtolower( trim( $note ) );
		if ( class_exists( 'ZDZ_Contacts' ) && method_exists( 'ZDZ_Contacts', 'note_opening' ) ) {
			$open = strtolower( trim( (string) ZDZ_Contacts::note_opening() ) );
			if ( '' !== $open && 0 === strpos( $n, $open ) ) {
				return '';
			}
		}
		if ( '' === $n ) {
			return 'outreach';
		}
		$w = self::words();
		foreach ( array( 'work', 'inbound', 'outreach' ) as $k ) {
			foreach ( $w[ $k ] as $kw ) {
				if ( false !== strpos( $n, $kw ) ) {
					return $k;
				}
			}
		}
		return 'outreach';
	}

	/**
	 * The decision for one customer from their contacts. Pure.
	 *
	 * @param array $contacts [ { date: 'Y-m-d H:i:s', type: outreach|inbound|work, summary } ]
	 * @param int   $days     the rest period
	 * @return array { can_email, reason, days_remaining, last_contact, last_contact_type, last_contact_summary, cooldown_expires }
	 */
	public static function decide( array $contacts, int $days ): array {
		$out = array(
			'can_email'            => true,
			'reason'               => 'no_recent_contact',
			'days_remaining'       => 0,
			'last_contact'         => null,
			'last_contact_type'    => null,
			'last_contact_summary' => null,
			'cooldown_expires'     => null,
		);
		$contacts = array_values( array_filter( $contacts, function ( $c ) {
			return is_array( $c ) && ! empty( $c['date'] ) && false !== strtotime( (string) $c['date'] ) && in_array( $c['type'] ?? '', array( 'outreach', 'inbound', 'work' ), true );
		} ) );
		if ( $days <= 0 || ! $contacts ) {
			if ( $days <= 0 ) {
				$out['reason'] = 'off';
			}
			return $out;
		}
		usort( $contacts, function ( $a, $b ) {
			return strtotime( (string) $b['date'] ) <=> strtotime( (string) $a['date'] );
		} );
		$last = $contacts[0];
		$ts   = (int) strtotime( (string) $last['date'] );
		$out['last_contact']         = gmdate( 'Y-m-d', $ts );
		$out['last_contact_type']    = (string) $last['type'];
		$out['last_contact_summary'] = isset( $last['summary'] ) ? mb_substr( (string) $last['summary'], 0, 120 ) : null;
		$since = (int) floor( ( self::now() - $ts ) / 86400 );
		if ( $since >= $days ) {
			$out['reason'] = 'outside_period';
			return $out;
		}
		if ( 'inbound' === $last['type'] || 'work' === $last['type'] ) {
			$out['reason'] = $last['type'];
			return $out;
		}
		$out['can_email']        = false;
		$out['reason']           = 'resting';
		$out['days_remaining']   = max( 0, $days - $since );
		$out['cooldown_expires'] = gmdate( 'Y-m-d', $ts + $days * 86400 );
		return $out;
	}

	/** One customer's status, from the chat's own record and the CRM connector (if any). */
	public static function check( string $name, string $email = '' ): array {
		$days = self::days();
		if ( $days <= 0 ) {
			return self::decide( array(), 0 );
		}
		$since    = gmdate( 'Y-m-d H:i:s', self::now() - $days * 86400 );
		$contacts = array();
		foreach ( self::own_sends( $name, $email, $since ) as $r ) {
			$contacts[] = array( 'date' => (string) $r['sent_at'], 'type' => 'outreach', 'summary' => (string) ( $r['context'] ?? '' ) );
		}
		$crm = function_exists( 'apply_filters' ) ? apply_filters( 'zana_cooldown_activity', array(), $name, $email, substr( $since, 0, 10 ) ) : array();
		foreach ( (array) $crm as $a ) {
			if ( ! is_array( $a ) || empty( $a['date'] ) ) {
				continue;
			}
			$type = self::classify( (string) ( $a['note'] ?? '' ) );
			if ( '' === $type ) {
				continue;
			}
			$contacts[] = array( 'date' => (string) $a['date'], 'type' => $type, 'summary' => wp_strip_all_tags( (string) ( $a['note'] ?? '' ) ) );
		}
		return self::decide( $contacts, $days );
	}

	/** This app's own record of emails sent to the customer within the period. */
	private static function own_sends( string $name, string $email, string $since ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ( '' === trim( $name ) && '' === trim( $email ) ) ) {
			return array();
		}
		$t = self::table();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT sent_at, context FROM {$t} WHERE ( customer_name = %s OR ( customer_email <> '' AND customer_email = %s ) ) AND sent_at >= %s ORDER BY sent_at DESC LIMIT 20",   // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$name,
			strtolower( $email ),
			$since
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** Record an outreach email the person sent (never the shared device, never Privacy View). */
	public static function log_sent( int $uid, string $name, string $email, string $context = '' ): bool {
		global $wpdb;
		if ( $uid <= 0 || ( '' === trim( $name ) && '' === trim( $email ) ) ) {
			return false;
		}
		if ( class_exists( 'ZANA_LLM_Client' ) && ZANA_LLM_Client::is_shared_device( $uid ) ) {
			return false;
		}
		if ( class_exists( 'ZANA_Pseudonym_Route' ) && ZANA_Pseudonym_Route::is_on( $uid ) ) {
			return false;
		}
		return (bool) $wpdb->insert( self::table(), array(
			'customer_name'  => mb_substr( sanitize_text_field( $name ), 0, 191 ),
			'customer_email' => strtolower( (string) sanitize_email( $email ) ),
			'user_id'        => $uid,
			'context'        => mb_substr( sanitize_textarea_field( $context ), 0, 500 ),
			'sent_at'        => gmdate( 'Y-m-d H:i:s', self::now() ),
		), array( '%s', '%s', '%d', '%s', '%s' ) );
	}

	/**
	 * The instructions block for an email turn: each customer's status and the rule. '' when the rest period is off or
	 * there is nobody to check.
	 *
	 * @param array $results name or address => decide() result
	 */
	public static function prompt_block( array $results ): string {
		$days = self::days();
		if ( $days <= 0 || ! $results ) {
			return '';
		}
		$lines = array( 'OUTREACH REST PERIOD (' . $days . ' days between outreach emails to one customer). Before drafting an outreach email, check:' );
		foreach ( $results as $who => $s ) {
			if ( ! is_array( $s ) ) {
				continue;
			}
			$who     = mb_substr( preg_replace( '/[\x00-\x1F\[\]]+/', ' ', (string) $who ), 0, 120 );
			$lines[] = ! empty( $s['can_email'] )
				? '- ' . $who . ': may email.'
				: '- ' . $who . ': resting, ' . (int) $s['days_remaining'] . ' days left (until ' . $s['cooldown_expires'] . '); last outreach ' . $s['last_contact'] . '.';
		}
		$lines[] = 'If a customer is resting, do NOT draft an outreach (solicitation) email to them. Say when the last outreach went and when the rest period ends, and offer a different kind of email.';
		$lines[] = 'A job-related email, or a reply to something the customer sent, is always allowed.';
		return implode( "\n", $lines );
	}
}
