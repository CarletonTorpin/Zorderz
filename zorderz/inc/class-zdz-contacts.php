<?php
/**
 * ZDZ_Contacts -- the contact log: every call, voicemail, e-mail, text or meeting with a customer made
 * through the apps, and whether it reached them.
 *
 * "Contacted" means the customer talked with us, in some form. A try that did not reach them (no answer,
 * a voicemail, an e-mail with no reply yet) is a try, shown as "tried, not reached yet"; only a reached
 * contact makes a lead Contacted. Which one-tap answers count as reaching someone is reaching_results():
 * talked and replied by default, adjustable with the `zdz_contact_reaching_results` filter.
 *
 *   ZDZ_Contacts::log( array( 'app' => 'leads', 'channel' => 'call', 'lead_id' => 123 ) )  -> id | 0
 *   ZDZ_Contacts::reached( $id, array( 'result' => 'replied' ) )                          -> bool
 *   ZDZ_Contacts::counts( array( 'month' => '2026-11', 'scope' => 'company' ) )            -> counts only
 *
 *   POST /zorderz/v1/contacts                the page logs a Call / Text / E-mail tap (marked links only; zdz-contacts.js)
 *   POST /zorderz/v1/contacts/<id>/outcome   Talked with them / Left a voicemail / No answer / They replied
 *   GET  /zorderz/v1/contacts/counts         ?month=YYYY-MM&scope=mine|company
 *   verbs contact.log (a write: the chat's Confirm card) and contact.counts (read)
 *
 * WHO: the row carries the person only when ZDZ_Consent::may_record( 'usage_records' ) says so. Otherwise it
 * is written with user_id 0 and nothing else that names them; its CRM note carries no name. The lead, the
 * channel, the outcome and the time are kept either way, so the business's count stays right. While the
 * usage-records card is OFF, may_record() is true for everyone, so nothing changes until the card is on.
 *
 * CRM NOTES: each contact with a lead owes one note on that lead, posted by the theme's shared CRM client
 * (ZDZ_Core_Nutshell::add_note). The queue is the row itself (ns_state), drained by cron, never in the
 * request: owed -> posting (claimed by one UPDATE) -> posted | maybe (the client said nothing: it may have
 * landed) -> read the lead, look for the note's Ref line, then posted or owed again. A call waits up to
 * CALL_WAIT for its one-tap answer, so its note says how it went. A try that is reached after its note went
 * out owes one more note.
 *
 * Never: on the shared device, in Demo Mode, for an app that is not registered, or from a page for an app
 * the person does not have. log() never throws: the app's own answer goes out as it would have. The note
 * text stays in the table and the CRM: never in a count, a tile or a log line.
 *
 * Counting starts on the day the first row is written: nothing is backfilled.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Contacts {

	const DB_VERSION = '1.0.0';
	const OPT_DB     = 'zdz_contacts_db_version';
	/** Site-local day (Y-m-d) the first row was written: counting starts here (nothing is backfilled). */
	const OPT_STARTED = 'zdz_contacts_started';
	const CRON        = 'zdz_contacts_drain';

	const CHANNELS  = array( 'call', 'voicemail', 'email', 'text', 'in_person' );
	const REF_KINDS = array( 'lead', 'job', 'estimate', 'project', 'survey', 'outreach' );
	/** One-tap answers. By default talked / replied reach; voicemail and no_answer stay tries. */
	const RESULTS = array( 'talked', 'voicemail', 'no_answer', 'replied' );
	/** The answers that reach someone, when nothing else is configured (see reaching_results()). */
	const REACHING_RESULTS = array( 'talked', 'replied' );
	/** What a page may log: what the person tapped. A meeting or a reply is logged by the app, or answered. */
	const PAGE_CHANNELS = array( 'call', 'text', 'email' );

	/** The platform's apps that may write; any other app registers itself on zdz_contact_apps. */
	const DEFAULT_APPS = array( 'leads', 'estimate-creator', 'jobs', 'surveys', 'assistant', 'chat' );

	/** A call's note waits this long for its one-tap answer. */
	const CALL_WAIT = 1800;
	const DRAIN_BATCH     = 10;
	const BACKOFF_AFTER   = 3;
	const BACKOFF_SECONDS = 86400;
	const IN_FLIGHT_TTL   = 900;
	const MAYBE_SETTLE    = 120;
	/** A note whose lead cannot be read for this long is given up (logged): the lead was deleted or merged. */
	const GIVE_UP_DAYS = 30;
	/** A note line and the rest of a page post are kept short. */
	const NOTE_MAX = 2000;

	public static function boot() {
		add_action( 'init', array( __CLASS__, 'maybe_install' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'zdz_register_capabilities', array( __CLASS__, 'register_verbs' ), 6 );
		add_filter( 'zdz_capability_annotations', array( __CLASS__, 'annotate' ) );
		add_action( self::CRON, array( __CLASS__, 'drain' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'zdz_contacts';
	}

	/* ── The table ────────────────────────────────────────────────────────────────────────────────────────── */

	public static function maybe_install() {
		if ( self::DB_VERSION !== (string) get_option( self::OPT_DB, '' ) ) {
			self::install();
		}
	}

	public static function install() {
		global $wpdb;
		$t   = self::table();
		$sql = "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			lead_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ref_kind varchar(12) NOT NULL DEFAULT '',
			ref_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			channel varchar(10) NOT NULL,
			outcome varchar(8) NOT NULL DEFAULT 'attempt',
			result varchar(10) NOT NULL DEFAULT '',
			app varchar(64) NOT NULL,
			occurred_at datetime NOT NULL,
			reached_at datetime DEFAULT NULL,
			note text DEFAULT NULL,
			tap_key char(16) NOT NULL DEFAULT '',
			dedupe char(32) NOT NULL,
			ns_state varchar(8) NOT NULL DEFAULT 'none',
			ns_posted varchar(8) NOT NULL DEFAULT '',
			ns_ref varchar(32) NOT NULL DEFAULT '',
			ns_note_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ns_attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			ns_next_at datetime DEFAULT NULL,
			ns_claimed_at datetime DEFAULT NULL,
			ns_posted_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY dedupe (dedupe),
			KEY lead_id (lead_id),
			KEY occurred_at (occurred_at),
			KEY user_time (user_id,occurred_at),
			KEY ns (ns_state,ns_next_at)
		) {$wpdb->get_charset_collate()};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		if ( $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) {
			update_option( self::OPT_DB, self::DB_VERSION );   // only once it is there: a failed create is tried again
		} else {
			error_log( 'Zorderz Contacts: the contacts table could not be created; will try again.' ); // phpcs:ignore
		}
	}

	public static function schedule() {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON );
		}
	}

	/* ── Who may write ────────────────────────────────────────────────────────────────────────────────────── */

	/** The apps that may write: DEFAULT_APPS plus any that register on zdz_contact_apps. */
	public static function apps() {
		$a = apply_filters( 'zdz_contact_apps', self::DEFAULT_APPS );
		$a = is_array( $a ) ? $a : self::DEFAULT_APPS;
		return array_values( array_unique( array_filter( array_map( 'strval', $a ), function ( $x ) {
			return (bool) preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $x );
		} ) ) );
	}

	/** The shared device or Demo Mode: never a contact. */
	public static function is_blocked( $uid ) {
		$uid = (int) $uid;
		if ( $uid <= 0 ) {
			return true;
		}
		if ( class_exists( 'ZDZ_Kiosk_Demo' ) && method_exists( 'ZDZ_Kiosk_Demo', 'is_active' ) && ZDZ_Kiosk_Demo::is_active() ) {
			return true;
		}
		if ( class_exists( 'ZDZ_Capabilities' ) && 'kiosk' === ZDZ_Capabilities::tier_of( $uid ) ) {
			return true;
		}
		return class_exists( 'ZDZ_Hierarchy' ) && method_exists( 'ZDZ_Hierarchy', 'is_kiosk' ) && ZDZ_Hierarchy::is_kiosk( $uid );
	}

	/** May this person be named on their contacts? Their usage-records choice. */
	public static function may_name( $uid ) {
		if ( ! class_exists( 'ZDZ_Consent' ) ) {
			return true;   // an older theme part: nothing to honour yet, as with usage records
		}
		return (bool) ZDZ_Consent::may_record( 'usage_records', (int) $uid );
	}

	/* ── Write ────────────────────────────────────────────────────────────────────────────────────────────── */

	/**
	 * Log one contact. $args:
	 *   app        (required) a registered app id
	 *   channel    (required) call | voicemail | email | text | in_person
	 *   lead_id    the CRM lead's number, when known
	 *   ref_kind, ref_id   what it was about (job, estimate, project, survey, outreach, lead) and its number
	 *   outcome    attempt (default) | reached. in_person is always reached; voicemail never is.
	 *   note       what was learned (optional; never in a count or a log line)
	 *   uid        who (default: the signed-in person). Named only if they may be (may_name).
	 *   occurred_at  UTC 'Y-m-d H:i:s' (default now; never in the future)
	 *   nutshell   false: no CRM note owed (an app that posts its own)
	 * At least a lead_id or a ref is needed. One row per app, lead, ref, channel, person and minute: a double tap is one.
	 *
	 * @return int the row's id, 0 when nothing was logged
	 */
	public static function log( array $args ) {
		try {
			$r = self::write( $args );
			return $r ? (int) $r['id'] : 0;
		} catch ( Throwable $e ) {
			error_log( 'Zorderz Contacts: not logged: ' . $e->getMessage() ); // phpcs:ignore
			return 0;
		}
	}

	/** log(), returning the row's id and tap key for the page: [ 'id' => int, 'key' => string ] or null. */
	public static function write( array $args ) {
		global $wpdb;
		$app = isset( $args['app'] ) ? (string) $args['app'] : '';
		if ( ! in_array( $app, self::apps(), true ) ) {
			return null;
		}
		$channel = isset( $args['channel'] ) ? (string) $args['channel'] : '';
		if ( ! in_array( $channel, self::CHANNELS, true ) ) {
			return null;
		}
		$uid = isset( $args['uid'] ) ? (int) $args['uid'] : (int) get_current_user_id();
		if ( self::is_blocked( $uid ) ) {
			return null;
		}
		$lead = self::num( $args['lead_id'] ?? 0 );
		$kind = isset( $args['ref_kind'] ) ? (string) $args['ref_kind'] : '';
		$ref  = self::num( $args['ref_id'] ?? 0 );
		if ( ! in_array( $kind, self::REF_KINDS, true ) || ! $ref ) {
			$kind = '';
			$ref  = 0;
		}
		if ( ! $lead && ! $ref ) {
			return null;
		}
		$outcome = self::initial_outcome( $channel, isset( $args['outcome'] ) ? (string) $args['outcome'] : '' );
		$now  = time();
		$when = $now;
		if ( isset( $args['occurred_at'] ) && is_string( $args['occurred_at'] )
			&& preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $args['occurred_at'] ) ) {
			$t = strtotime( $args['occurred_at'] . ' UTC' );
			if ( $t && $t <= $now + 300 ) {
				$when = $t;
			}
		}
		$who  = self::may_name( $uid ) ? $uid : 0;
		$note = isset( $args['note'] ) && is_scalar( $args['note'] ) ? self::clean_note( (string) $args['note'] ) : '';
		// One row per app, lead, ref, channel, person and minute. "Person" is who the row names: an unnamed row's key is
		// built from 0, never from the real id: a hash of a small id could be reversed in milliseconds.
		$dedupe = md5( implode( '|', array( $app, $lead, $kind, $ref, $channel, $who, gmdate( 'Y-m-d H:i', $when ) ) ) );

		$existing = self::by_dedupe( $dedupe );
		if ( $existing ) {
			return array( 'id' => (int) $existing['id'], 'key' => (string) $existing['tap_key'], 'again' => true );
		}
		$owed = $lead && ( ! isset( $args['nutshell'] ) || false !== $args['nutshell'] );
		$row  = array(
			'lead_id'     => $lead,
			'ref_kind'    => $kind,
			'ref_id'      => $ref,
			'user_id'     => $who,
			'channel'     => $channel,
			'outcome'     => $outcome,
			'result'      => 'in_person' === $channel ? 'talked' : ( 'voicemail' === $channel ? 'voicemail' : '' ),
			'app'         => $app,
			'occurred_at' => gmdate( 'Y-m-d H:i:s', $when ),
			'reached_at'  => 'reached' === $outcome ? gmdate( 'Y-m-d H:i:s', $when ) : null,
			'note'        => '' === $note ? null : $note,
			'tap_key'     => self::new_key(),
			'dedupe'      => $dedupe,
			'ns_state'    => $owed ? 'owed' : 'none',
			// A call waits for its one-tap answer before its note goes out.
			'ns_next_at'  => $owed ? gmdate( 'Y-m-d H:i:s', 'call' === $channel && 'attempt' === $outcome ? $now + self::CALL_WAIT : $now ) : null,
		);
		if ( ! self::quietly( function () use ( $wpdb, $row ) { return $wpdb->insert( self::table(), $row ); } ) ) {
			$again = self::by_dedupe( $dedupe );   // the other tap won the race
			if ( $again ) {
				return array( 'id' => (int) $again['id'], 'key' => (string) $again['tap_key'], 'again' => true );
			}
			error_log( 'Zorderz Contacts: a contact could not be written (database error).' ); // phpcs:ignore
			return null;
		}
		$id = (int) $wpdb->insert_id;
		if ( '' === (string) get_option( self::OPT_STARTED, '' ) ) {
			update_option( self::OPT_STARTED, ( new DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() )->format( 'Y-m-d' ), false );
		}
		if ( $owed && 'call' !== $channel && function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( $now + 10, self::CRON );
		}
		return array( 'id' => $id, 'key' => $row['tap_key'], 'again' => false );
	}

	/**
	 * An answer for one contact. $how:
	 *   result  talked | voicemail | no_answer | replied
	 *   note    what was learned (optional)
	 * talked and replied make it reached (and stay reached); voicemail turns a call into a voicemail; no_answer keeps a
	 * try. A try whose note already went out owes one more note when it is reached.
	 */
	public static function reached( $id, array $how = array() ) {
		global $wpdb;
		$row = self::get( $id );
		if ( ! $row ) {
			return false;
		}
		$result = isset( $how['result'] ) ? (string) $how['result'] : 'replied';
		if ( ! in_array( $result, self::RESULTS, true ) ) {
			return false;
		}
		$set = self::answer_changes( $row, $result, gmdate( 'Y-m-d H:i:s' ) );
		if ( null === $set ) {
			return true;   // a later "No answer" never takes back a reach
		}
		$note = isset( $how['note'] ) && is_scalar( $how['note'] ) ? self::clean_note( (string) $how['note'] ) : '';
		if ( '' !== $note ) {
			$set['note'] = $note;
		}
		$t  = self::table();
		$ok = false !== self::quietly( function () use ( $wpdb, $t, $set, $row ) { return $wpdb->update( $t, $set, array( 'id' => (int) $row['id'] ) ); } );
		if ( ! $ok ) {
			error_log( 'Zorderz Contacts: an answer for contact #' . (int) $row['id'] . ' could not be saved (database error).' ); // phpcs:ignore
			return false;
		}
		if ( (int) $row['lead_id'] > 0 ) {
			// Each a conditional UPDATE on the state as it is now: two answers at once must not turn a note being
			// posted back into an owed one.
			$now  = gmdate( 'Y-m-d H:i:s' );
			$sent = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET ns_next_at = %s WHERE id = %d AND ns_state = 'owed'", $now, (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$t} is self::table(): $wpdb->prefix plus a fixed name
			$sent += (int) self::follow_up_if_reached( (int) $row['id'] );
			if ( $sent > 0 && function_exists( 'wp_schedule_single_event' ) ) {
				wp_schedule_single_event( time() + 10, self::CRON );
			}
		}
		return true;
	}

	/**
	 * The answers that make a contact reached. The rule "contacted means the customer talked with us"
	 * is Core's (talked, replied); a business that counts something else as reaching a customer says so
	 * on the `zdz_contact_reaching_results` filter. Only known answers survive; an empty or broken answer
	 * from the filter keeps Core's.
	 */
	public static function reaching_results() {
		$r = function_exists( 'apply_filters' ) ? apply_filters( 'zdz_contact_reaching_results', self::REACHING_RESULTS ) : self::REACHING_RESULTS;
		$r = is_array( $r ) ? array_values( array_intersect( self::RESULTS, array_map( 'strval', $r ) ) ) : array();
		return $r ? $r : self::REACHING_RESULTS;
	}

	/**
	 * A new row's outcome (pure). A meeting in person always reached them; a voicemail reached them only
	 * when the business counts a voicemail as reaching; anything else is what the caller said (a try unless
	 * it said reached).
	 */
	public static function initial_outcome( $channel, $asked ) {
		if ( 'in_person' === $channel ) {
			return 'reached';
		}
		if ( 'voicemail' === $channel ) {
			return in_array( 'voicemail', self::reaching_results(), true ) ? 'reached' : 'attempt';
		}
		return 'reached' === $asked ? 'reached' : 'attempt';
	}

	/**
	 * What one answer changes on a row (pure): the columns to set, or null when it changes nothing. An
	 * answer that reaches makes the row reached (and it stays reached); a later try never takes a reach
	 * back; "Left a voicemail" turns a call that has not reached anyone into a voicemail.
	 *
	 * @param array  $row    the row as stored (channel, outcome)
	 * @param string $result one of RESULTS
	 * @param string $now    UTC 'Y-m-d H:i:s' to stamp a new reach with
	 * @return array|null
	 */
	public static function answer_changes( array $row, $result, $now ) {
		$set     = array( 'result' => (string) $result );
		$reached = 'reached' === (string) ( $row['outcome'] ?? '' );
		if ( 'voicemail' === $result && 'call' === (string) ( $row['channel'] ?? '' ) && ! $reached ) {
			$set['channel'] = 'voicemail';
		}
		if ( in_array( $result, self::reaching_results(), true ) ) {
			if ( ! $reached ) {
				$set['outcome']    = 'reached';
				$set['reached_at'] = (string) $now;
			}
			return $set;
		}
		return $reached ? null : $set;
	}

	public static function get( $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( $id <= 0 ) {
			return null;
		}
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::table() is $wpdb->prefix plus a fixed name
		return is_array( $r ) ? $r : null;
	}

	/**
	 * May $uid answer for this contact? Never the shared device or someone with no place on the ladder. Then: the page
	 * holding its tap key (the call it just made), its own person, or an administrator; and for "They replied", anyone
	 * who has the contact's app (a reply a colleague sees, or one to an unnamed contact, counts).
	 */
	public static function may_answer( array $row, $uid, $key = '', $result = '' ) {
		$uid = (int) $uid;
		if ( self::is_blocked( $uid ) || ! self::on_ladder( $uid ) ) {
			return false;
		}
		if ( '' !== (string) $key && '' !== (string) $row['tap_key'] && hash_equals( (string) $row['tap_key'], (string) $key ) ) {
			return true;
		}
		if ( (int) $row['user_id'] > 0 && (int) $row['user_id'] === $uid ) {
			return true;
		}
		if ( 'admin' === ZDZ_Capabilities::tier_of( $uid ) ) {
			return true;
		}
		return 'replied' === $result && self::has_app( $uid, (string) $row['app'] );
	}

	/** Someone with a place on the ladder (a tech or more), not the shared device. */
	public static function on_ladder( $uid ) {
		return class_exists( 'ZDZ_Capabilities' ) && in_array( ZDZ_Capabilities::tier_of( (int) $uid ), array( 'viewer', 'staff', 'admin' ), true );
	}

	/** Has the app (chat and the Assistant: anyone on the ladder). Refuses when the theme's app check is missing. */
	public static function has_app( $uid, $app ) {
		if ( in_array( $app, array( 'chat', 'assistant' ), true ) ) {
			return self::on_ladder( $uid );
		}
		return class_exists( 'ZDZ_Plugin_API' ) && method_exists( 'ZDZ_Plugin_API', 'user_can_access_app' )
			&& ZDZ_Plugin_API::user_can_access_app( (int) $uid, $app );
	}

	/* ── Read ─────────────────────────────────────────────────────────────────────────────────────────────── */

	/**
	 * Counts for one month, never names or notes. $q:
	 *   month  'YYYY-MM' in the site's zone (default this month)
	 *   scope  mine (default) | company
	 *   uid    whose "mine" (default: the signed-in person)
	 *   include_surveys  company only: the surveys app's own calls count too (default false: its call list is counted apart)
	 * Returns basis 'log' with: leads_reached (leads with a reached contact), leads_tried_only (tried, not reached yet),
	 * contacts by channel and outcome, and since (the first counted day, when it falls in this month); basis 'none' for
	 * a month before counting started; recorded false for "mine" of someone who turned off usage records.
	 * Which leads count as the business's own, and the month's new leads, are the analytics app's to say.
	 */
	public static function counts( array $q = array() ) {
		global $wpdb;
		$tz    = wp_timezone();
		$month = isset( $q['month'] ) && preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', (string) $q['month'] )
			? (string) $q['month'] : ( new DateTimeImmutable( 'now', $tz ) )->format( 'Y-m' );
		$scope = isset( $q['scope'] ) && 'company' === $q['scope'] ? 'company' : 'mine';
		$uid   = isset( $q['uid'] ) ? (int) $q['uid'] : (int) get_current_user_id();
		$out   = array( 'success' => true, 'month' => $month, 'scope' => $scope, 'source' => 'zdz_contacts' );

		if ( 'mine' === $scope && ! self::may_name( $uid ) ) {
			return $out + array( 'basis' => 'log', 'recorded' => false,
				'message' => __( 'Not recorded: you turned off usage records.', 'zorderz' ) );
		}
		$start = new DateTimeImmutable( $month . '-01 00:00:00', $tz );
		$end   = $start->modify( '+1 month' );
		$began = (string) get_option( self::OPT_STARTED, '' );
		if ( '' === $began || $began >= $end->format( 'Y-m-d' ) ) {
			return $out + array( 'basis' => 'none', 'message' => __( 'Not counted yet: counting starts when the apps begin logging contacts.', 'zorderz' ) );
		}
		$since = $began > $start->format( 'Y-m-d' ) ? $began : '';
		if ( 'mine' === $scope && class_exists( 'ZDZ_Consent' ) && ZDZ_Consent::enabled() ) {
			// Named only from the day they agreed: before it their contacts carry no one.
			$c   = get_user_meta( $uid, ZDZ_Consent::META, true );
			$day = is_array( $c ) && ! empty( $c['accepted_at'] ) ? substr( (string) $c['accepted_at'], 0, 10 ) : '';
			if ( '' !== $day && $day > $start->format( 'Y-m-d' ) && $day < $end->format( 'Y-m-d' ) && $day > $since ) {
				$since = $day;
			}
		}
		$from  = $start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$to    = $end->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );

		$where = $wpdb->prepare( 'occurred_at >= %s AND occurred_at < %s', $from, $to );
		if ( 'mine' === $scope ) {
			$where .= $wpdb->prepare( ' AND user_id = %d', $uid );
		} elseif ( empty( $q['include_surveys'] ) ) {
			$where .= " AND ref_kind <> 'survey' AND app <> 'surveys'";
		}
		$t     = self::table();
		$leads = $wpdb->get_row( "SELECT COUNT(DISTINCT lead_id) AS tried, COUNT(DISTINCT CASE WHEN outcome = 'reached' THEN lead_id END) AS reached FROM {$t} WHERE {$where} AND lead_id > 0", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$t} is self::table(): $wpdb->prefix plus a fixed name; {$where} is built only from $wpdb->prepare() and fixed text above
		$err   = (string) $wpdb->last_error;   // wpdb answers a failed read with null or an empty array, so ask it
		$rows  = $wpdb->get_results( "SELECT channel, outcome, COUNT(*) AS n FROM {$t} WHERE {$where} GROUP BY channel, outcome", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$t} is self::table(): $wpdb->prefix plus a fixed name; {$where} is built only from $wpdb->prepare() and fixed text above
		$err  .= (string) $wpdb->last_error;
		if ( '' !== $err || ! is_array( $leads ) || ! is_array( $rows ) ) {
			// A failed read is never a 0.
			return array( 'success' => false, 'error' => __( 'The contact log could not be read just now.', 'zorderz' ), 'source' => 'zdz_contacts' );
		}
		$by = array();
		foreach ( self::CHANNELS as $c ) {
			$by[ $c ] = array( 'attempt' => 0, 'reached' => 0 );
		}
		foreach ( $rows as $r ) {
			if ( isset( $by[ $r['channel'] ][ $r['outcome'] ] ) ) {
				$by[ $r['channel'] ][ $r['outcome'] ] = (int) $r['n'];
			}
		}
		$reached = (int) $leads['reached'];
		return $out + array(
			'basis'            => 'log',
			'recorded'         => true,
			'since'            => $since,
			'leads_reached'    => $reached,
			'leads_tried_only' => max( 0, (int) $leads['tried'] - $reached ),
			'by_channel'       => $by,
		);
	}

	/* ── The page ─────────────────────────────────────────────────────────────────────────────────────────── */

	public static function routes() {
		register_rest_route( ZDZ_REST_NS, '/contacts', array(
			'methods'             => 'POST',
			'permission_callback' => function () { return is_user_logged_in(); },
			'callback'            => array( __CLASS__, 'rest_log' ),
		) );
		register_rest_route( ZDZ_REST_NS, '/contacts/(?P<id>\d+)/outcome', array(
			'methods'             => 'POST',
			'permission_callback' => function () { return is_user_logged_in(); },
			'callback'            => array( __CLASS__, 'rest_outcome' ),
		) );
		register_rest_route( ZDZ_REST_NS, '/contacts/counts', array(
			'methods'             => 'GET',
			'permission_callback' => function () { return is_user_logged_in(); },
			'callback'            => array( __CLASS__, 'rest_counts' ),
		) );
	}

	/** POST /zorderz/v1/contacts {app, channel: call|text|email, lead_id?, ref_kind?, ref_id?} -> {id, key} */
	public static function rest_log( $req ) {
		$uid = (int) get_current_user_id();
		$b   = self::body( $req );
		$app = isset( $b['app'] ) ? (string) $b['app'] : '';
		if ( self::is_blocked( $uid ) ) {
			return new WP_Error( 'zdz_contacts_kiosk', __( 'Not on the shared device.', 'zorderz' ), array( 'status' => 403 ) );
		}
		if ( ! in_array( $app, self::page_apps(), true ) || ! in_array( (string) ( $b['channel'] ?? '' ), self::PAGE_CHANNELS, true ) ) {
			return new WP_Error( 'zdz_contacts_bad', __( 'That is not a contact the app can log.', 'zorderz' ), array( 'status' => 400 ) );
		}
		// Someone on the ladder, for an app they have; refused when the app check is missing.
		if ( ! self::on_ladder( $uid ) || ! self::has_app( $uid, $app ) ) {
			return new WP_Error( 'zdz_contacts_app', __( 'That is not available on your account.', 'zorderz' ), array( 'status' => 403 ) );
		}
		if ( ! self::within_rate( $uid ) ) {
			return new WP_Error( 'zdz_contacts_busy', __( 'Too many contacts logged in the last hour. Try again later.', 'zorderz' ), array( 'status' => 429 ) );
		}
		$r = null;
		try {
			$r = self::write( array(
				'app'      => $app,
				'channel'  => (string) $b['channel'],
				'lead_id'  => $b['lead_id'] ?? 0,
				'ref_kind' => $b['ref_kind'] ?? '',
				'ref_id'   => $b['ref_id'] ?? 0,
				'uid'      => $uid,
			) );
		} catch ( Throwable $e ) {
			error_log( 'Zorderz Contacts: page log failed: ' . $e->getMessage() ); // phpcs:ignore
		}
		if ( ! $r ) {
			return new WP_Error( 'zdz_contacts_not_logged', __( 'That contact was not logged.', 'zorderz' ), array( 'status' => 422 ) );
		}
		return rest_ensure_response( array( 'success' => true, 'id' => $r['id'], 'key' => $r['key'] ) );
	}

	/** POST /zorderz/v1/contacts/<id>/outcome {result, key?, note?} */
	public static function rest_outcome( $req ) {
		$uid = (int) get_current_user_id();
		$b   = self::body( $req );
		$row = self::get( (int) $req->get_param( 'id' ) );
		// Someone else's contact and a missing one read the same: nothing to tell apart.
		$result = isset( $b['result'] ) ? (string) $b['result'] : '';
		if ( ! $row || ! self::may_answer( $row, $uid, isset( $b['key'] ) ? (string) $b['key'] : '', $result ) ) {
			return new WP_Error( 'zdz_contacts_none', __( 'That contact is not one you can answer for.', 'zorderz' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $result, self::RESULTS, true ) ) {
			return new WP_Error( 'zdz_contacts_bad', __( 'That is not an answer the app knows.', 'zorderz' ), array( 'status' => 400 ) );
		}
		if ( ! self::reached( (int) $row['id'], array( 'result' => $result, 'note' => $b['note'] ?? '' ) ) ) {
			return new WP_Error( 'zdz_contacts_failed', __( 'That answer was not saved. Try again.', 'zorderz' ), array( 'status' => 500 ) );
		}
		$now = self::get( (int) $row['id'] );
		return rest_ensure_response( array( 'success' => true, 'id' => (int) $row['id'], 'outcome' => $now ? $now['outcome'] : '' ) );
	}

	/** GET /zorderz/v1/contacts/counts?month=&scope= : company for administrators only. */
	public static function rest_counts( $req ) {
		$uid = (int) get_current_user_id();
		$out = self::counts_for( $uid, array( 'month' => (string) $req->get_param( 'month' ), 'scope' => (string) $req->get_param( 'scope' ) ) );
		if ( ! empty( $out['denied'] ) ) {
			return new WP_Error( 'zdz_contacts_denied', $out['message'], array( 'status' => 403 ) );
		}
		return rest_ensure_response( $out );
	}

	/** counts() with the rule on who may ask: the shared device never; the company for administrators. */
	public static function counts_for( $uid, array $q ) {
		if ( self::is_blocked( $uid ) ) {
			return array( 'success' => true, 'denied' => true, 'message' => __( 'Not on the shared device.', 'zorderz' ), 'source' => 'zdz_contacts' );
		}
		$scope = isset( $q['scope'] ) && 'company' === $q['scope'] ? 'company' : 'mine';
		if ( 'company' === $scope && ! ( class_exists( 'ZDZ_Capabilities' ) && 'admin' === ZDZ_Capabilities::tier_of( (int) $uid ) ) ) {
			return array( 'success' => true, 'denied' => true, 'message' => __( 'The company\'s count is for administrators.', 'zorderz' ), 'source' => 'zdz_contacts' );
		}
		return self::counts( array( 'month' => $q['month'] ?? '', 'scope' => $scope, 'uid' => (int) $uid ) );
	}

	/** Apps a page may log for: not chat or the Assistant, which log through contact.log and its Confirm card. */
	public static function page_apps() {
		return array_values( array_diff( self::apps(), array( 'chat', 'assistant' ) ) );
	}

	/** A page logs at most PAGE_RATE contacts an hour for one person. */
	const PAGE_RATE = 60;

	private static function within_rate( $uid ) {
		$k = 'zdz_contacts_rate_' . (int) $uid . '_' . gmdate( 'YmdH' );
		$n = (int) get_transient( $k );
		if ( $n >= self::PAGE_RATE ) {
			return false;
		}
		set_transient( $k, $n + 1, 3600 );
		return true;
	}

	/* ── Verbs ────────────────────────────────────────────────────────────────────────────────────────────── */

	public static function register_verbs( $caps ) {
		$caps = is_array( $caps ) ? $caps : array();
		if ( ! isset( $caps['contact.log'] ) ) {
			$caps['contact.log'] = array(
				'verb'        => 'contact.log',
				'provider'    => 'theme-contacts',
				'tier'        => 'viewer',
				'callback'    => array( __CLASS__, 'verb_log' ),
				'side_effect' => true,   // and outside: it posts a CRM note. Confirmed in chat.
				'summary'     => 'Log a call, voicemail, e-mail, text or meeting with a customer, and whether it reached them.',
			);
		}
		if ( ! isset( $caps['contact.counts'] ) ) {
			$caps['contact.counts'] = array(
				'verb'        => 'contact.counts',
				'provider'    => 'theme-contacts',
				'tier'        => 'viewer',
				'callback'    => array( __CLASS__, 'verb_counts' ),
				'side_effect' => false,
				'summary'     => 'How many leads were reached, or tried and not reached yet, this month: yours, or the company\'s (administrators).',
			);
		}
		return $caps;
	}

	public static function annotate( $a ) {
		$a = is_array( $a ) ? $a : array();
		$a += array(
			'contact.log'    => array( 'app' => '', 'outside' => true, 'needs_app' => false ),
			'contact.counts' => array( 'app' => '', 'outside' => false, 'needs_app' => false ),
		);
		return $a;
	}

	/** contact.log: run by chat's Confirm card for the signed-in person (user_id is the registry's stamp). */
	public static function verb_log( array $args ) {
		$uid = (int) ( $args['user_id'] ?? 0 );
		// Whoever runs the Confirm card must stamp the signed-in person (as invoke() does); a different id is refused.
		$me = (int) get_current_user_id();
		if ( $me > 0 && $uid !== $me ) {
			return array( 'success' => false, 'denied' => true, 'message' => __( 'That contact was not logged.', 'zorderz' ), 'verb' => 'contact.log' );
		}
		if ( ! empty( $args['is_kiosk'] ) || self::is_blocked( $uid ) ) {
			return array( 'success' => true, 'denied' => true, 'message' => __( 'Not on the shared device.', 'zorderz' ), 'verb' => 'contact.log' );
		}
		$app = isset( $args['app'] ) && in_array( (string) $args['app'], self::apps(), true ) ? (string) $args['app'] : 'chat';
		$r   = null;
		try {
			$r = self::write( array(
				'app'      => $app,
				'channel'  => $args['channel'] ?? '',
				'outcome'  => $args['outcome'] ?? 'attempt',
				'lead_id'  => $args['lead_id'] ?? 0,
				'ref_kind' => $args['ref_kind'] ?? '',
				'ref_id'   => $args['ref_id'] ?? 0,
				'note'     => $args['note'] ?? '',
				'uid'      => $uid,
			) );
		} catch ( Throwable $e ) {
			error_log( 'Zorderz Contacts: contact.log failed: ' . $e->getMessage() ); // phpcs:ignore
		}
		return $r
			? array( 'success' => true, 'id' => $r['id'], 'message' => __( 'Logged.', 'zorderz' ), 'verb' => 'contact.log' )
			: array( 'success' => false, 'error' => __( 'That contact was not logged.', 'zorderz' ), 'verb' => 'contact.log' );
	}

	public static function verb_counts( array $args ) {
		$uid = (int) ( $args['user_id'] ?? 0 );
		$out = self::counts_for( $uid, array( 'month' => $args['month'] ?? '', 'scope' => $args['scope'] ?? 'mine' ) );
		$out['verb'] = 'contact.counts';
		return $out;
	}

	/* ── CRM: the owed notes ──────────────────────────────────────────────────────────────────────────────── */

	/** Fixed opening, so a reader of the CRM's notes (the analytics app) knows the note is the app's and does not count it twice. */
	const NOTE_OPENING = 'Contact log · ';

	/** The opening every note starts with: NOTE_OPENING, or the `zdz_contact_note_opening` filter's plain one-line text. */
	public static function note_opening() {
		$o = function_exists( 'apply_filters' ) ? apply_filters( 'zdz_contact_note_opening', self::NOTE_OPENING ) : self::NOTE_OPENING;
		$o = is_string( $o ) ? preg_replace( '/[\x00-\x1F\x7F]/', '', $o ) : '';
		return ( '' !== trim( (string) $o ) && strlen( $o ) <= 60 ) ? $o : self::NOTE_OPENING;
	}

	/** The note for a row as it stands: [ text, ref ]. No name when the row has none. */
	public static function note_text( array $row ) {
		// A follow-up: its try went out as a try, and it has been reached since.
		$follow = 'attempt' === $row['ns_posted'] && 'reached' === $row['outcome'];
		$what   = self::what( $row, $follow );
		$who    = (int) $row['user_id'] > 0 ? self::short_name( (int) $row['user_id'] ) : '';
		$when   = self::local_time( (string) ( $follow && $row['reached_at'] ? $row['reached_at'] : $row['occurred_at'] ) );
		$ref    = 'zdc-' . (int) $row['id'] . '-' . ( 'reached' === $row['outcome'] ? 'r' : 'a' );
		$text   = self::note_opening() . $what . ' (' . ( '' !== $who ? $who . ', ' : '' ) . $when . ')';
		$note   = trim( (string) $row['note'] );
		if ( '' !== $note ) {
			$text .= "\n\n" . $note;
		}
		return array( $text . "\n\nRef: " . $ref, $ref );
	}

	private static function what( array $row, $follow ) {
		if ( $follow ) {
			$said = array( 'replied' => 'They replied', 'voicemail' => 'Left a voicemail', 'no_answer' => 'No answer' );
			return $said[ (string) $row['result'] ] ?? 'Talked with them';
		}
		$reached = 'reached' === $row['outcome'];
		switch ( $row['channel'] ) {
			case 'in_person':
				return 'Talked in person';
			case 'voicemail':
				return 'Called — left a voicemail';
			case 'email':
				return $reached ? 'E-mailed — they replied' : 'E-mailed';
			case 'text':
				return $reached ? 'Texted — they replied' : 'Texted';
			default:
				if ( $reached ) {
					return 'replied' === $row['result'] ? 'Called — they called back' : 'Called — talked with them';
				}
				return 'no_answer' === $row['result'] ? 'Called — no answer' : 'Called';
		}
	}

	/** First name and last initial, as the leads app writes them ("Alex E."). */
	public static function short_name( $uid ) {
		$f = trim( (string) get_user_meta( $uid, 'first_name', true ) );
		$l = trim( (string) get_user_meta( $uid, 'last_name', true ) );
		if ( '' === $f ) {
			$u = get_userdata( $uid );
			$p = $u ? preg_split( '/\s+/', trim( (string) $u->display_name ) ) : array();
			$f = (string) ( $p[0] ?? '' );
			$l = count( $p ) > 1 ? (string) end( $p ) : $l;
		}
		return trim( $f . ( '' !== $l ? ' ' . mb_substr( $l, 0, 1 ) . '.' : '' ) );
	}

	private static function local_time( $utc ) {
		$t = strtotime( $utc . ' UTC' ) ?: time();
		return ( new DateTimeImmutable( '@' . $t ) )->setTimezone( wp_timezone() )->format( 'M j, Y \a\t g:i A T' );
	}

	/** The client to post with: the theme's shared CRM client. A filter lets the tests stand one in. */
	protected static function client() {
		$c = apply_filters( 'zdz_contacts_nutshell_client', null );
		if ( $c ) {
			return $c;
		}
		return class_exists( 'ZDZ_Core_Nutshell' ) ? new ZDZ_Core_Nutshell() : null;
	}

	/**
	 * Post what is owed, a few at a time, from cron. Each row is claimed by one UPDATE (owed -> posting) before anyone
	 * posts it, so two runs never post one note. The client's null is "maybe": it may have landed, so the lead is read
	 * for the Ref line before the note is ever posted again. Returns what it did, for the tests and the log.
	 */
	public static function drain( $limit = self::DRAIN_BATCH ) {
		global $wpdb;
		$t    = self::table();
		$now  = time();
		$nows = gmdate( 'Y-m-d H:i:s', $now );
		$did  = array( 'posted' => 0, 'maybe' => 0, 'settled' => 0, 'requeued' => 0, 'unreadable' => 0, 'gave_up' => 0, 'skipped' => '' );

		// A claim older than IN_FLIGHT_TTL: that run died part-way; it may have posted.
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET ns_state = 'maybe' WHERE ns_state = 'posting' AND ns_claimed_at < %s", gmdate( 'Y-m-d H:i:s', $now - self::IN_FLIGHT_TTL ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$t} is self::table(): $wpdb->prefix plus a fixed name

		$client = self::client();
		if ( ! $client || ( method_exists( $client, 'is_configured' ) && ! $client->is_configured() ) ) {
			$did['skipped'] = 'nutshell_not_configured';
			return $did;
		}

		// Maybes first: read the lead, look for the Ref.
		$maybes = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE ns_state = 'maybe' AND ns_claimed_at < %s AND ( ns_next_at IS NULL OR ns_next_at <= %s ) ORDER BY ns_next_at, id LIMIT %d", gmdate( 'Y-m-d H:i:s', $now - self::MAYBE_SETTLE ), $nows, (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$t} is self::table(): $wpdb->prefix plus a fixed name
		foreach ( $maybes as $row ) {
			// The Ref of the note that may have landed (not the row as it stands: it may have been answered since).
			$ref   = '' !== (string) $row['ns_ref'] ? (string) $row['ns_ref'] : self::note_text( $row )[1];
			$found = self::lead_has_ref( $client, (int) $row['lead_id'], $ref );
			if ( null === $found ) {
				self::read_failed( $row, $now );   // never block the rows behind it
				$did['unreadable']++;
				continue;
			}
			if ( $found ) {
				$wpdb->update( $t, array( 'ns_state' => 'posted', 'ns_posted' => '-r' === substr( $ref, -2 ) ? 'reached' : 'attempt', 'ns_posted_at' => $nows ), array( 'id' => (int) $row['id'], 'ns_state' => 'maybe' ) );
				self::follow_up_if_reached( (int) $row['id'] );
				$did['settled']++;
			} else {
				$wpdb->update( $t, array( 'ns_state' => 'owed', 'ns_next_at' => $nows ), array( 'id' => (int) $row['id'], 'ns_state' => 'maybe' ) );
				$did['requeued']++;
			}
		}

		$owed = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE ns_state = 'owed' AND ns_next_at <= %s ORDER BY ns_next_at, id LIMIT %d", $nows, (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$t} is self::table(): $wpdb->prefix plus a fixed name
		foreach ( $owed as $row ) {
			$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET ns_state = 'posting', ns_claimed_at = %s, ns_attempts = ns_attempts + 1 WHERE id = %d AND ns_state = 'owed'", $nows, (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$t} is self::table(): $wpdb->prefix plus a fixed name
			if ( 1 !== (int) $claimed ) {
				continue;   // another run has it
			}
			$row = self::get( (int) $row['id'] );   // as it stands now (an answer may have come in)
			if ( ! $row ) {
				continue;
			}
			list( $text, $ref ) = self::note_text( $row );
			$wpdb->update( $t, array( 'ns_ref' => $ref ), array( 'id' => (int) $row['id'], 'ns_state' => 'posting' ) );
			$res = null;
			try {
				$res = $client->add_note( array( 'entity' => array( 'entityType' => 'Leads', 'id' => (int) $row['lead_id'] ), 'note' => $text ) );
			} catch ( Throwable $e ) {
				$res = null;
			}
			// As the surveys app reads the shared client: anything back is a post; null is "not known".
			if ( null !== $res && false !== $res ) {
				$nid = is_array( $res ) && isset( $res['id'] ) ? (int) $res['id'] : 0;
				$wpdb->update( $t, array( 'ns_state' => 'posted', 'ns_posted' => $row['outcome'], 'ns_note_id' => $nid, 'ns_posted_at' => $nows ), array( 'id' => (int) $row['id'], 'ns_state' => 'posting' ) );
				self::follow_up_if_reached( (int) $row['id'] );
				$did['posted']++;
				continue;
			}
			// Nothing definite back: it may have landed. Never posted again before the lead is read.
			$next = (int) $row['ns_attempts'] >= self::BACKOFF_AFTER ? $now + self::BACKOFF_SECONDS : $now;
			$wpdb->update( $t, array( 'ns_state' => 'maybe', 'ns_claimed_at' => $nows, 'ns_next_at' => gmdate( 'Y-m-d H:i:s', $next ) ), array( 'id' => (int) $row['id'], 'ns_state' => 'posting' ) );
			error_log( 'Zorderz Contacts: CRM note for contact #' . (int) $row['id'] . ' not confirmed (attempt ' . (int) $row['ns_attempts'] . '); will check the lead before posting again.' ); // phpcs:ignore
			$did['maybe']++;
		}
		return $did;
	}

	/** A lead that could not be read: try again later (a day after BACKOFF_AFTER tries); after GIVE_UP_DAYS, give up loudly. */
	protected static function read_failed( array $row, $now ) {
		global $wpdb;
		$tries = (int) $row['ns_attempts'] + 1;
		if ( strtotime( $row['occurred_at'] . ' UTC' ) < $now - self::GIVE_UP_DAYS * 86400 ) {
			$wpdb->update( self::table(), array( 'ns_state' => 'gave_up' ), array( 'id' => (int) $row['id'], 'ns_state' => 'maybe' ) );
			error_log( 'Zorderz Contacts: gave up on the CRM note for contact #' . (int) $row['id'] . ': its lead could not be read for ' . self::GIVE_UP_DAYS . ' days (deleted or merged?).' ); // phpcs:ignore
			return;
		}
		$next = $now + ( $tries >= self::BACKOFF_AFTER ? self::BACKOFF_SECONDS : 900 );
		$wpdb->update( self::table(), array( 'ns_attempts' => $tries, 'ns_next_at' => gmdate( 'Y-m-d H:i:s', $next ) ), array( 'id' => (int) $row['id'], 'ns_state' => 'maybe' ) );
	}

	/** Reached while its try's note was on its way: one more note is owed ("talked with them" / "they replied"). */
	protected static function follow_up_if_reached( $id ) {
		global $wpdb;
		return $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET ns_state = 'owed', ns_attempts = 0, ns_next_at = %s WHERE id = %d AND ns_state = 'posted' AND ns_posted = 'attempt' AND outcome = 'reached'", gmdate( 'Y-m-d H:i:s' ), (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::table() is $wpdb->prefix plus a fixed name
	}

	/** true / false: is the Ref on the lead's notes; null: the lead could not be read. */
	protected static function lead_has_ref( $client, $lead_id, $ref ) {
		if ( ! method_exists( $client, 'get_lead' ) ) {
			return null;
		}
		try {
			$lead = $client->get_lead( (int) $lead_id );
		} catch ( Throwable $e ) {
			return null;
		}
		if ( ! is_array( $lead ) ) {
			return null;
		}
		foreach ( (array) ( $lead['notes'] ?? array() ) as $n ) {
			$bodies = is_array( $n ) ? array_intersect_key( $n, array_flip( array( 'note', 'noteMarkup', 'noteHtml', 'body' ) ) ) : array( (string) $n );
			foreach ( $bodies as $body ) {
				if ( is_string( $body ) && false !== strpos( $body, 'Ref: ' . $ref ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/* ── Small helpers ────────────────────────────────────────────────────────────────────────────────────── */

	/** Run a write with wpdb's own error printing off: it would put the whole query, the note with it, in the log. */
	private static function quietly( callable $fn ) {
		global $wpdb;
		$was = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
		try {
			return $fn();
		} finally {
			if ( null !== $was ) {
				$wpdb->suppress_errors( $was );
			}
		}
	}

	private static function by_dedupe( $d ) {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT id, tap_key FROM ' . self::table() . ' WHERE dedupe = %s', $d ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::table() is $wpdb->prefix plus a fixed name
		return is_array( $r ) ? $r : null;
	}

	private static function num( $v ) {
		return ( is_int( $v ) || is_string( $v ) ) && preg_match( '/^[1-9][0-9]{0,15}$/', (string) $v ) ? (int) $v : 0;
	}

	private static function clean_note( $s ) {
		$s = trim( sanitize_textarea_field( $s ) );
		return mb_substr( $s, 0, self::NOTE_MAX );
	}

	private static function new_key() {
		try {
			return bin2hex( random_bytes( 8 ) );
		} catch ( Throwable $e ) {
			return substr( md5( uniqid( '', true ) . mt_rand() ), 0, 16 );
		}
	}

	private static function body( $req ) {
		$b = method_exists( $req, 'get_json_params' ) ? $req->get_json_params() : array();
		return is_array( $b ) ? $b : array();
	}
}
