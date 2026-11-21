<?php
/**
 * ZDZ_Signin_Routes -- getting in when the sign-in email can't arrive.
 *
 * A sign-in code normally reaches a person by email. When their mailbox is down, or the site can't send, they need
 * another way in that is not a password. This class keeps three, and one named person sets them up for everyone:
 *
 *   - a BACKUP ADDRESS: another address a person may type on the sign-in screen. The code goes to the address that
 *     was typed, so an address at a different mail service works when the work mailbox does not;
 *   - a ONE-TIME SIGN-IN CODE that the manager of sign-in routes gives a person: eight digits, shown once, good for
 *     a few minutes, once, for that account only;
 *   - a SECOND ISSUER: one more account that may give such codes, for when the manager can't be reached.
 *
 * There is no password, text message, authenticator app or printed code here. A further route would be one more
 * kind in put_code() and one more branch of "which codes fit" in claim(); the page and the login bridge stay as
 * they are. The shared device and an account in Privacy View get no route at all.
 *
 * What lives here:
 *   1. EVERY SIGN-IN CODE, KEPT PER ACCOUNT (user meta zdz_signin_codes): a salted one-way hash, an expiry, a kind
 *      ("mail": emailed, six digits; "bridge": shown on the browser's "You're logged in" page for the Home Screen
 *      app, six digits; "issued": given by a person, EIGHT digits) and the wrong tries it has left. A code is never
 *      kept under a name made of its digits: two people can be given the same digits, and each signs in as
 *      themselves, because a claim carries the email AND the code. Each hash has its own random salt and nothing
 *      here is keyed by the WordPress salts, so a change of salts leaves a live code, the manager and every backup
 *      address working.
 *   2. WRONG TRIES, COUNTED PER CODE: an emailed code is claimed only with the address that was typed to ask for
 *      it, and a wrong six-digit try costs only the codes asked for with THAT address one of their 5 tries (and the
 *      "bridge" codes); a wrong eight-digit try costs only a given code one of its 300. A code with none left is
 *      dead and the person asks for another. There is deliberately NO lock on the account: a lock protects against
 *      guessing and is itself a way to keep someone out. Nothing a stranger does with the work address touches a
 *      code asked for with the backup address or a code a person gave. Emails are capped per typed address
 *      (6 in 15 minutes). After 50 wrong six-digit tries against an account's live codes in one day, no more
 *      codes are emailed to it until that day is over and the managers are told; a code a person gives, and the
 *      "bridge" codes, still work, so this is a pause on one route and not a lock on the account.
 *   3. WHO MANAGES ROUTES (option zdz_signin_managers): named once on the page by an administrator signed in as
 *      themselves. An administrator role alone is not enough (an account in Privacy View can hold one). Never the
 *      shared device, an account in Privacy View, View As or Demo Mode. Until someone is named nobody can give a
 *      code.
 *   4. THE SECOND ISSUER (option zdz_signin_issuer): one account that may give codes, to everyone except
 *      administrators (the default) or to anyone. It cannot change addresses, managers or itself.
 *      On a WordPress network (multisite) an account belongs to every site of it, so there only a network
 *      administrator manages routes or sets an address, and there is no second issuer.
 *   5. BACKUP ADDRESSES (user meta zdz_login_aliases). This class cleans a typed list and says which entries it
 *      refused and why; "tested" means a code emailed to that address really signed the person in once
 *      (zdz_signin_seen).
 *   6. THE PAGE (Zorderz → Sign-in routes) and its two logs: what people did on the page (zdz_signin_log) and what
 *      happened at the login screen (zdz_signin_events). Who, what, when: never a code, never an address.
 *
 * No code is ever written to a log, an option, a notice or a reply other than the one page response that shows an
 * issued code to the person who pressed the button.
 *
 * What a site may set (each tunes or narrows; none gives a route to an account that gets none):
 *   - wp-config.php: define( 'ZDZ_SIGNIN_MANAGER', <user id> ) names a manager whatever the page holds. It is the
 *     way back when the named manager can no longer manage;
 *   - filter zdz_signin_issued_ttl: how long a given code lasts, in seconds (600; held between 2 and 60 minutes);
 *   - filter zdz_signin_day_misses: wrong six-digit tries an account takes in a day before emailed codes pause
 *     (50; held between 10 and 200);
 *   - option or filter zdz_signin_shared_outage_domains, or profile.yml email.shared_outage_domains in an Identity
 *     Pack: the public mail domains of the service that hosts the work mailboxes (none by default). A backup
 *     address there may be down whenever the work mailbox is, and the page says so beside it.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Signin_Routes {

	const PAGE  = 'zdz-signin-routes';
	const NONCE = 'zdz_signin_routes';

	const META_CODES   = 'zdz_signin_codes';
	const META_SENDS   = 'zdz_signin_sends';
	const META_SEEN    = 'zdz_signin_seen';
	const META_LAST    = 'zdz_signin_last';
	const META_NOTED   = 'zdz_signin_noted';
	const META_FORM    = 'zdz_signin_form';
	const META_MISSES  = 'zdz_signin_misses';   // wrong six-digit tries against live codes today: n, since
	const META_ALIASES = 'zdz_login_aliases';

	const OPT_MANAGERS = 'zdz_signin_managers';
	const OPT_ISSUER   = 'zdz_signin_issuer';
	const OPT_LOG      = 'zdz_signin_log';      // what people did on the page: kept apart, so nothing a stranger can cause pushes it out
	const OPT_EVENTS   = 'zdz_signin_events';   // what happened at the login screen (a backup address used, a code cancelled, a send refused)
	const OPT_SHARED   = 'zdz_signin_shared_outage_domains';

	const ISSUED_TTL   = 600;   // a code a person gives: 10 minutes unless a site changes it (issued_ttl())
	const TRIES_MAIL   = 5;     // wrong six-digit tries an emailed or "bridge" code survives
	const TRIES_ISSUED = 300;   // wrong eight-digit tries a given code survives: 300 in 90 million is 1 in 300,000, and a stranger can't cancel it with a handful
	const WINDOW       = 900;   // 15 minutes: the span sends are counted over
	const MAX_SENDS    = 6;     // codes emailed to ONE address of one account in WINDOW
	const PER_ADDRESS  = 6;     // emailed codes kept live per address: MAX_SENDS, so no ask inside the cap pushes out a live code
	const MAX_BRIDGE   = 3;     // "bridge" codes kept live per account (the newest)
	const DAY_MISSES   = 50;    // wrong six-digit tries against an account's live codes in DAY before emailed codes pause
	const DAY          = 86400;
	const LOG_MAX      = 300;
	const EVENTS_MAX   = 100;
	const PEOPLE_MAX   = 500;   // accounts the page lists
	const LOCK_STALE   = 10;    // seconds: an options-row lock older than this was left by a request that died (lock())

	/** Tests set this; 0 means the clock. */
	public static $now = 0;

	/** The one-press token of this showing of the page (render(), form_open()). */
	private static $form_token = '';

	/** Did a notice reach the account the last set_issuer() named? The page says so, or gives the address to pass on. */
	private static $issuer_told = false;

	/** How this request holds each account's lock: 'mysql' (GET_LOCK) or 'row' (the options-row fallback). */
	private static $held = array();

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ), 22 );
		add_action( 'admin_notices', array( __CLASS__, 'refused_notice' ) );
		add_action( 'zdz_register_alert_types', array( __CLASS__, 'register_alert_type' ) );
		add_filter( 'zdz_identity_pack_consumers', array( __CLASS__, 'pack_consumer' ) );
		add_filter( 'zdz_export_secret_markers', array( __CLASS__, 'export_secret_markers' ) );
		add_filter( 'zdz_export_non_portable_option_patterns', array( __CLASS__, 'non_portable_options' ) );
	}

	private static function now() {
		return self::$now > 0 ? (int) self::$now : time();
	}

	/** The account, or null when there is none. */
	private static function account( $uid ) {
		$u = (int) $uid > 0 ? get_user_by( 'id', (int) $uid ) : false;
		return $u instanceof WP_User ? $u : null;
	}

	/** The account's own sign-in address, lower-cased ('' when there is no such account). */
	private static function email_of( $uid ) {
		$u = self::account( $uid );
		return $u ? strtolower( trim( (string) $u->user_email ) ) : '';
	}

	/* ================================================================== codes */

	private static function hash_of( $salt, $uid, $code ) {
		return hash( 'sha256', (string) $salt . '|' . (int) $uid . '|' . (string) $code );
	}

	/**
	 * The code a person gives: EIGHT digits, never starting with 0. An emailed code has six. The two lengths are
	 * counted apart (claim()), so wrong six-digit tries a stranger sends at a known address can't cancel a code the
	 * manager has just read out, and eight digits leave a guesser 300 tries in 90 million.
	 */
	public static function new_code() {
		return (string) random_int( 10000000, 99999999 );
	}

	/** How long a code a person gives lasts, in seconds: 10 minutes unless a site changes it; never under 2 minutes or over 60. */
	public static function issued_ttl() {
		return max( 120, min( 3600, (int) apply_filters( 'zdz_signin_issued_ttl', self::ISSUED_TTL ) ) );
	}

	/** Six digits is an emailed or "bridge" code; eight is one a person gave. Anything else is no code. */
	private static function class_of( $code ) {
		$n = strlen( (string) $code );
		return 6 === $n ? 'short' : ( 8 === $n ? 'issued' : '' );
	}

	/** This account's codes that have not run out. */
	private static function live_codes( $uid ) {
		$all = get_user_meta( (int) $uid, self::META_CODES, true );
		$out = array();
		foreach ( is_array( $all ) ? $all : array() as $r ) {
			if ( is_array( $r ) && isset( $r['h'], $r['s'], $r['exp'], $r['kind'], $r['left'] ) && (int) $r['exp'] > self::now() && (int) $r['left'] > 0 ) {
				$out[] = $r;
			}
		}
		return $out;
	}

	private static function save_codes( $uid, array $codes ) {
		if ( $codes ) {
			update_user_meta( (int) $uid, self::META_CODES, array_values( $codes ) );
		} else {
			delete_user_meta( (int) $uid, self::META_CODES );
		}
	}

	/**
	 * One writer at a time per account, so two requests at once can't both use one code or share one wrong try.
	 * MySQL's GET_LOCK: held by this request's connection, given up when the connection ends (so a request that dies
	 * leaves nothing behind), waited for up to 1 second without polling. A database with no GET_LOCK (SQLite, for
	 * one) answers nothing: then the lock is one options row per account (row_lock()), the same fallback
	 * ZDZ_Token_Service uses. A lock another request holds ('0') is never taken that way. WordPress loads a user's
	 * meta into the request's cache as soon as the user is looked up, so the copy read before the lock is dropped once
	 * it is held. False when it can't be had (the caller then refuses). With no database object there is nothing to
	 * lock against.
	 */
	private static function lock( $uid ) {
		global $wpdb;
		$uid = (int) $uid;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return true;
		}
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::lock_name( $uid ), 1 ) );
		if ( '1' === (string) $got ) {
			self::$held[ $uid ] = 'mysql';
		} elseif ( null === $got && self::row_lock( $uid ) ) {
			self::$held[ $uid ] = 'row';
		} else {
			return false;
		}
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $uid, 'user_meta' );
		}
		return true;
	}

	private static function unlock( $uid ) {
		global $wpdb;
		$uid  = (int) $uid;
		$mode = self::$held[ $uid ] ?? '';
		unset( self::$held[ $uid ] );
		if ( 'mysql' === $mode ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name( $uid ) ) );
		} elseif ( 'row' === $mode ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::lock_row( $uid ) ) );
		}
	}

	/**
	 * The fallback lock: an options row per account. option_name is unique, so of two requests only one can make the
	 * row (INSERT IGNORE). It is tried for up to a second, like GET_LOCK's wait. A row older than LOCK_STALE seconds
	 * was left by a request that died (a row, unlike GET_LOCK, outlives its request): it is removed, and only if it is
	 * still that same row. On a WordPress network the row is one site's, while an account is the network's: there,
	 * use a database with GET_LOCK.
	 */
	private static function row_lock( $uid ) {
		global $wpdb;
		if ( ! method_exists( $wpdb, 'query' ) || empty( $wpdb->options ) ) {
			return false;
		}
		$row = self::lock_row( $uid );
		for ( $try = 0; $try < 10; $try++ ) {
			$now  = self::now();
			$made = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $row, (string) $now ) );
			if ( 1 === (int) $made ) {
				return true;
			}
			$since = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $row ) );
			if ( null !== $since && $now - (int) $since > self::LOCK_STALE ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $row, (string) $since ) );
				continue;
			}
			usleep( 100000 );
		}
		return false;
	}

	/** The fallback lock's options row for one account. */
	private static function lock_row( $uid ) {
		return 'zdz_signin_lock_' . (int) $uid;
	}

	/**
	 * MySQL's named locks belong to the whole server, and user meta to the whole install: so the name carries the
	 * database and the install's base prefix (not one site's of a network), and the account. At most 64 characters.
	 */
	private static function lock_name( $uid ) {
		global $wpdb;
		$base = is_object( $wpdb ) ? (string) ( $wpdb->base_prefix ?? ( $wpdb->prefix ?? '' ) ) : '';
		return 'zdz_signin_' . substr( md5( ( defined( 'DB_NAME' ) ? (string) DB_NAME : '' ) . '|' . $base ), 0, 12 ) . '_' . (int) $uid;
	}

	/** Run $fn holding the account's lock; the lock is given up whatever $fn does. $otherwise when it can't be had. */
	private static function locked( $uid, callable $fn, $otherwise = null ) {
		if ( ! self::lock( $uid ) ) {
			return $otherwise;
		}
		try {
			return $fn();
		} finally {
			self::unlock( $uid );
		}
	}

	/**
	 * Keep a code for one account. $kind: mail | bridge (six digits) or issued (eight). $more: 'to' (the address that
	 * was TYPED to ask for an emailed code: the claim must carry the same one), 'sent' (the address the email really
	 * goes to, when that is not the one typed), 'tok' (a bridge token to retire with it), 'by' (who gave it). An
	 * issued code replaces any earlier one. Emailed codes keep the newest PER_ADDRESS for each typed address, so asks
	 * made with one address never push out a code asked for with another; "bridge" codes keep their own newest
	 * MAX_BRIDGE. The code itself is not stored. False when it was not kept: the caller must then not hand the code
	 * out.
	 */
	public static function put_code( $uid, $code, $kind, $ttl, array $more = array() ) {
		$uid  = (int) $uid;
		$code = (string) preg_replace( '/\D/', '', (string) $code );
		$kind = in_array( $kind, array( 'mail', 'bridge', 'issued' ), true ) ? $kind : '';
		if ( $uid <= 0 || '' === $kind || (int) $ttl <= 0 || self::class_of( $code ) !== ( 'issued' === $kind ? 'issued' : 'short' ) ) {
			return false;
		}
		if ( 'issued' === $kind && ! self::gets_routes( $uid ) ) {
			return false;   // the shared device and an account in Privacy View get no route
		}
		$salt = bin2hex( random_bytes( 8 ) );
		$rec  = array(
			's'    => $salt,
			'h'    => self::hash_of( $salt, $uid, $code ),
			'exp'  => self::now() + (int) $ttl,
			'kind' => $kind,
			'left' => 'issued' === $kind ? self::TRIES_ISSUED : self::TRIES_MAIL,
			'to'   => 'mail' === $kind && isset( $more['to'] ) && is_string( $more['to'] ) ? strtolower( trim( $more['to'] ) ) : '',
		);
		if ( 'mail' === $kind && '' === $rec['to'] ) {
			return false;   // an emailed code with no address could be claimed with none
		}
		if ( isset( $more['tok'] ) && is_string( $more['tok'] ) && '' !== $more['tok'] ) {
			$rec['tok'] = $more['tok'];
		}
		if ( 'mail' === $kind ) {
			// Where the email really goes. A login plugin's own form mails the ACCOUNT address whatever was typed, so
			// a sign-in with that code proves nothing about a backup mailbox (after_sign_in()).
			$rec['sent'] = isset( $more['sent'] ) && is_string( $more['sent'] ) && '' !== trim( $more['sent'] ) ? strtolower( trim( $more['sent'] ) ) : $rec['to'];
		}
		if ( ! empty( $more['by'] ) ) {
			$rec['by'] = (int) $more['by'];
		}
		return (bool) self::locked(
			$uid,
			function () use ( $uid, $kind, $rec ) {
				$keep = array();
				$same = array();
				foreach ( self::live_codes( $uid ) as $r ) {
					if ( $r['kind'] === $kind && ( 'mail' !== $kind || (string) ( $r['to'] ?? '' ) === $rec['to'] ) ) {
						$same[] = $r;
					} else {
						$keep[] = $r;
					}
				}
				$room = 'issued' === $kind ? 0 : ( 'mail' === $kind ? self::PER_ADDRESS - 1 : self::MAX_BRIDGE - 1 );
				$same = $room > 0 ? array_slice( $same, -$room ) : array();
				self::save_codes( $uid, array_merge( $keep, $same, array( $rec ) ) );
				return true;
			},
			false
		);
	}

	/**
	 * A claim: the email AND the code. Returns array( 'user' => WP_User, 'rec' => the code's record ) once, or null.
	 * Null says nothing about why (an unknown address, a wrong code and a used-up code look the same).
	 *
	 * Which codes a claim is weighed against, and which a wrong one costs a try, are the same set:
	 *   six digits   -> the codes asked for with the address typed now, and the "bridge" codes;
	 *   eight digits -> the code a person gave.
	 * So a stranger typing the work address can use up only what was asked for with the work address. There is no
	 * lock on the account: the person asks for a new code and it works. (A lock on the account after a few wrong
	 * tries, or a try charged to every six-digit code the account holds, would let a stranger who knows one address
	 * keep its owner out.)
	 */
	public static function claim( $email, $code ) {
		$code  = (string) preg_replace( '/\D/', '', (string) $code );
		$class = self::class_of( $code );
		$typed = strtolower( trim( (string) $email ) );
		$user  = class_exists( 'ZDZ_Magic_Link_Bridge' ) ? ZDZ_Magic_Link_Bridge::resolve_login_user( (string) $email ) : null;
		if ( ! ( $user instanceof WP_User ) || '' === $class ) {
			return null;
		}
		$uid    = (int) $user->ID;
		$dead   = array();
		$paused = false;
		$won    = self::locked(
			$uid,
			function () use ( $uid, $code, $class, $typed, &$dead, &$paused ) {
				$codes = self::live_codes( $uid );
				$mine  = array();   // the codes this claim is weighed against
				foreach ( $codes as $i => $r ) {
					$fits = 'issued' === $class
						? 'issued' === $r['kind']
						: ( 'bridge' === $r['kind'] || ( 'mail' === $r['kind'] && $typed === (string) ( $r['to'] ?? '' ) ) );
					if ( $fits ) {
						$mine[] = $i;
					}
				}
				$hit = -1;
				foreach ( $mine as $i ) {
					if ( hash_equals( (string) $codes[ $i ]['h'], self::hash_of( $codes[ $i ]['s'], $uid, $code ) ) ) {
						$hit = $i;
					}
				}
				if ( $hit >= 0 ) {
					$rec = $codes[ $hit ];
					unset( $codes[ $hit ] );
					self::save_codes( $uid, $codes );
					return $rec;
				}
				foreach ( $mine as $i ) {
					$codes[ $i ]['left'] = (int) $codes[ $i ]['left'] - 1;
					if ( $codes[ $i ]['left'] <= 0 ) {
						$dead[] = $codes[ $i ];
						unset( $codes[ $i ] );
					}
				}
				if ( $mine ) {
					self::save_codes( $uid, $codes );
				}
				if ( $mine && 'issued' !== $class ) {
					// a wrong six-digit try against a live code: counted for the day (a try with no live code to
					// weigh against cannot succeed, so it is not counted and cannot be used to pause someone's codes)
					$m      = self::misses( $uid );
					$m['n'] = $m['n'] + 1;
					update_user_meta( $uid, self::META_MISSES, $m );
					$paused = self::day_misses() === $m['n'];
				}
				return null;
			}
		);
		if ( is_array( $won ) ) {
			return array(
				'user' => $user,
				'rec'  => $won,
			);
		}
		if ( $paused ) {
			self::event( 'codes_paused', $uid );
			self::notify_managers(
				__( 'Emailed sign-in codes paused', 'zorderz' ),
				/* translators: 1: a person's name, 2: a number of tries */
				sprintf( __( '%1$s: %2$d wrong sign-in codes were tried today, so no more codes are emailed to them until the day is over. You can give them a one-time code on Sign-in routes.', 'zorderz' ), self::name_of( $uid ), self::day_misses() )
			);
		}
		foreach ( $dead as $r ) {
			if ( 'issued' === $r['kind'] ) {
				self::log( 'issued_killed', $uid, (int) ( $r['by'] ?? 0 ) );
				if ( self::once_a_window( $uid, 'issued_killed' ) ) {
					self::notify_managers(
						__( 'A sign-in code was cancelled', 'zorderz' ),
						/* translators: 1: a person's name, 2: a number of tries */
						sprintf( __( 'The one-time code given to %1$s was cancelled after %2$d wrong tries. Check with them before giving another.', 'zorderz' ), self::name_of( $uid ), self::TRIES_ISSUED )
					);
				}
			} elseif ( self::once_a_window( $uid, 'killed' ) ) {
				self::event( 'code_killed', $uid );
				self::notify_managers(
					__( 'Wrong sign-in codes', 'zorderz' ),
					/* translators: 1: a person's name, 2: a number of tries */
					sprintf( __( '%1$s: %2$d wrong sign-in codes were tried, so an emailed code was cancelled. They can ask for a new one.', 'zorderz' ), self::name_of( $uid ), self::TRIES_MAIL )
				);
			}
		}
		return null;
	}

	/** True the first time in WINDOW this is asked for this account and word; false after (so a stranger can't fill the page or the bell). */
	private static function once_a_window( $uid, $word ) {
		$n = get_user_meta( (int) $uid, self::META_NOTED, true );
		$n = is_array( $n ) ? $n : array();
		if ( (int) ( $n[ $word ] ?? 0 ) + self::WINDOW > self::now() ) {
			return false;
		}
		$n[ $word ] = self::now();
		update_user_meta( (int) $uid, self::META_NOTED, $n );
		return true;
	}

	/** Wrong six-digit tries an account may take in a day before emailed codes pause (a site may tune it, within bounds). */
	public static function day_misses() {
		$n = function_exists( 'apply_filters' ) ? (int) apply_filters( 'zdz_signin_day_misses', self::DAY_MISSES ) : self::DAY_MISSES;
		return max( 10, min( 200, $n ) );
	}

	/** This account's wrong six-digit tries in the current day: 'n' and 'since' (a fresh day once DAY has passed). */
	private static function misses( $uid ) {
		$m = get_user_meta( (int) $uid, self::META_MISSES, true );
		if ( ! is_array( $m ) || (int) ( $m['since'] ?? 0 ) + self::DAY <= self::now() ) {
			return array(
				'n'     => 0,
				'since' => self::now(),
			);
		}
		return array(
			'n'     => (int) ( $m['n'] ?? 0 ),
			'since' => (int) $m['since'],
		);
	}

	/** Has this account taken a day's worth of wrong six-digit tries? Then no code is emailed to it until the day is over. */
	public static function codes_paused( $uid ) {
		return self::misses( (int) $uid )['n'] >= self::day_misses();
	}

	/**
	 * May another code be emailed for this typed address of this account now? Counts the ask. At most MAX_SENDS for
	 * one address in WINDOW. Counted per address, so asks a stranger makes with the work address never use up the
	 * backup address's. A refusal is one line on the page per account per WINDOW.
	 */
	public static function may_send( $uid, $to ) {
		$uid = (int) $uid;
		if ( self::codes_paused( $uid ) ) {
			return false;   // a day's worth of wrong tries: the managers were told when it began
		}
		$key = md5( strtolower( trim( (string) $to ) ) );
		$ok  = (bool) self::locked(
			$uid,
			function () use ( $uid, $key ) {
				$all = get_user_meta( $uid, self::META_SENDS, true );
				$all = is_array( $all ) ? $all : array();
				foreach ( $all as $k => $s ) {
					if ( ! is_array( $s ) || (int) ( $s['since'] ?? 0 ) + self::WINDOW <= self::now() ) {
						unset( $all[ $k ] );
					}
				}
				$s = $all[ $key ] ?? array(
					'n'     => 0,
					'since' => self::now(),
				);
				if ( (int) $s['n'] >= self::MAX_SENDS ) {
					return false;
				}
				$s['n']      = (int) $s['n'] + 1;
				$all[ $key ] = $s;
				update_user_meta( $uid, self::META_SENDS, $all );
				return true;
			},
			false
		);
		if ( ! $ok && self::once_a_window( $uid, 'send_refused' ) ) {
			self::event( 'send_refused', $uid );
		}
		return $ok;
	}

	/** The site could not hand a sign-in email to its mailer. Recorded for the page; the login screen says nothing. */
	public static function mail_failed( $uid ) {
		if ( self::once_a_window( (int) $uid, 'mail_failed' ) ) {
			self::event( 'mail_failed', (int) $uid );
		}
	}

	/** After a claim signed someone in: remember how, mark a backup address tested, tell who should know. */
	public static function after_sign_in( $user, array $rec ) {
		if ( ! ( $user instanceof WP_User ) ) {
			return;
		}
		$uid  = (int) $user->ID;
		$kind = (string) ( $rec['kind'] ?? '' );
		$to   = (string) ( $rec['sent'] ?? '' );   // the mailbox the code really arrived in
		$last = array(
			't'     => self::now(),
			'route' => $kind,
		);
		if ( 'issued' === $kind ) {
			$by         = (int) ( $rec['by'] ?? 0 );
			$last['by'] = $by;
			self::log( 'issued_used', $uid, $by );
			/* translators: %s: the name of the person who gave the code */
			self::notify( $uid, __( 'You signed in with a code', 'zorderz' ), sprintf( __( 'You signed in with a one-time code from %s.', 'zorderz' ), self::name_of( $by ) ) );
			/* translators: 1: the person who signed in, 2: the person who gave the code */
			self::notify_managers( __( 'A sign-in code was used', 'zorderz' ), sprintf( __( '%1$s signed in with the one-time code from %2$s.', 'zorderz' ), self::name_of( $uid ), self::name_of( $by ) ), $by );
		} elseif ( 'mail' === $kind && '' !== $to && strtolower( trim( (string) $user->user_email ) ) !== $to ) {
			$last['route'] = 'backup';
			$seen          = get_user_meta( $uid, self::META_SEEN, true );
			$seen          = is_array( $seen ) ? $seen : array();
			$seen[ $to ]   = self::now();
			update_user_meta( $uid, self::META_SEEN, $seen );
			self::event( 'backup_used', $uid );
			/* translators: %s: a person's name */
			self::notify_managers( __( 'A backup address was used', 'zorderz' ), sprintf( __( '%s signed in with a code sent to a backup address.', 'zorderz' ), self::name_of( $uid ) ) );
		}
		update_user_meta( $uid, self::META_LAST, $last );
	}

	/* ================================================================== who */

	/** The shared device: never a person, so never given a route. The platform's trait decides. */
	private static function is_shared_device( $uid ) {
		if ( class_exists( 'ZDZ_Hierarchy' ) ) {
			return ZDZ_Hierarchy::is_kiosk( (int) $uid );
		}
		if ( class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) ) {
			return ZDZ_User_Roles::is_shared_device( (int) $uid );
		}
		$u = self::account( $uid );
		return $u && in_array( 'zdz_general', (array) $u->roles, true );
	}

	private static function is_privacy_view( $uid ) {
		return class_exists( 'ZDZ_Privacy_View' ) && ZDZ_Privacy_View::is_on( (int) $uid );
	}

	private static function is_admin_tier( $uid ) {
		if ( class_exists( 'ZDZ_Hierarchy' ) ) {
			return ZDZ_Hierarchy::is_admin( (int) $uid );
		}
		return function_exists( 'user_can' ) && user_can( (int) $uid, 'manage_options' );
	}

	/**
	 * Is this install a WordPress network (multisite)? There an account, and everything kept with it, belongs to the
	 * whole network, while this page belongs to one site of it. A one-time code or a backup address is a way into
	 * an account whatever it holds on the other sites, so on a network both are a network administrator's alone:
	 * only one can manage sign-in routes or set an address, and there is no second issuer.
	 */
	private static function is_network() {
		return function_exists( 'is_multisite' ) && is_multisite();
	}

	/** On a network: a network administrator's account. On a single site there is none. */
	private static function is_network_admin( $uid ) {
		return self::is_network() && function_exists( 'is_super_admin' ) && is_super_admin( (int) $uid );
	}

	/** Does this account get backup routes at all? Never the shared device, never an account in Privacy View. */
	public static function gets_routes( $uid ) {
		$uid = (int) $uid;
		return $uid > 0 && null !== self::account( $uid ) && ! self::is_shared_device( $uid ) && ! self::is_privacy_view( $uid );
	}

	/**
	 * Is $uid the person at this device, acting as themselves? Not the shared device, not an account in Privacy View,
	 * not while View As or Demo Mode is on. (A request that is not $uid's own can't act for $uid.)
	 */
	public static function acting_ok( $uid ) {
		$uid = (int) $uid;
		if ( $uid <= 0 || $uid !== (int) get_current_user_id() || ! self::gets_routes( $uid ) ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Kiosk_Demo' ) && ZDZ_Kiosk_Demo::is_active() ) {
			return false;
		}
		if ( class_exists( 'ZDZ_View_As' ) ) {
			// a role, or (with no role chosen: after a reset, or viewing an administrator) one person
			if ( ZDZ_View_As::is_emulating() || ( method_exists( 'ZDZ_View_As', 'get_emulated_user_id' ) && ZDZ_View_As::get_emulated_user_id() ) ) {
				return false;
			}
		}
		return true;
	}

	/** Could this account be named a manager? An administrator who gets routes; on a network, a network administrator (is_network()). */
	public static function eligible_manager( $uid ) {
		return self::gets_routes( $uid ) && self::is_admin_tier( $uid ) && ( ! self::is_network() || self::is_network_admin( $uid ) );
	}

	/**
	 * Has anyone ever been named? A manager who is later deleted, demoted or put in Privacy View does NOT re-open
	 * "I manage sign-in routes" to the next administrator: that would hand the page, and a code for any account, to
	 * whoever pressed first. The way back is the wp-config.php line (pinned_manager()).
	 */
	public static function ever_named() {
		$ids = get_option( self::OPT_MANAGERS, array() );
		return ( is_array( $ids ) && $ids ) || self::pinned_manager() > 0;
	}

	private static function stored_managers() {
		$ids = get_option( self::OPT_MANAGERS, array() );
		return is_array( $ids ) ? array_values( array_unique( array_map( 'intval', $ids ) ) ) : array();
	}

	/** define( 'ZDZ_SIGNIN_MANAGER', <user id> ) in wp-config.php names the manager whatever the page holds. 0 if not set. */
	public static function pinned_manager() {
		return defined( 'ZDZ_SIGNIN_MANAGER' ) ? (int) constant( 'ZDZ_SIGNIN_MANAGER' ) : 0;
	}

	/** The accounts that manage sign-in routes (still eligible). */
	public static function managers() {
		$ids = self::stored_managers();
		if ( self::pinned_manager() > 0 ) {
			$ids[] = self::pinned_manager();
		}
		$out = array();
		foreach ( $ids as $id ) {
			if ( self::eligible_manager( (int) $id ) ) {
				$out[] = (int) $id;
			}
		}
		return array_values( array_unique( $out ) );
	}

	public static function may_manage( $uid ) {
		return self::acting_ok( $uid ) && in_array( (int) $uid, self::managers(), true );
	}

	/** Nobody has ever been named, and this administrator could be. */
	public static function may_claim( $uid ) {
		return ! self::ever_named() && self::acting_ok( $uid ) && self::eligible_manager( $uid );
	}

	/** The second issuer: array( 'uid' => int, 'scope' => 'non_admin' | 'anyone' ), or null. Never a manager. None on a network (is_network()). */
	public static function issuer() {
		$o   = get_option( self::OPT_ISSUER, array() );
		$uid = is_array( $o ) ? (int) ( $o['uid'] ?? 0 ) : 0;
		if ( $uid <= 0 || self::is_network() || ! self::gets_routes( $uid ) || in_array( $uid, self::managers(), true ) ) {
			return null;
		}
		return array(
			'uid'   => $uid,
			'scope' => 'anyone' === ( $o['scope'] ?? '' ) ? 'anyone' : 'non_admin',
		);
	}

	public static function is_issuer( $uid ) {
		$i = self::issuer();
		return $i && (int) $uid === $i['uid'] && self::acting_ok( $uid );
	}

	/** May $actor give $target a one-time code? */
	public static function may_issue_for( $actor, $target ) {
		$actor  = (int) $actor;
		$target = (int) $target;
		if ( $actor === $target || ! self::gets_routes( $target ) ) {
			return false;
		}
		if ( self::may_manage( $actor ) ) {
			return true;
		}
		if ( ! self::is_issuer( $actor ) ) {
			return false;
		}
		$i = self::issuer();
		return 'anyone' === $i['scope'] || ! self::is_admin_tier( $target );
	}

	public static function may_open( $uid ) {
		return self::may_manage( $uid ) || self::may_claim( $uid ) || self::is_issuer( $uid );
	}

	/**
	 * Nobody can manage any more: the account that was named can't, or wp-config.php names one that can't. An
	 * administrator who could manage is then shown why, and the way back (render_refusal()): no list, no form, no act.
	 */
	public static function shows_way_back( $uid ) {
		return self::ever_named() && ! self::managers() && self::acting_ok( $uid ) && self::eligible_manager( $uid );
	}

	/* ================================================================== acts */

	/**
	 * Give $target a one-time code. The code (shown once, by the caller), or a WP_Error. While a code is live for
	 * that account a second one is given only with $replace (the page's "Give a new code"), so refreshing the page
	 * that shows a code does not silently swap it for another.
	 */
	public static function issue( $actor, $target, $replace = false ) {
		$actor  = (int) $actor;
		$target = (int) $target;
		if ( ! self::may_issue_for( $actor, $target ) ) {
			return new WP_Error( 'zdz_signin_refused', __( 'You can\'t give a sign-in code for that account.', 'zorderz' ) );
		}
		$left = self::issued_left( $target );
		if ( $left > 0 && ! $replace ) {
			/* translators: %d: minutes */
			return new WP_Error( 'zdz_signin_live', sprintf( __( 'A code is already live for that account, for %d more min. Press "Give a new code" to replace it.', 'zorderz' ), (int) ceil( $left / 60 ) ) );
		}
		if ( $left > 0 && ! self::may_manage( $actor ) && self::issued_by( $target ) !== $actor ) {
			return new WP_Error( 'zdz_signin_refused', __( 'A code someone else gave is live for that account. Only they, or whoever manages sign-in routes, can replace it.', 'zorderz' ) );
		}
		$code = self::new_code();
		if ( ! self::put_code( $target, $code, 'issued', self::issued_ttl(), array( 'by' => $actor ) ) ) {
			return new WP_Error( 'zdz_signin_refused', __( 'That code could not be kept. Try again.', 'zorderz' ) );
		}
		self::log( 'issued', $target, $actor );
		/* translators: 1: the person who gave the code, 2: the person it is for */
		self::notify_managers( __( 'A sign-in code was given', 'zorderz' ), sprintf( __( '%1$s gave %2$s a one-time sign-in code.', 'zorderz' ), self::name_of( $actor ), self::name_of( $target ) ), $actor );
		return $code;
	}

	/** Take back a code that was given and not yet used. The second issuer: only one they gave. */
	public static function cancel_issued( $actor, $target ) {
		$actor  = (int) $actor;
		$target = (int) $target;
		if ( ! self::may_issue_for( $actor, $target ) || ( ! self::may_manage( $actor ) && self::issued_by( $target ) !== $actor ) ) {
			return false;
		}
		$had = (bool) self::locked(
			$target,
			function () use ( $target ) {
				$keep = array();
				$had  = false;
				foreach ( self::live_codes( $target ) as $r ) {
					if ( 'issued' === $r['kind'] ) {
						$had = true;
					} else {
						$keep[] = $r;
					}
				}
				if ( $had ) {
					self::save_codes( $target, $keep );
				}
				return $had;
			},
			false
		);
		if ( $had ) {
			self::log( 'issued_cancelled', $target, $actor );
		}
		return $had;
	}

	/** Who gave the code that is live for this account (a user id), or 0. */
	public static function issued_by( $uid ) {
		foreach ( self::live_codes( $uid ) as $r ) {
			if ( 'issued' === $r['kind'] ) {
				return (int) ( $r['by'] ?? 0 );
			}
		}
		return 0;
	}

	/** Seconds an issued code still has, or 0. */
	public static function issued_left( $uid ) {
		foreach ( self::live_codes( $uid ) as $r ) {
			if ( 'issued' === $r['kind'] ) {
				return max( 0, (int) $r['exp'] - self::now() );
			}
		}
		return 0;
	}

	/** "I manage sign-in routes": once, by an administrator acting as themselves, while nobody has ever been named. */
	public static function claim_management( $uid ) {
		if ( ! self::may_claim( $uid ) ) {
			return false;
		}
		update_option( self::OPT_MANAGERS, array( (int) $uid ), false );
		self::log( 'manager_set', (int) $uid, (int) $uid );
		// every other administrator is told, so a press by the wrong person does not pass unseen
		foreach ( self::people() as $u ) {
			if ( (int) $u->ID !== (int) $uid && self::eligible_manager( (int) $u->ID ) ) {
				/* translators: %s: a person's name */
				self::notify( (int) $u->ID, __( 'Sign-in routes have a manager', 'zorderz' ), sprintf( __( '%s now manages sign-in routes (backup addresses and one-time sign-in codes).', 'zorderz' ), self::name_of( $uid ) ) );
			}
		}
		return true;
	}

	public static function add_manager( $actor, $uid ) {
		if ( ! self::may_manage( $actor ) || ! self::eligible_manager( $uid ) ) {
			return false;
		}
		if ( ! in_array( (int) $uid, self::managers(), true ) ) {
			$m   = self::stored_managers();   // never the wp-config.php one: taking that line out must take them out
			$m[] = (int) $uid;
			update_option( self::OPT_MANAGERS, $m, false );
			self::log( 'manager_set', (int) $uid, (int) $actor );
		}
		return true;
	}

	/** Remove a manager. Never the last one, and never the one wp-config.php names. The person removed is told. */
	public static function remove_manager( $actor, $uid ) {
		$uid = (int) $uid;
		$m   = self::managers();
		if ( ! self::may_manage( $actor ) || count( $m ) < 2 || ! in_array( $uid, $m, true ) || $uid === self::pinned_manager() ) {
			return false;
		}
		update_option( self::OPT_MANAGERS, array_values( array_diff( self::stored_managers(), array( $uid ) ) ), false );
		self::log( 'manager_removed', $uid, (int) $actor );
		/* translators: %s: a person's name */
		self::notify( $uid, __( 'You no longer manage sign-in routes', 'zorderz' ), sprintf( __( '%s removed you from the people who manage sign-in routes.', 'zorderz' ), self::name_of( $actor ) ) );
		return true;
	}

	/** Name (or clear, with $uid 0) the second issuer. $scope: 'non_admin' (the default) or 'anyone'. */
	public static function set_issuer( $actor, $uid, $scope = 'non_admin' ) {
		$uid               = (int) $uid;
		self::$issuer_told = false;
		if ( ! self::may_manage( $actor ) ) {
			return false;
		}
		if ( 0 === $uid ) {
			delete_option( self::OPT_ISSUER );
			self::log( 'issuer_removed', 0, (int) $actor );
			return true;
		}
		if ( self::is_network() || ! self::gets_routes( $uid ) || in_array( $uid, self::managers(), true ) ) {
			return false;
		}
		$scope = 'anyone' === $scope ? 'anyone' : 'non_admin';
		update_option(
			self::OPT_ISSUER,
			array(
				'uid'   => $uid,
				'scope' => $scope,
				'by'    => (int) $actor,
				't'     => self::now(),
			),
			false
		);
		self::log( 'issuer_set', $uid, (int) $actor, $scope );
		// The page is not on that account's menu unless it is an administrator's, so they are sent its address. A role
		// that gets no notices is not reached: the page then gives the manager the address to pass on.
		/* translators: %s: the address of the Sign-in routes page */
		self::$issuer_told = self::notify( $uid, __( 'You can give sign-in codes', 'zorderz' ), sprintf( __( 'When a teammate can\'t get their sign-in email, you can give them a one-time code: %s', 'zorderz' ), self::page_url() ), self::page_url() );
		return true;
	}

	/* ================================================================== backup addresses */

	public static function addresses( $uid ) {
		$a = get_user_meta( (int) $uid, self::META_ALIASES, true );
		return is_array( $a ) ? array_values( array_filter( array_map( 'strval', $a ) ) ) : array();
	}

	/** Who else holds this (lower-cased) address as a backup address? A user id, or 0. */
	private static function address_owner( $lc, $exclude ) {
		$ids = get_users(
			array(
				'meta_key' => self::META_ALIASES,   // only the accounts that hold a backup address, bounded by 'number'
				'fields'   => 'ID',
				'number'   => (int) apply_filters( 'zdz_login_alias_scan_cap', 500 ),
			)
		);
		foreach ( (array) $ids as $id ) {
			if ( (int) $id === (int) $exclude ) {
				continue;
			}
			if ( in_array( $lc, array_map( 'strtolower', self::addresses( (int) $id ) ), true ) ) {
				return (int) $id;
			}
		}
		return 0;
	}

	/**
	 * Read a typed list of addresses for one account. Returns array( kept (lower-cased), refused ), where refused is a
	 * list of array( what was typed, why ). An entry is kept when it is a valid email address that is not this
	 * account's own address, not another account's sign-in address and not another person's backup address; every
	 * entry that is not kept is named with its reason, never dropped without a word.
	 */
	public static function clean_addresses( $uid, $raw ) {
		$uid   = (int) $uid;
		$own   = self::email_of( $uid );
		$keep  = array();
		$no    = array();
		$parts = preg_split( '/[\s,;]+/', (string) $raw );
		foreach ( (array) $parts as $part ) {
			$part = trim( (string) $part );
			if ( '' === $part ) {
				continue;
			}
			$addr = sanitize_email( $part );
			if ( '' === $addr || ! is_email( $addr ) ) {
				$no[] = array( $part, __( 'is not an email address', 'zorderz' ) );
				continue;
			}
			$lc = strtolower( $addr );
			if ( '' !== $own && $lc === $own ) {
				$no[] = array( $part, __( 'is already this account\'s own address', 'zorderz' ) );
			} elseif ( in_array( $lc, $keep, true ) ) {
				continue;   // typed twice: kept once, nothing to say
			} elseif ( get_user_by( 'email', $addr ) ) {
				$no[] = array( $part, __( 'is another account\'s sign-in address', 'zorderz' ) );
			} elseif ( self::address_owner( $lc, $uid ) ) {
				$no[] = array( $part, __( 'is already another person\'s backup address', 'zorderz' ) );
			} else {
				$keep[] = $lc;
			}
		}
		return array( $keep, $no );
	}

	/**
	 * Save one account's backup addresses. Only a manager; before anyone has been named, an administrator. Returns
	 * the refused list, or null when nothing was saved.
	 */
	public static function save_addresses( $actor, $uid, $raw ) {
		$uid = (int) $uid;
		if ( ! self::may_edit_addresses( $actor ) || null === self::account( $uid ) ) {
			return null;
		}
		list( $keep, $no ) = self::clean_addresses( $uid, $raw );
		if ( ! self::gets_routes( $uid ) && $keep ) {
			return null;   // the shared device and an account in Privacy View: addresses they already have can be removed, never added
		}
		$before = array_map( 'strtolower', self::addresses( $uid ) );
		update_user_meta( $uid, self::META_ALIASES, $keep );
		$own = self::email_of( $uid );
		self::locked(
			$uid,
			function () use ( $uid, $own, $keep ) {   // a code asked for with an address that was just removed dies with it
				$live = array();
				foreach ( self::live_codes( $uid ) as $r ) {
					$to = (string) ( $r['to'] ?? '' );
					if ( 'mail' !== $r['kind'] || $to === $own || in_array( $to, $keep, true ) ) {
						$live[] = $r;
					}
				}
				self::save_codes( $uid, $live );
				return true;
			}
		);
		$seen = get_user_meta( $uid, self::META_SEEN, true );
		if ( is_array( $seen ) ) {
			update_user_meta( $uid, self::META_SEEN, array_intersect_key( $seen, array_flip( $keep ) ) );
		}
		if ( $before !== $keep ) {
			self::log( 'addresses_changed', $uid, (int) $actor, (string) count( $keep ) );
		}
		return $no;
	}

	/** May $actor change anyone's backup addresses? */
	public static function may_edit_addresses( $actor ) {
		if ( self::ever_named() ) {   // not managers(): a manager who can no longer manage does not hand this back to every administrator
			return self::may_manage( $actor );
		}
		if ( self::is_network() && ! self::is_network_admin( $actor ) ) {
			return false;   // on a network a backup address is a network administrator's to set (is_network())
		}
		return self::acting_ok( $actor ) && function_exists( 'user_can' ) && ( user_can( (int) $actor, 'edit_users' ) || user_can( (int) $actor, 'manage_options' ) );
	}

	/**
	 * Public mail domains run by the service that hosts the work mailboxes: a backup address at one of them may be
	 * down whenever the work mailbox is. None by default, because which service hosts a business's mail is the
	 * business's own. From the option (a list, or one comma-separated line; an Identity Pack writes it from
	 * profile.yml email.shared_outage_domains), then the filter of the same name. Lower-case whole domains only.
	 *
	 * @return string[]
	 */
	public static function shared_outage_domains() {
		$raw = get_option( self::OPT_SHARED, array() );
		$raw = is_string( $raw ) ? preg_split( '/[\s,;]+/', $raw ) : ( is_array( $raw ) ? $raw : array() );
		$raw = apply_filters( 'zdz_signin_shared_outage_domains', $raw );
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $d ) {
			$d = self::clean_domain( $d );
			if ( '' !== $d ) {
				$out[ $d ] = true;
			}
		}
		return array_keys( $out );
	}

	/** A whole mail domain in lower case (example.com), or '' for anything else. */
	private static function clean_domain( $d ) {
		$d = is_scalar( $d ) ? trim( strtolower( (string) $d ), " \t\n\r\0\x0B." ) : '';
		return preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/', $d ) ? $d : '';
	}

	/**
	 * What to say beside a backup address: 'same' (the account address's own mail domain: no help when that mailbox
	 * is down), 'shared' (a domain of the same mail service, shared_outage_domains()), or ''.
	 */
	public static function address_flag( $account_email, $addr ) {
		$d  = strtolower( (string) substr( strrchr( '@' . (string) $addr, '@' ), 1 ) );
		$wd = strtolower( (string) substr( strrchr( '@' . (string) $account_email, '@' ), 1 ) );
		if ( '' !== $d && $d === $wd ) {
			return 'same';
		}
		return '' !== $d && in_array( $d, self::shared_outage_domains(), true ) ? 'shared' : '';
	}

	/** When this backup address last signed the person in (a timestamp), or 0: "not yet tested". */
	public static function tested_at( $uid, $addr ) {
		$seen = get_user_meta( (int) $uid, self::META_SEEN, true );
		return is_array( $seen ) ? (int) ( $seen[ strtolower( (string) $addr ) ] ?? 0 ) : 0;
	}

	/**
	 * One account's cover when the work mailbox is down: 'none' (no backup address), 'untested' (one or more, none
	 * has signed them in yet), 'tested'. An address on the account address's own mail domain counts for nothing.
	 */
	public static function cover( $uid ) {
		$own  = self::email_of( $uid );
		$best = 'none';
		foreach ( self::addresses( $uid ) as $a ) {
			if ( '' !== $own && 'same' === self::address_flag( $own, $a ) ) {
				continue;
			}
			if ( self::tested_at( $uid, $a ) > 0 ) {
				return 'tested';
			}
			$best = 'untested';
		}
		return $best;
	}

	/* ================================================================== the Identity Pack's part */

	/** Registers profile.yml email.shared_outage_domains with the Identity Pack (preview, apply, snapshot, restore). */
	public static function pack_consumer( $consumers ) {
		$consumers = is_array( $consumers ) ? $consumers : array();
		if ( class_exists( 'ZDZ_Identity_Pack' ) && method_exists( 'ZDZ_Identity_Pack', 'option_consumer' ) ) {
			$consumers['signin-routes'] = ZDZ_Identity_Pack::option_consumer( __( 'Sign-in routes', 'zorderz' ), 'profile', array( 'email.shared_outage_domains' ), array( __CLASS__, 'plan_shared_outage_domains' ) );
		}
		return $consumers;
	}

	/**
	 * profile.yml email.shared_outage_domains -> the option shared_outage_domains() reads. Each entry must be a whole
	 * mail domain (example.com); one that is not is refused by name and the rest are kept.
	 */
	public static function plan_shared_outage_domains( array $data ) {
		$plan = ZDZ_Identity_Pack::plan();
		$path = 'email.shared_outage_domains';
		if ( ! array_key_exists( $path, $data ) ) {
			return $plan;
		}
		$ok = array();
		foreach ( ZDZ_Identity_Pack::as_list( $data[ $path ] ) as $raw ) {
			$d = self::clean_domain( $raw );
			if ( '' !== $d ) {
				$ok[ $d ] = true;
			} else {
				$plan['refused'][] = array(
					'path' => $path,
					'why'  => sprintf( '"%s" is not a mail domain (example.com).', is_scalar( $raw ) ? (string) $raw : gettype( $raw ) ),
				);
			}
		}
		if ( $ok ) {
			ZDZ_Identity_Pack::plan_option( $plan, $path, self::OPT_SHARED, array_keys( $ok ) );
		}
		return $plan;
	}

	/**
	 * A live sign-in code's record and the page's one-press token belong to this install's sign-ins. Neither travels
	 * in a Company Data export, and neither is taken from an import (ZDZ_Data_Portability reads these as secrets).
	 */
	public static function export_secret_markers( $markers ) {
		$markers   = is_array( $markers ) ? $markers : array();
		$markers[] = self::META_CODES;
		$markers[] = self::META_FORM;
		return $markers;
	}

	/** The fallback lock's rows (lock_row()) are this install's state: never exported, never imported. */
	public static function non_portable_options( $patterns ) {
		$patterns   = is_array( $patterns ) ? $patterns : array();
		$patterns[] = '/^zdz_signin_lock_\d+$/';
		return $patterns;
	}

	/* ================================================================== log and notices */

	private static function name_of( $uid ) {
		$u = self::account( $uid );
		return $u ? (string) ( $u->display_name ?: $u->user_login ) : __( 'someone', 'zorderz' );
	}

	/** One line of what people did on the page: what, about whom, by whom, when. Never a code, never an address. */
	public static function log( $what, $uid, $by = 0, $note = '' ) {
		self::line( self::OPT_LOG, self::LOG_MAX, $what, $uid, $by, $note );
		if ( class_exists( 'ZDZ_Admin_Dashboard' ) && method_exists( 'ZDZ_Admin_Dashboard', 'log_action' ) ) {
			ZDZ_Admin_Dashboard::log_action( (int) $by, 'signin_' . $what, 'Sign-in routes: ' . $what . ' (account ' . (int) $uid . ')', 'zdz-theme' );
		}
	}

	/** One line of what happened at the login screen (nobody is signed in when it does). Kept apart from log(). */
	private static function event( $what, $uid ) {
		self::line( self::OPT_EVENTS, self::EVENTS_MAX, $what, $uid, 0, '' );
	}

	private static function line( $option, $max, $what, $uid, $by, $note ) {
		$log   = get_option( $option, array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			't'    => self::now(),
			'what' => (string) $what,
			'u'    => (int) $uid,
			'by'   => (int) $by,
			'n'    => (string) $note,
		);
		update_option( $option, array_slice( $log, -$max ), false );
	}

	public static function register_alert_type( $router ) {
		if ( is_object( $router ) && method_exists( $router, 'register_type' ) ) {
			$router->register_type(
				'signin_route',
				array(
					'label'            => __( 'Backup sign-in', 'zorderz' ),
					'description'      => __( 'A one-time sign-in code was given, used or cancelled, a backup address was used, or wrong codes were tried.', 'zorderz' ),
					'default_channels' => array( 'in_app', 'push' ),   // not email by default: these are for when email is down
					'source_plugin'    => 'zorderz',
					'category'         => 'system',
				)
			);
		}
	}

	/** True when the notice went out on a channel that person's role and choices allow; false when none took it. */
	private static function notify( $uid, $title, $message, $url = '' ) {
		if ( $uid <= 0 || ! class_exists( 'ZDZ_Alert_Router' ) ) {
			return false;
		}
		return (bool) ZDZ_Alert_Router::send(
			'signin_route',
			(int) $uid,
			(string) $title,
			(string) $message,
			array(
				'plugin' => 'zorderz',
				'url'    => (string) $url,
			)
		);
	}

	private static function notify_managers( $title, $message, $except = 0 ) {
		foreach ( self::managers() as $m ) {
			if ( $m !== (int) $except ) {
				self::notify( $m, $title, $message, self::page_url() );
			}
		}
	}

	/* ================================================================== the page */

	public static function page_url() {
		return function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=' . self::PAGE ) : '';
	}

	public static function add_page() {
		$me = (int) get_current_user_id();
		if ( ! self::may_open( $me ) && ! self::shows_way_back( $me ) ) {
			return;   // not on the menu, and WordPress refuses the address, for everyone else
		}
		// A second issuer who is not an administrator can't see the Zorderz menu: their page has no parent and is reached by its address.
		$parent = current_user_can( 'manage_options' ) ? 'zdz-core-settings' : '';
		add_submenu_page( $parent, __( 'Sign-in routes', 'zorderz' ), __( 'Sign-in routes', 'zorderz' ), 'read', self::PAGE, array( __CLASS__, 'render' ) );
	}

	/** After a profile save left out a typed backup address: say which, and why. */
	public static function refused_notice() {
		$key = 'zdz_signin_refused_' . (int) get_current_user_id();
		$no  = get_transient( $key );
		if ( ! is_array( $no ) || ! $no ) {
			return;
		}
		delete_transient( $key );
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Backup sign-in addresses:', 'zorderz' ) . '</strong> '
			/* translators: %s: the entries that were not saved, each with its reason */
			. esc_html( sprintf( __( 'not saved: %s.', 'zorderz' ), self::refused_text( $no ) ) ) . '</p></div>';
	}

	public static function remember_refused( array $no ) {
		if ( $no ) {
			set_transient( 'zdz_signin_refused_' . (int) get_current_user_id(), $no, 300 );
		}
	}

	private static function refused_text( array $no ) {
		$bits = array();
		foreach ( $no as $r ) {
			if ( is_array( $r ) && isset( $r[0], $r[1] ) ) {
				$bits[] = '"' . (string) $r[0] . '" ' . (string) $r[1];
			}
		}
		return implode( '; ', $bits );
	}

	/** A time as the site writes dates and times. */
	private static function when( $ts ) {
		if ( $ts <= 0 ) {
			return '';
		}
		if ( ! function_exists( 'wp_date' ) ) {
			return gmdate( 'j M Y, H:i', (int) $ts ) . ' UTC';
		}
		$date = (string) get_option( 'date_format', '' );
		$time = (string) get_option( 'time_format', '' );
		return (string) wp_date( ( '' !== $date ? $date : 'j M Y' ) . ', ' . ( '' !== $time ? $time : 'g:i a' ), (int) $ts );
	}

	private static function people() {
		$users = get_users(
			array(
				'number'  => self::PEOPLE_MAX,
				'orderby' => 'display_name',
				'order'   => 'ASC',
			)
		);
		return is_array( $users ) ? $users : array();
	}

	/** How a log line reads. */
	private static function log_text( array $e ) {
		$u  = self::name_of( (int) $e['u'] );
		$by = self::name_of( (int) $e['by'] );
		switch ( (string) $e['what'] ) {
			case 'issued':
				/* translators: 1: who gave the code, 2: who it is for */
				return sprintf( __( '%1$s gave %2$s a one-time code', 'zorderz' ), $by, $u );
			case 'issued_used':
				/* translators: 1: who signed in, 2: who gave the code */
				return sprintf( __( '%1$s signed in with the code from %2$s', 'zorderz' ), $u, $by );
			case 'issued_cancelled':
				/* translators: 1: who took the code back, 2: who it was for */
				return sprintf( __( '%1$s took back the code given to %2$s', 'zorderz' ), $by, $u );
			case 'backup_used':
				/* translators: %s: a person's name */
				return sprintf( __( '%s signed in with a backup address', 'zorderz' ), $u );
			case 'issued_killed':
				/* translators: 1: who gave the code, 2: who it was for, 3: a number of tries */
				return sprintf( __( 'The code %1$s gave %2$s was cancelled after %3$d wrong tries', 'zorderz' ), $by, $u, self::TRIES_ISSUED );
			case 'code_killed':
				/* translators: 1: a person's name, 2: a number of tries */
				return sprintf( __( '%1$s: %2$d wrong codes were tried; an emailed code was cancelled', 'zorderz' ), $u, self::TRIES_MAIL );
			case 'codes_paused':
				/* translators: 1: a person's name, 2: a number of tries */
				return sprintf( __( '%1$s: %2$d wrong codes were tried in a day; no more codes are emailed to them until the day is over', 'zorderz' ), $u, self::day_misses() );
			case 'send_refused':
				/* translators: 1: a person's name, 2: minutes */
				return sprintf( __( '%1$s: too many codes were asked for with one address; no more are sent to it for %2$d minutes', 'zorderz' ), $u, (int) ( self::WINDOW / 60 ) );
			case 'mail_failed':
				/* translators: %s: a person's name */
				return sprintf( __( 'The site could not send a sign-in email for %s', 'zorderz' ), $u );
			case 'addresses_changed':
				/* translators: 1: who changed them, 2: whose they are, 3: how many there are now */
				return sprintf( __( '%1$s changed the backup addresses of %2$s (now %3$d)', 'zorderz' ), $by, $u, (int) $e['n'] );
			case 'manager_set':
				/* translators: 1: the manager, 2: who named them */
				return sprintf( __( '%1$s manages sign-in routes (set by %2$s)', 'zorderz' ), $u, $by );
			case 'manager_removed':
				/* translators: 1: the person removed, 2: who removed them */
				return sprintf( __( '%1$s no longer manages sign-in routes (%2$s)', 'zorderz' ), $u, $by );
			case 'issuer_set':
				return 'anyone' === $e['n']
					/* translators: 1: the second issuer, 2: who named them */
					? sprintf( __( '%1$s may give codes to anyone (set by %2$s)', 'zorderz' ), $u, $by )
					/* translators: 1: the second issuer, 2: who named them */
					: sprintf( __( '%1$s may give codes to everyone except administrators (set by %2$s)', 'zorderz' ), $u, $by );
			case 'issuer_removed':
				/* translators: %s: a person's name */
				return sprintf( __( '%s removed the second issuer', 'zorderz' ), $by );
		}
		return (string) $e['what'];
	}

	/** Handle the page's one POST. Returns array( kind, text[, code, for] ) to show, or null. */
	private static function handle_post( $me ) {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'POST' !== $method || ! isset( $_POST['zdz_signin_do'] ) ) {
			return null;
		}
		check_admin_referer( self::NONCE );   // a post with no nonce, or another form's, goes no further
		$do  = sanitize_key( wp_unslash( $_POST['zdz_signin_do'] ) );
		$uid = isset( $_POST['zdz_signin_uid'] ) ? (int) $_POST['zdz_signin_uid'] : 0;
		switch ( $do ) {
			case 'claim':
				return self::claim_management( $me )
					? array( 'ok', __( 'You now manage sign-in routes. Only you (and anyone you add under "Managed by") can change backup addresses, here or on a profile. The other administrators have been told.', 'zorderz' ) )
					: array( 'no', __( 'That could not be done.', 'zorderz' ) );
			case 'issue':
				// One press, one code: the page's token is used up by the press, so the same post again (a refreshed
				// page, after the code was used or ran out) gives nothing.
				$want = (string) get_user_meta( $me, self::META_FORM, true );
				$got  = isset( $_POST['zdz_signin_form'] ) ? sanitize_text_field( wp_unslash( $_POST['zdz_signin_form'] ) ) : '';
				delete_user_meta( $me, self::META_FORM );
				if ( '' === $want || ! hash_equals( $want, $got ) ) {
					return array( 'no', __( 'That press was not counted: this page was reloaded or opened again, or the button was pressed twice. If the person\'s row says a code is live, press "Give a new code"; otherwise press the button once more.', 'zorderz' ) );
				}
				$code = self::issue( $me, $uid, ! empty( $_POST['zdz_signin_replace'] ) );
				return is_wp_error( $code ) ? array( 'no', $code->get_error_message() ) : array( 'code', '', $code, $uid );
			case 'cancel':
				return self::cancel_issued( $me, $uid )
					? array( 'ok', __( 'That code no longer works.', 'zorderz' ) )
					: array( 'no', __( 'There was no code to take back.', 'zorderz' ) );
			case 'addresses':
				$typed = isset( $_POST['zdz_signin_addresses'] ) ? sanitize_text_field( wp_unslash( $_POST['zdz_signin_addresses'] ) ) : '';
				$no    = self::may_manage( $me ) ? self::save_addresses( $me, $uid, $typed ) : null;
				if ( null === $no ) {
					return array( 'no', __( 'That could not be saved.', 'zorderz' ) );
				}
				/* translators: %s: the entries that were not saved, each with its reason */
				return $no ? array( 'warn', sprintf( __( 'Saved, except: %s.', 'zorderz' ), self::refused_text( $no ) ) ) : array( 'ok', __( 'Saved.', 'zorderz' ) );
			case 'issuer':
				$scope = isset( $_POST['zdz_signin_scope'] ) ? sanitize_key( wp_unslash( $_POST['zdz_signin_scope'] ) ) : 'non_admin';
				if ( $uid <= 0 ) {
					return array( 'no', __( 'Choose an account first.', 'zorderz' ) );
				}
				if ( ! self::set_issuer( $me, $uid, $scope ) ) {
					return array( 'no', __( 'That account can\'t be the second issuer.', 'zorderz' ) );
				}
				return self::$issuer_told
					? array( 'ok', __( 'Saved. They have been told where to find this page.', 'zorderz' ) )
					/* translators: %s: the address of the Sign-in routes page */
					: array( 'warn', sprintf( __( 'Saved. No notice reaches that account, so tell them where this page is: %s', 'zorderz' ), self::page_url() ) );
			case 'issuer_remove':
				return self::set_issuer( $me, 0 )
					? array( 'ok', __( 'There is no second issuer now.', 'zorderz' ) )
					: array( 'no', __( 'That could not be done.', 'zorderz' ) );
			case 'manager_add':
				return self::add_manager( $me, $uid )
					? array( 'ok', __( 'Saved.', 'zorderz' ) )
					: array( 'no', __( 'That account can\'t manage sign-in routes.', 'zorderz' ) );
			case 'manager_remove':
				return self::remove_manager( $me, $uid )
					? array( 'ok', __( 'Removed.', 'zorderz' ) )
					: array( 'no', __( 'That manager can\'t be removed here (the last one, or the one wp-config.php names).', 'zorderz' ) );
		}
		return null;
	}

	private static function form_open( $do, $uid = 0 ) {
		echo '<form method="post" style="display:inline">';
		wp_nonce_field( self::NONCE );
		if ( 'issue' === $do ) {
			echo '<input type="hidden" name="zdz_signin_form" value="' . esc_attr( self::$form_token ) . '">';
		}
		echo '<input type="hidden" name="zdz_signin_do" value="' . esc_attr( $do ) . '"><input type="hidden" name="zdz_signin_uid" value="' . (int) $uid . '">';
	}

	/** What everyone who may not open the page is shown: nothing but why, and the way back when there is one to give. */
	private static function render_refusal( $me ) {
		echo '<div class="wrap"><h1>' . esc_html__( 'Sign-in routes', 'zorderz' ) . '</h1><p>'
			. esc_html__( 'Only the person who manages sign-in routes, signed in as themselves (not the shared device, View As or Demo Mode), can open this page.', 'zorderz' ) . '</p>';
		if ( self::shows_way_back( $me ) ) {
			if ( self::pinned_manager() > 0 ) {
				/* translators: %d: a user ID */
				echo '<p>' . esc_html( sprintf( __( 'wp-config.php names account %d as the manager (ZDZ_SIGNIN_MANAGER), and that account can\'t manage sign-in routes: it must be an administrator, and not the shared device or an account in Privacy View. Until the line is corrected nobody can change backup addresses or give a code.', 'zorderz' ), self::pinned_manager() ) ) . '</p>';
			} else {
				/* translators: %d: the reader's own user ID */
				echo '<p>' . esc_html( sprintf( __( 'The account that managed sign-in routes can no longer do so, so nobody can change backup addresses or give a code. To name a manager, add this line to wp-config.php (%d is your own user ID):', 'zorderz' ), (int) $me ) )
					. ' <code>define( \'ZDZ_SIGNIN_MANAGER\', ' . (int) $me . ' );</code></p>';
			}
		}
		echo '</div>';
	}

	public static function render() {
		$me = (int) get_current_user_id();
		// An account in Privacy View, the shared device, View As and Demo Mode are refused here before a post is read.
		if ( ( class_exists( 'ZDZ_Privacy_View' ) && ZDZ_Privacy_View::is_on() ) || ! self::may_open( $me ) ) {
			self::render_refusal( $me );
			return;
		}
		$msg              = self::handle_post( $me );
		$manage           = self::may_manage( $me );
		self::$form_token = bin2hex( random_bytes( 12 ) );   // this showing of the page; see handle_post(), 'issue'
		update_user_meta( $me, self::META_FORM, self::$form_token );

		echo '<div class="wrap zdz-signin-routes"><h1>' . esc_html__( 'Sign-in routes', 'zorderz' ) . '</h1>';
		echo '<style>.zdz-signin-routes .zdz-sr-code{font:700 34px/1.2 ui-monospace,Menlo,monospace;letter-spacing:6px;margin:8px 0}.zdz-signin-routes td,.zdz-signin-routes th{vertical-align:top}.zdz-signin-routes .zdz-sr-bad{color:#b32d2e;font-weight:600}.zdz-signin-routes .zdz-sr-warn{color:#996800}.zdz-signin-routes .zdz-sr-good{color:#007017}.zdz-signin-routes .zdz-sr-box{background:#fff;border:1px solid #c3c4c7;padding:12px 16px;margin:16px 0;max-width:900px}.zdz-signin-routes input.zdz-sr-addr{width:100%;max-width:340px}</style>';

		if ( $msg && 'code' === $msg[0] ) {
			$code = (string) $msg[2];
			echo '<div class="notice notice-success"><p>';
			/* translators: %s: a person's name */
			echo wp_kses( sprintf( __( 'Sign-in code for %s:', 'zorderz' ), '<strong>' . esc_html( self::name_of( (int) $msg[3] ) ) . '</strong>' ), array( 'strong' => array() ) );
			echo '</p><p class="zdz-sr-code" id="zdz-sr-code">' . esc_html( substr( $code, 0, 4 ) . ' ' . substr( $code, 4 ) ) . '</p><p>';
			/* translators: %d: minutes */
			echo esc_html( sprintf( __( 'Eight digits. Good for %d minutes, once, for that account only. It is shown here this one time. Give it by voice to someone you recognise, or call them back on a number you know. They open the sign-in screen, press "Email not arriving?", and type their email and this code.', 'zorderz' ), (int) ceil( self::issued_ttl() / 60 ) ) );
			echo '</p></div>';
		} elseif ( $msg ) {
			$cls = 'ok' === $msg[0] ? 'notice-success' : ( 'warn' === $msg[0] ? 'notice-warning' : 'notice-error' );
			echo '<div class="notice ' . esc_attr( $cls ) . '"><p>' . esc_html( (string) $msg[1] ) . '</p></div>';
		}

		if ( self::may_claim( $me ) ) {
			echo '<div class="zdz-sr-box"><p><strong>' . esc_html__( 'Nobody manages sign-in routes yet.', 'zorderz' ) . '</strong> '
				. esc_html__( 'Sign-in routes are the ways in when a person\'s sign-in email can\'t reach them: a backup address the code can go to, and a one-time code read out to them. The person who manages them is the only one who can set backup addresses, name a second issuer, and give one-time codes.', 'zorderz' ) . '</p><p>'
				. ( self::is_network()
					? esc_html__( 'Until someone does, nobody can give a code. This site is part of a WordPress network, so only a network administrator can manage sign-in routes or edit backup addresses on a person\'s profile, and there is no second issuer.', 'zorderz' )
					: esc_html__( 'Until someone does, nobody can give a code, and any administrator can still edit backup addresses on a person\'s profile.', 'zorderz' ) )
				. ' ' . esc_html__( 'This can be pressed once, by one person, and can\'t be undone from this page; the other administrators are told who pressed it. To name a different manager later, add a line to wp-config.php:', 'zorderz' )
				. ' <code>define( \'ZDZ_SIGNIN_MANAGER\', &lt;user ID&gt; );</code></p>';
			self::form_open( 'claim' );
			echo '<button class="button button-primary">' . esc_html__( 'I manage sign-in routes', 'zorderz' ) . '</button></form></div></div>';
			return;
		}

		$people = self::people();
		if ( $manage ) {
			self::render_cover( $people );
		} else {
			echo '<p>' . esc_html__( 'You can give a teammate a one-time sign-in code when their sign-in email can\'t reach them. Whoever manages sign-in routes is told each time.', 'zorderz' ) . '</p>';
		}
		self::render_people( $people, $me, $manage );
		if ( $manage ) {
			self::render_issuer_box( $people );
			self::render_managers_box( $people );
			self::render_log();
		}
		echo '</div>';
	}

	/** The manager's summary: who has no backup address, and whose has never signed them in. */
	private static function render_cover( array $people ) {
		$none = array();
		$unt  = array();
		foreach ( $people as $u ) {
			if ( ! self::gets_routes( (int) $u->ID ) ) {
				continue;
			}
			$c = self::cover( (int) $u->ID );
			if ( 'none' === $c ) {
				$none[] = self::name_of( (int) $u->ID );
			} elseif ( 'untested' === $c ) {
				$unt[] = self::name_of( (int) $u->ID );
			}
		}
		echo '<div class="zdz-sr-box" id="zdz-sr-cover"><p>'
			. wp_kses( __( '<strong>When the work mailbox is down</strong>, a person gets in with a code sent to a backup address, or with a one-time code you give them below.', 'zorderz' ), array( 'strong' => array() ) ) . '</p>';
		if ( $none ) {
			/* translators: 1: how many people, 2: their names */
			echo '<p class="zdz-sr-bad">' . esc_html( sprintf( __( 'No backup address (%1$d): %2$s', 'zorderz' ), count( $none ), implode( ', ', $none ) ) ) . '</p>';
		}
		if ( $unt ) {
			/* translators: 1: how many people, 2: their names */
			echo '<p class="zdz-sr-warn">' . esc_html( sprintf( __( 'Backup address not yet tested (%1$d): %2$s. It counts as tested once it has signed them in.', 'zorderz' ), count( $unt ), implode( ', ', $unt ) ) ) . '</p>';
		}
		if ( ! $none && ! $unt ) {
			echo '<p class="zdz-sr-good">' . esc_html__( 'Everyone has a backup address that has signed them in at least once.', 'zorderz' ) . '</p>';
		}
		echo '<p>' . esc_html__( 'When the site itself can\'t send mail, only a one-time code works. If you are signed out as well, you need a way into WordPress that does not depend on this site\'s email (your host\'s control panel, for example): check now that you have one.', 'zorderz' ) . '</p></div>';
	}

	/** The list of people: for the manager everyone, with addresses; for the second issuer only those it may give a code. */
	private static function render_people( array $people, $me, $manage ) {
		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>' . esc_html__( 'Person', 'zorderz' ) . '</th>';
		if ( $manage ) {
			echo '<th>' . esc_html__( 'Backup sign-in addresses', 'zorderz' ) . '</th><th>' . esc_html__( 'Last signed in', 'zorderz' ) . '</th>';
		}
		echo '<th>' . esc_html__( 'One-time code', 'zorderz' ) . '</th></tr></thead><tbody>';
		foreach ( $people as $u ) {
			$uid = (int) $u->ID;
			$can = self::may_issue_for( $me, $uid );
			if ( ! $manage && ! $can ) {
				continue;
			}
			echo '<tr data-uid="' . (int) $uid . '"><td><strong>' . esc_html( self::name_of( $uid ) ) . '</strong>';
			if ( $manage ) {
				echo '<br><span class="description">' . esc_html( (string) $u->user_email ) . '</span>';
			}
			echo '</td>';
			if ( ! self::gets_routes( $uid ) ) {
				echo '<td colspan="' . ( $manage ? 3 : 1 ) . '"><span class="description">'
					. ( self::is_shared_device( $uid ) ? esc_html__( 'The shared device: no backup routes.', 'zorderz' ) : esc_html__( 'An account in Privacy View: no backup routes.', 'zorderz' ) ) . '</span>';
				$old = $manage ? count( self::addresses( $uid ) ) : 0;
				if ( $old ) {
					// set on its profile before it was the shared device or in Privacy View (a mail alias of the same
					// mailbox, usually): still accepted at sign-in, so it can be seen and removed here
					echo ' <span class="zdz-sr-warn">'
						/* translators: %d: how many addresses */
						. esc_html( sprintf( _n( 'It also signs in with %d other address set earlier.', 'It also signs in with %d other addresses set earlier.', $old, 'zorderz' ), $old ) ) . '</span> ';
					self::form_open( 'addresses', $uid );
					echo '<input type="hidden" name="zdz_signin_addresses" value=""><button class="button-link">' . esc_html__( 'Remove', 'zorderz' ) . '</button></form>';
				}
				echo '</td></tr>';
				continue;
			}
			if ( $manage ) {
				echo '<td>';
				self::form_open( 'addresses', $uid );
				echo '<input type="text" class="zdz-sr-addr" name="zdz_signin_addresses" value="' . esc_attr( implode( ', ', self::addresses( $uid ) ) ) . '" placeholder="' . esc_attr__( 'a non-work address', 'zorderz' ) . '"> <button class="button">' . esc_html__( 'Save', 'zorderz' ) . '</button></form>';
				foreach ( self::addresses( $uid ) as $a ) {
					$flag = self::address_flag( (string) $u->user_email, $a );
					$t    = self::tested_at( $uid, $a );
					echo '<br><span class="description">' . esc_html( $a ) . ': ';
					if ( 'same' === $flag ) {
						echo '<span class="zdz-sr-bad">' . esc_html__( 'same mail domain as the work address: no help when that mailbox is down', 'zorderz' ) . '</span>';
					} else {
						if ( $t ) {
							/* translators: %s: a date and time */
							echo '<span class="zdz-sr-good">' . esc_html( sprintf( __( 'tested %s', 'zorderz' ), self::when( $t ) ) ) . '</span>';
						} else {
							echo '<span class="zdz-sr-warn">' . esc_html__( 'not yet tested', 'zorderz' ) . '</span>';
						}
						if ( 'shared' === $flag ) {
							echo ' · <span class="zdz-sr-warn">' . esc_html__( 'same mail service as the work mailbox: it may be down at the same time', 'zorderz' ) . '</span>';
						}
					}
					echo '</span>';
				}
				echo '</td><td>';
				$last = get_user_meta( $uid, self::META_LAST, true );
				if ( is_array( $last ) && ! empty( $last['t'] ) ) {
					$how = array(
						'mail'   => __( 'emailed code', 'zorderz' ),
						'backup' => __( 'backup address', 'zorderz' ),
						'issued' => __( 'one-time code', 'zorderz' ),
						'bridge' => __( 'Home Screen app code', 'zorderz' ),
					);
					echo esc_html( self::when( (int) $last['t'] ) ) . '<br><span class="description">' . esc_html( $how[ (string) ( $last['route'] ?? '' ) ] ?? '' ) . '</span>';
				} else {
					echo '<span class="description">' . esc_html__( 'no code sign-in recorded yet', 'zorderz' ) . '</span>';
				}
				echo '</td>';
			}
			echo '<td>';
			if ( $can ) {
				$left = self::issued_left( $uid );
				self::form_open( 'issue', $uid );
				if ( $left ) {
					echo '<input type="hidden" name="zdz_signin_replace" value="1">';
				}
				echo '<button class="button' . ( $left ? '' : ' button-primary' ) . '">' . ( $left ? esc_html__( 'Give a new code', 'zorderz' ) : esc_html__( 'Give a sign-in code', 'zorderz' ) ) . '</button></form>';
				if ( $left ) {
					/* translators: %d: minutes */
					echo '<br><span class="description">' . esc_html( sprintf( __( 'A code is live for %d more min.', 'zorderz' ), (int) ceil( $left / 60 ) ) ) . '</span> ';
					if ( $manage || self::issued_by( $uid ) === (int) $me ) {
						self::form_open( 'cancel', $uid );
						echo '<button class="button-link">' . esc_html__( 'Take it back', 'zorderz' ) . '</button></form>';
					}
				}
			} elseif ( $uid === (int) $me ) {
				echo '<span class="description">' . esc_html__( 'you', 'zorderz' ) . '</span>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_issuer_box( array $people ) {
		if ( self::is_network() ) {
			echo '<div class="zdz-sr-box" id="zdz-sr-issuer"><h2>' . esc_html__( 'Second issuer', 'zorderz' ) . '</h2><p>'
				. esc_html__( 'This site is part of a WordPress network, where only network administrators give one-time codes: there is no second issuer. Add another network administrator under "Managed by" instead.', 'zorderz' ) . '</p></div>';
			return;
		}
		$iss  = self::issuer();
		$mgrs = self::managers();
		echo '<div class="zdz-sr-box" id="zdz-sr-issuer"><h2>' . esc_html__( 'Second issuer', 'zorderz' ) . '</h2><p>'
			. esc_html__( 'One more account that may give one-time codes, for when you can\'t be reached. It sees no addresses and can\'t change them or anything else here. It can take back or replace only a code it gave itself. You are told each time it gives a code.', 'zorderz' ) . '</p>';
		if ( $iss ) {
			echo '<p>';
			echo esc_html(
				'anyone' === $iss['scope']
					/* translators: %s: a person's name */
					? sprintf( __( 'Now: %s, for anyone, administrators included.', 'zorderz' ), self::name_of( $iss['uid'] ) )
					/* translators: %s: a person's name */
					: sprintf( __( 'Now: %s, for everyone except administrators.', 'zorderz' ), self::name_of( $iss['uid'] ) )
			);
			echo ' ';
			self::form_open( 'issuer_remove' );
			echo '<button class="button-link">' . esc_html__( 'Remove', 'zorderz' ) . '</button></form></p>';
		}
		echo '<form method="post" style="display:inline">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="zdz_signin_do" value="issuer">';
		echo '<select name="zdz_signin_uid"><option value="0">' . esc_html__( 'Choose an account…', 'zorderz' ) . '</option>';
		foreach ( $people as $u ) {
			$uid = (int) $u->ID;
			if ( self::gets_routes( $uid ) && ! in_array( $uid, $mgrs, true ) ) {
				echo '<option value="' . (int) $uid . '">' . esc_html( self::name_of( $uid ) ) . '</option>';
			}
		}
		echo '</select> ';
		echo '<label><input type="radio" name="zdz_signin_scope" value="non_admin" checked> ' . esc_html__( 'everyone except administrators', 'zorderz' ) . '</label> ';
		echo '<label><input type="radio" name="zdz_signin_scope" value="anyone"> ' . esc_html__( 'anyone', 'zorderz' ) . '</label> ';
		echo '<button class="button">' . esc_html__( 'Save', 'zorderz' ) . '</button></form>';
		echo '<p class="description">' . esc_html__( '"Anyone" lets that account give a code for your account too. That is what gets you back in when you are locked out, and it makes that account as sensitive as yours.', 'zorderz' ) . '</p></div>';
	}

	private static function render_managers_box( array $people ) {
		$mgrs = self::managers();
		echo '<div class="zdz-sr-box" id="zdz-sr-managers"><h2>' . esc_html__( 'Managed by', 'zorderz' ) . '</h2><ul>';
		foreach ( $mgrs as $m ) {
			echo '<li>' . esc_html( self::name_of( $m ) );
			if ( $m === self::pinned_manager() ) {
				echo ' <span class="description">' . esc_html__( '(named in wp-config.php)', 'zorderz' ) . '</span>';
			} elseif ( count( $mgrs ) > 1 ) {
				echo ' ';
				self::form_open( 'manager_remove', $m );
				echo '<button class="button-link">' . esc_html__( 'Remove', 'zorderz' ) . '</button></form>';
			}
			echo '</li>';
		}
		echo '</ul>';
		echo '<form method="post" style="display:inline">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="zdz_signin_do" value="manager_add">';
		echo '<select name="zdz_signin_uid"><option value="0">' . esc_html__( 'Add an administrator…', 'zorderz' ) . '</option>';
		foreach ( $people as $u ) {
			if ( self::eligible_manager( (int) $u->ID ) && ! in_array( (int) $u->ID, $mgrs, true ) ) {
				echo '<option value="' . (int) $u->ID . '">' . esc_html( self::name_of( (int) $u->ID ) ) . '</option>';
			}
		}
		echo '</select> <button class="button">' . esc_html__( 'Add', 'zorderz' ) . '</button></form></div>';
	}

	/** "What happened": the page's own log and the login screen's, newest first. */
	private static function render_log() {
		$log = array_merge( (array) get_option( self::OPT_LOG, array() ), (array) get_option( self::OPT_EVENTS, array() ) );
		$log = array_values( array_filter( $log, 'is_array' ) );
		usort(
			$log,
			function ( $a, $b ) {
				return (int) ( $b['t'] ?? 0 ) <=> (int) ( $a['t'] ?? 0 );
			}
		);
		$log = array_slice( $log, 0, 60 );
		echo '<h2>' . esc_html__( 'What happened', 'zorderz' ) . '</h2>';
		if ( ! $log ) {
			echo '<p class="description">' . esc_html__( 'Nothing yet.', 'zorderz' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		foreach ( $log as $e ) {
			if ( isset( $e['t'], $e['what'], $e['u'], $e['by'] ) ) {
				echo '<tr><td style="width:190px">' . esc_html( self::when( (int) $e['t'] ) ) . '</td><td>' . esc_html( self::log_text( $e + array( 'n' => '' ) ) ) . '</td></tr>';
			}
		}
		echo '</tbody></table>';
	}
}
