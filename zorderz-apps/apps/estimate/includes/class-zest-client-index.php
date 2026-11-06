<?php
/**
 * ZEST_Client_Index - this site's copy of the billing system's client list, so finding an existing customer takes
 * milliseconds instead of one billing round trip per question.
 *
 *   - One table, {prefix}zest_client_index: the fields the lookup reads (names, organisation, email, phones, address,
 *     vis_state, the billing system's updated time), one row per billing client.
 *   - Kept in step: a background sweep reads the whole client list, 100 a page, inside a 20-second budget per pass
 *     (WP-Cron; every minute until the first sweep is complete, then hourly; a fresh sweep each day catches deletions,
 *     which are hidden, never erased); each hourly pass first asks for clients changed since the last pass, and never
 *     trusts that filter: every row's own `updated` is checked, and a page that ignored the filter ends the quick check.
 *   - search(): candidates by indexed SQL (email, phone digits, surname, sound-alike surname, the shared name matcher's
 *     homophones, organisation, first name), then rank(): the shared name matcher's points (ZDZ_Name_Match::name_score:
 *     surname exact/contained/typo, first name exact/contained/nickname/typo, curated soundalikes) plus 20 for the email
 *     searched for and 20 for the phone, kept at 5 points or more, five at most, best first. auto_fill (fill the fields
 *     without a tap) needs 20 or more, so only an email or phone match ever fills; a name alone is a suggestion.
 *   - ready(): the index answers only after one complete sweep. Until then, and whenever it finds nothing, the caller
 *     asks the billing system live (ajax_search), so a lookup is never worse.
 *   - Who sees what: below the admin tier, a client's email, phone and street are shown only when the search itself
 *     carried that email or phone, or the person owns an estimate for that client; anyone else sees the name and town,
 *     and only when the name matches the name searched for (withhold()).
 *
 * Never calls a model, never writes to the billing system. Nothing is logged but counts.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Client_Index {

	const DB_VERSION     = '1';
	const DB_OPTION      = 'zest_client_index_db';
	const STATE_OPTION   = 'zest_client_index_state';
	const CRON_HOOK      = 'zest_client_index_sync';
	const LOCK_OPTION    = 'zest_client_index_lock';
	const RESTART_OPTION = 'zest_client_index_restart';
	const PASS_BUDGET    = 20;
	const PAGE_TIMEOUT   = 12;
	const LOCK_TTL       = 300;
	const RESWEEP_S      = 86400;
	const LAG_S          = 21600;
	const MAX_CANDIDATES = 400;
	const QUICK_PER_MIN  = 90;

	/** @var callable|null tests: ( page, per, since ) => { clients, pages, total } | null */
	public static $pager = null;

	private static $installed = null;

	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron' ) );
		add_action( self::CRON_HOOK . '_more', array( __CLASS__, 'cron' ) );
		add_action( 'wp_ajax_zest_search_clients', array( __CLASS__, 'ajax_search' ) );
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON_HOOK ) && class_exists( 'ZEST_FreshBooks' ) && ZEST_FreshBooks::is_configured() ) {
			wp_schedule_event( time() + 60, 'hourly', self::CRON_HOOK );
		}
	}

	public static function unschedule(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			wp_clear_scheduled_hook( self::CRON_HOOK . '_more' );
		}
	}

	/* ================================================================== the table */

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zest_client_index';
	}

	public static function schema( string $prefix, string $charset ): string {
		return "CREATE TABLE {$prefix}zest_client_index (
		client_id    BIGINT UNSIGNED NOT NULL,
		fname        VARCHAR(120) NOT NULL DEFAULT '',
		lname        VARCHAR(120) NOT NULL DEFAULT '',
		organization VARCHAR(200) NOT NULL DEFAULT '',
		fkey         VARCHAR(80)  NOT NULL DEFAULT '',
		lkey         VARCHAR(80)  NOT NULL DEFAULT '',
		lsound       VARCHAR(20)  NOT NULL DEFAULT '',
		okey         VARCHAR(200) NOT NULL DEFAULT '',
		email        VARCHAR(190) NOT NULL DEFAULT '',
		phones       VARCHAR(120) NOT NULL DEFAULT '',
		mob_phone    VARCHAR(60)  NOT NULL DEFAULT '',
		home_phone   VARCHAR(60)  NOT NULL DEFAULT '',
		bus_phone    VARCHAR(60)  NOT NULL DEFAULT '',
		p_street     VARCHAR(200) NOT NULL DEFAULT '',
		p_street2    VARCHAR(200) NOT NULL DEFAULT '',
		p_city       VARCHAR(120) NOT NULL DEFAULT '',
		p_province   VARCHAR(60)  NOT NULL DEFAULT '',
		p_code       VARCHAR(20)  NOT NULL DEFAULT '',
		vis_state    TINYINT      NOT NULL DEFAULT 0,
		fb_updated   VARCHAR(25)  NOT NULL DEFAULT '',
		sweep        INT UNSIGNED NOT NULL DEFAULT 0,
		synced_at    DATETIME     NOT NULL,
		PRIMARY KEY  (client_id),
		KEY lkey (lkey),
		KEY lsound (lsound),
		KEY fkey (fkey),
		KEY email (email),
		KEY sweep (sweep)
	) {$charset};";
	}

	public static function maybe_install(): bool {
		if ( null !== self::$installed ) {
			return self::$installed;
		}
		if ( self::DB_VERSION === (string) get_option( self::DB_OPTION, '' ) ) {
			return self::$installed = true;
		}
		global $wpdb;
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( self::schema( $wpdb->prefix, $wpdb->get_charset_collate() ) );
		$ok = ( self::table() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::table() ) ) );
		if ( $ok ) {
			update_option( self::DB_OPTION, self::DB_VERSION, false );
		}
		return self::$installed = $ok;
	}

	/* ================================================================== keys (pure) */

	/** Letters only, lower case, accents folded: "O'Brien-Smith" -> "obriensmith". */
	public static function key( $s ): string {
		$s = (string) $s;
		if ( function_exists( 'remove_accents' ) ) {
			$s = remove_accents( $s );
		} elseif ( function_exists( 'iconv' ) ) {
			$t = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $s ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a name iconv can't fold stays as it is
			$s = false === $t ? $s : $t;
		}
		return substr( (string) preg_replace( '/[^a-z]/', '', strtolower( $s ) ), 0, 80 );
	}

	/** The sound of a surname (metaphone of its letters), '' for fewer than two letters. */
	public static function sound( $s ): string {
		$k = self::key( $s );
		return strlen( $k ) < 2 ? '' : substr( (string) metaphone( $k ), 0, 20 );
	}

	/** The last ten digits of a phone; '' for fewer than seven digits. */
	public static function phone_key( $s ): string {
		$d = (string) preg_replace( '/\D/', '', (string) $s );
		if ( strlen( $d ) > 10 ) {
			$d = substr( $d, -10 );
		}
		return strlen( $d ) >= 7 ? $d : '';
	}

	/** Every phone a client carries: mobile, home, business, and a second street line that is only a phone. */
	public static function phones_of( array $c ): array {
		$out = array();
		foreach ( array( 'mob_phone', 'home_phone', 'bus_phone' ) as $f ) {
			$k = self::phone_key( $c[ $f ] ?? '' );
			if ( '' !== $k ) {
				$out[ $k ] = true;
			}
		}
		$st2 = (string) ( $c['p_street2'] ?? '' );
		if ( '' !== $st2 && class_exists( 'ZEST_Contract_Address' ) && ZEST_Contract_Address::is_phone( $st2 ) ) {
			$k = self::phone_key( $st2 );
			if ( '' !== $k ) {
				$out[ $k ] = true;
			}
		}
		return array_map( 'strval', array_keys( $out ) );
	}

	/** A billing client -> the index row (null when it has no id). */
	public static function row_from_client( array $c, int $sweep ): ?array {
		$id = (int) ( $c['id'] ?? ( $c['userid'] ?? 0 ) );
		if ( $id <= 0 ) {
			return null;
		}
		$cut = static function ( $v, $n ) {
			$v = trim( (string) $v );
			return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, $n ) : substr( $v, 0, $n );
		};
		return array(
			'client_id'    => $id,
			'fname'        => $cut( $c['fname'] ?? '', 120 ),
			'lname'        => $cut( $c['lname'] ?? '', 120 ),
			'organization' => $cut( $c['organization'] ?? '', 200 ),
			'fkey'         => self::key( $c['fname'] ?? '' ),
			'lkey'         => self::key( $c['lname'] ?? '' ),
			'lsound'       => self::sound( $c['lname'] ?? '' ),
			'okey'         => substr( self::key( $c['organization'] ?? '' ), 0, 200 ),
			'email'        => $cut( strtolower( (string) ( $c['email'] ?? '' ) ), 190 ),
			'phones'       => substr( implode( '|', self::phones_of( $c ) ), 0, 120 ),
			'mob_phone'    => $cut( $c['mob_phone'] ?? '', 60 ),
			'home_phone'   => $cut( $c['home_phone'] ?? '', 60 ),
			'bus_phone'    => $cut( $c['bus_phone'] ?? '', 60 ),
			'p_street'     => $cut( $c['p_street'] ?? '', 200 ),
			'p_street2'    => $cut( $c['p_street2'] ?? '', 200 ),
			'p_city'       => $cut( $c['p_city'] ?? '', 120 ),
			'p_province'   => $cut( $c['p_province'] ?? '', 60 ),
			'p_code'       => $cut( $c['p_code'] ?? '', 20 ),
			'vis_state'    => (int) ( $c['vis_state'] ?? 0 ),
			'fb_updated'   => $cut( $c['updated'] ?? '', 25 ),
			'sweep'        => $sweep,
			'synced_at'    => gmdate( 'Y-m-d H:i:s' ),
		);
	}

	/** An index row -> the billing client shape rank() reads. */
	public static function client_from_row( array $r ): array {
		$out = array( 'id' => (int) $r['client_id'], 'vis_state' => (int) $r['vis_state'] );
		foreach ( array( 'fname', 'lname', 'organization', 'email', 'mob_phone', 'home_phone', 'bus_phone', 'p_street', 'p_street2', 'p_city', 'p_province', 'p_code' ) as $k ) {
			$out[ $k ] = (string) ( $r[ $k ] ?? '' );
		}
		return $out;
	}

	/* ================================================================== ranking (pure) */

	/** Name points for one client: the shared matcher's when it is there, else exact and contained names only. */
	public static function name_points( string $cf, string $cl, string $sf, string $sl ): int {
		$cf = strtolower( $cf );
		$cl = strtolower( $cl );
		$sf = str_replace( array( ' ', '&' ), '', strtolower( trim( $sf ) ) );
		$sl = strtolower( trim( $sl ) );
		if ( class_exists( 'ZDZ_Name_Match' ) && method_exists( 'ZDZ_Name_Match', 'name_score' ) ) {
			return (int) ZDZ_Name_Match::name_score( $cf, $cl, $sf, $sl );
		}
		$p = 0;
		if ( '' !== $sl && '' !== $cl && ( $cl === $sl || false !== strpos( $cl, $sl ) || false !== strpos( $sl, $cl ) ) ) {
			$p += 5;
		}
		$cfn = str_replace( array( ' ', '&' ), '', $cf );
		if ( '' !== $sf && '' !== $cfn && ( $cfn === $sf || false !== strpos( $cfn, $sf ) || false !== strpos( $sf, $cfn ) ) ) {
			$p += 10;
		}
		return $p;
	}

	/**
	 * Rank clients against a search { fname, lname, email, phone }: [ { id, fname, lname, email, phone, street, city,
	 * state, zip, match_score, auto_fill } ], best first, 5 at most, each with 5 points or more.
	 */
	public static function rank( array $clients, array $params ): array {
		$sf = (string) ( $params['fname'] ?? '' );
		$sl = (string) ( $params['lname'] ?? '' );
		$se = strtolower( trim( (string) ( $params['email'] ?? '' ) ) );
		$sp = (string) preg_replace( '/\D/', '', (string) ( $params['phone'] ?? '' ) );
		$out = array();
		foreach ( $clients as $c ) {
			if ( ! is_array( $c ) || 0 !== (int) ( $c['vis_state'] ?? 0 ) ) {
				continue;
			}
			$phones = self::phones_of( $c );
			$raw    = '';
			foreach ( array( 'mob_phone', 'home_phone', 'bus_phone', 'p_street2' ) as $k ) {
				if ( '' === $raw && '' !== self::phone_key( $c[ $k ] ?? '' ) && ( 'p_street2' !== $k || in_array( self::phone_key( $c[ $k ] ), $phones, true ) ) ) {
					$raw = (string) $c[ $k ];
				}
			}
			$score = self::name_points( (string) ( $c['fname'] ?? '' ), (string) ( $c['lname'] ?? '' ), $sf, $sl );
			$ce    = strtolower( (string) ( $c['email'] ?? '' ) );
			if ( '' !== $se && '' !== $ce && $se === $ce ) {
				$score += 20;
			}
			if ( strlen( $sp ) >= 7 ) {
				foreach ( $phones as $cp ) {
					if ( $cp === $sp || false !== strpos( $cp, $sp ) || false !== strpos( $sp, $cp ) ) {
						$score += 20;
						break;
					}
				}
			}
			$score += ( '' !== $ce ? 1 : 0 ) + ( '' !== $raw ? 1 : 0 );
			$out[] = array(
				'id'          => (string) ( $c['id'] ?? '' ),
				'fname'       => (string) ( $c['fname'] ?? '' ),
				'lname'       => (string) ( $c['lname'] ?? '' ),
				'email'       => (string) ( $c['email'] ?? '' ),
				'phone'       => $raw,
				'street'      => (string) ( $c['p_street'] ?? '' ),
				'city'        => (string) ( $c['p_city'] ?? '' ),
				'state'       => (string) ( $c['p_province'] ?? '' ),
				'zip'         => (string) ( $c['p_code'] ?? '' ),
				'match_score' => $score,
			);
		}
		usort( $out, static function ( $a, $b ) { return $b['match_score'] <=> $a['match_score']; } );
		$res = array();
		foreach ( $out as $r ) {
			if ( $r['match_score'] < 5 ) {
				continue;
			}
			$r['auto_fill'] = $r['match_score'] >= 20;
			$res[]          = $r;
			if ( count( $res ) >= 5 ) {
				break;
			}
		}
		return $res;
	}

	/**
	 * Below the admin tier: a result keeps its email, phone and street only when the search carried that email or phone,
	 * or $owns says the person owns an estimate for the client; any other result is shown (name and town only) only when
	 * its name matches the name searched for.
	 */
	public static function withhold( array $results, array $params, bool $admin, ?callable $owns = null ): array {
		if ( $admin ) {
			return $results;
		}
		$se  = strtolower( trim( (string) ( $params['email'] ?? '' ) ) );
		$sp  = self::phone_key( (string) ( $params['phone'] ?? '' ) );
		$out = array();
		foreach ( $results as $r ) {
			$re = strtolower( trim( (string) ( $r['email'] ?? '' ) ) );
			$rp = self::phone_key( (string) ( $r['phone'] ?? '' ) );
			if ( ( '' !== $se && '' !== $re && $re === $se ) || ( '' !== $sp && '' !== $rp && $rp === $sp ) || ( $owns && $owns( (string) ( $r['id'] ?? '' ) ) ) ) {
				$out[] = $r;
				continue;
			}
			if ( self::name_points( (string) ( $r['fname'] ?? '' ), (string) ( $r['lname'] ?? '' ), (string) ( $params['fname'] ?? '' ), (string) ( $params['lname'] ?? '' ) ) < 5 ) {
				continue;
			}
			$out[] = array(
				'id' => $r['id'] ?? '', 'fname' => $r['fname'] ?? '', 'lname' => $r['lname'] ?? '', 'email' => '', 'phone' => '', 'street' => '',
				'city' => $r['city'] ?? '', 'state' => $r['state'] ?? '', 'zip' => $r['zip'] ?? '', 'match_score' => (int) ( $r['match_score'] ?? 0 ),
				'auto_fill' => false, 'withheld' => true, 'has_email' => '' !== $re, 'has_phone' => '' !== $rp,
			);
		}
		return $out;
	}

	/**
	 * After a parse: is this suggestion only a look-alike name, when the note's own email or phone says the customer is
	 * someone else? A person with the same surname (a household) and anyone whose email or phone matches are kept, and
	 * so is a result whose contact details were withheld (nothing to tell them apart by).
	 */
	public static function weak_name_only( array $r, string $fname, string $lname, string $email, string $phone ): bool {
		$e = strtolower( trim( $email ) );
		$p = self::phone_key( $phone );
		if ( '' === $e && strlen( $p ) < 7 ) {
			return false;
		}
		if ( ! empty( $r['withheld'] ) ) {
			return false;
		}
		$re = strtolower( trim( (string) ( $r['email'] ?? '' ) ) );
		$rp = self::phone_key( (string) ( $r['phone'] ?? '' ) );
		if ( ( '' !== $e && '' !== $re && $re === $e ) || ( strlen( $p ) >= 7 && '' !== $rp && $rp === $p ) ) {
			return false;
		}
		$norm = static function ( $s ) {
			return strtolower( (string) preg_replace( '/[^a-z]/i', '', (string) $s ) );
		};
		$sl = $norm( $lname );
		if ( '' !== $sl && $sl === $norm( $r['lname'] ?? '' ) ) {
			return false;
		}
		$sf = $norm( $fname );
		$cf = $norm( $r['fname'] ?? '' );
		if ( '' !== $sf && '' !== $cf && class_exists( 'ZDZ_Name_Match' ) && method_exists( 'ZDZ_Name_Match', 'first_names_equivalent' )
			&& ZDZ_Name_Match::first_names_equivalent( $cf, $sf ) ) {
			return false;
		}
		return true;
	}

	/* ================================================================== writes */

	public static function upsert_many( array $clients, ?int $sweep = null ): int {
		if ( ! $clients || ! self::maybe_install() ) {
			return 0;
		}
		global $wpdb;
		$sweep = null === $sweep ? (int) ( self::state()['sweep'] ?? 0 ) : $sweep;
		$n     = 0;
		foreach ( $clients as $c ) {
			$row = is_array( $c ) ? self::row_from_client( $c, $sweep ) : null;
			if ( $row && false !== $wpdb->replace( self::table(), $row ) ) {
				$n++;
			}
		}
		return $n;
	}

	public static function state(): array {
		$s = get_option( self::STATE_OPTION, array() );
		return ( is_array( $s ) ? $s : array() ) + array( 'sweep' => 1, 'page' => 1, 'pages' => 0, 'total' => 0, 'complete' => false, 'last_full' => 0, 'sweep_start' => 0, 'inc_mark' => '', 'inc_ok' => true, 'passes' => 0, 'last_pass' => 0, 'last_error' => '' );
	}

	private static function save_state( array $s ): void {
		update_option( self::STATE_OPTION, $s, false );
	}

	public static function ready(): bool {
		return ! empty( self::state()['complete'] ) && self::maybe_install();
	}

	/* ================================================================== search */

	/** The ranked results from the index, or null when it cannot answer (not ready, no table, a database error). */
	public static function search( array $params ): ?array {
		if ( ! self::ready() ) {
			return null;
		}
		$rows = self::candidates( $params );
		if ( null === $rows ) {
			return null;
		}
		$clients = array();
		foreach ( $rows as $r ) {
			$clients[ (int) $r['client_id'] ] = self::client_from_row( $r );
		}
		return self::rank( $clients, $params );
	}

	/** Every row a lookup could rank, at most MAX_CANDIDATES; a row with the email or phone searched for sorts first. */
	public static function candidates( array $params ): ?array {
		global $wpdb;
		$t      = self::table();
		$where  = array();
		$args   = array();
		$email  = strtolower( trim( (string) ( $params['email'] ?? '' ) ) );
		$digits = self::phone_key( $params['phone'] ?? '' );
		$fk     = self::key( $params['fname'] ?? '' );
		$lk     = self::key( $params['lname'] ?? '' );
		if ( '' !== $email ) {
			$where[] = 'email = %s';
			$args[]  = $email;
			if ( strlen( $email ) >= 5 ) {
				$where[] = 'email LIKE %s';
				$args[]  = '%' . $wpdb->esc_like( $email ) . '%';
			}
		}
		if ( '' !== $digits ) {
			$where[] = 'phones LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( substr( $digits, -7 ) ) . '%';
		}
		if ( strlen( $lk ) >= 2 ) {
			$where[] = 'lkey LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( $lk ) . '%';
			$snd     = self::sound( $lk );
			if ( '' !== $snd ) {
				$where[] = 'lsound = %s';
				$args[]  = $snd;
			}
			if ( strlen( $lk ) >= 4 ) {
				$where[] = 'lkey LIKE %s';
				$args[]  = $wpdb->esc_like( substr( $lk, 0, 3 ) ) . '%';
			}
			if ( class_exists( 'ZDZ_Name_Match' ) && method_exists( 'ZDZ_Name_Match', 'soundalike_variants' ) ) {
				foreach ( array_slice( (array) ZDZ_Name_Match::soundalike_variants( (string) ( $params['lname'] ?? '' ) ), 0, 12 ) as $v ) {
					$vk = self::key( $v );
					if ( '' !== $vk ) {
						$where[] = 'lkey = %s';
						$args[]  = $vk;
					}
				}
			}
			$where[] = 'okey LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( $lk ) . '%';
		} elseif ( strlen( $fk ) >= 2 ) {
			$where[] = 'fkey LIKE %s';
			$args[]  = $wpdb->esc_like( $fk ) . '%';
		}
		if ( ! $where ) {
			return array();
		}
		$sql    = "SELECT * FROM {$t} WHERE vis_state = 0 AND ( " . implode( ' OR ', $where ) . " ) ORDER BY ( email <> '' AND email = %s ) DESC, ( phones <> '' AND %s <> '' AND phones LIKE %s ) DESC, ( lkey = %s ) DESC, ( fkey = %s ) DESC, client_id DESC LIMIT %d";
		$args[] = $email;
		$args[] = $digits;
		$args[] = '%' . $wpdb->esc_like( '' !== $digits ? substr( $digits, -7 ) : '' ) . '%';
		$args[] = $lk;
		$args[] = $fk;
		$args[] = self::MAX_CANDIDATES;
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- built from fixed fragments, every value a placeholder
		if ( null === $rows && '' !== (string) $wpdb->last_error ) {
			return null;
		}
		return is_array( $rows ) ? $rows : array();
	}

	/* ================================================================== the billing system's list */

	/** One page of clients: { clients, pages, total }, or null when the billing system did not answer. */
	public static function page( int $page, int $per, string $since = '' ): ?array {
		if ( is_callable( self::$pager ) ) {
			return call_user_func( self::$pager, $page, $per, $since );
		}
		$client = class_exists( 'ZEST_FreshBooks' ) ? ZEST_FreshBooks::client() : null;
		$acct   = class_exists( 'ZDZ_Core_Settings' ) && method_exists( 'ZDZ_Core_Settings', 'get_fb_account_id' ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) ZDZ_Core_Settings::get_fb_account_id() ) : '';
		if ( ! $client || '' === $acct || ! method_exists( $client, 'api_request' ) ) {
			return null;
		}
		$q = 'page=' . max( 1, $page ) . '&per_page=' . max( 1, min( 100, $per ) ) . ( '' !== $since ? '&search[updated_min]=' . rawurlencode( $since ) : '' );
		try {
			$resp = $client->api_request( 'GET', '/accounting/account/' . $acct . '/users/clients?' . $q );
		} catch ( \Throwable $e ) {
			return null;
		}
		$r = is_array( $resp ) ? ( $resp['response']['result'] ?? null ) : null;
		if ( ! is_array( $r ) || ! is_array( $r['clients'] ?? null ) ) {
			return null;
		}
		return array( 'clients' => array_values( array_filter( $r['clients'], 'is_array' ) ), 'pages' => (int) ( $r['pages'] ?? 1 ), 'total' => (int) ( $r['total'] ?? 0 ) );
	}

	public static function cron(): void {
		if ( ! is_callable( self::$pager ) && ! ( class_exists( 'ZEST_FreshBooks' ) && ZEST_FreshBooks::is_configured() ) ) {
			return;
		}
		$st = self::run_pass( self::PASS_BUDGET );
		if ( '' === (string) $st['last_error'] && ( (int) $st['pages'] <= 0 || (int) $st['page'] <= (int) $st['pages'] ) && ! wp_next_scheduled( self::CRON_HOOK . '_more' ) ) {
			wp_schedule_single_event( time() + 60, self::CRON_HOOK . '_more' );
		}
	}

	/** One pass inside $budget seconds: the quick check for changed clients (after a whole sweep), then the next pages. */
	public static function run_pass( int $budget = self::PASS_BUDGET, ?callable $clock = null ): array {
		$now = static function () use ( $clock ) {
			return $clock ? (float) call_user_func( $clock ) : microtime( true );
		};
		$t0 = $now();
		if ( ! self::maybe_install() ) {
			$s               = self::state();
			$s['last_error'] = 'the index table could not be made';
			self::save_state( $s );
			return $s;
		}
		if ( ! self::lock() ) {
			return self::state();
		}
		try {
			$s = self::state();
			$s['passes']++;
			$s['last_error'] = '';
			$asked = (bool) get_option( self::RESTART_OPTION, false );
			if ( $asked || ( ! empty( $s['complete'] ) && (int) $s['page'] > max( 1, (int) $s['pages'] ) && ( time() - (int) $s['last_full'] ) >= self::RESWEEP_S ) ) {
				$s['sweep']       = (int) $s['sweep'] + 1;
				$s['page']        = 1;
				$s['sweep_start'] = time();
				delete_option( self::RESTART_OPTION );
				self::save_state( $s );
			}
			if ( ! empty( $s['complete'] ) && ! empty( $s['inc_ok'] ) && '' !== (string) $s['inc_mark'] ) {
				$since = self::time_minus( (string) $s['inc_mark'], self::LAG_S );
				for ( $p = 1; $p <= 5 && ( $now() - $t0 ) < $budget - self::PAGE_TIMEOUT; $p++ ) {
					$res = self::page( $p, 100, $since );
					if ( null === $res ) {
						$s['last_error'] = 'the billing system did not answer the quick check';
						break;
					}
					$fresh = array();
					$old   = 0;
					foreach ( $res['clients'] as $c ) {
						if ( '' === (string) ( $c['updated'] ?? '' ) || strcmp( (string) $c['updated'], $since ) >= 0 ) {
							$fresh[] = $c;
						} else {
							$old++;
						}
					}
					self::upsert_many( $fresh, (int) $s['sweep'] );
					$s['inc_mark'] = self::newest( $fresh, (string) $s['inc_mark'] );
					if ( $old > 0 && $old * 2 >= count( $res['clients'] ) ) {
						$s['inc_ok']     = false;
						$s['last_error'] = 'the billing system ignored the "changed since" filter, so changes arrive with the daily sweep';
						break;
					}
					if ( count( $res['clients'] ) < 100 || $p >= (int) $res['pages'] ) {
						break;
					}
				}
			}
			while ( ( $now() - $t0 ) < $budget - self::PAGE_TIMEOUT ) {
				if ( (int) $s['pages'] > 0 && (int) $s['page'] > (int) $s['pages'] ) {
					break;
				}
				$res = self::page( (int) $s['page'], 100 );
				if ( null === $res ) {
					$s['last_error'] = 'the billing system did not answer page ' . (int) $s['page'] . ' (the next pass tries again)';
					break;
				}
				$s['pages'] = max( 1, (int) $res['pages'] );
				$s['total'] = (int) $res['total'];
				self::upsert_many( $res['clients'], (int) $s['sweep'] );
				$s['inc_mark'] = self::newest( $res['clients'], (string) $s['inc_mark'] );
				$s['page']     = (int) $s['page'] + 1;
				if ( (int) $s['page'] > (int) $s['pages'] ) {
					self::finish_sweep( $s );
					break;
				}
				if ( ! $res['clients'] ) {
					$s['page']       = (int) $s['page'] - 1;
					$s['last_error'] = 'the billing system sent an empty page ' . (int) $s['page'] . ' (the next pass tries again)';
					break;
				}
			}
			$s['last_pass'] = time();
			self::save_state( $s );
			return $s;
		} finally {
			self::unlock();
		}
	}

	/** A whole sweep was read: rows it did not see are gone from the billing system's list (hidden, not erased). */
	private static function finish_sweep( array &$s ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET vis_state = 9 WHERE vis_state = 0 AND sweep < %d', (int) $s['sweep'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- the table name is the app's own
		$s['complete']  = true;
		$s['last_full'] = time();
		$s['page']      = (int) $s['pages'] + 1;
		$s['inc_ok']    = true;
	}

	/** The newest `updated` among $clients, or $mark ('Y-m-d H:i:s' strings compare). */
	public static function newest( array $clients, string $mark ): string {
		foreach ( $clients as $c ) {
			$u = is_array( $c ) ? (string) ( $c['updated'] ?? '' ) : '';
			if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $u ) && strcmp( $u, $mark ) > 0 ) {
				$mark = $u;
			}
		}
		return $mark;
	}

	public static function time_minus( string $t, int $seconds ): string {
		$ts = strtotime( $t . ' UTC' );
		return false === $ts ? '' : gmdate( 'Y-m-d H:i:s', $ts - $seconds );
	}

	private static function lock(): bool {
		$now = time();
		if ( add_option( self::LOCK_OPTION, $now, '', 'no' ) ) {
			return true;
		}
		$held = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $held > 0 && ( $now - $held ) < self::LOCK_TTL ) {
			return false;
		}
		update_option( self::LOCK_OPTION, $now, false );   // a lock left by a process that died
		return true;
	}

	private static function unlock(): void {
		delete_option( self::LOCK_OPTION );
	}

	/** "Read every client again": the next pass starts a new sweep (the rows are kept until it replaces them). */
	public static function restart(): array {
		update_option( self::RESTART_OPTION, 1, false );
		return self::state();
	}

	/* ================================================================== the lookup */

	/** The live fallback: the billing system's own client search for the email, then the surname. */
	private static function live( array $params ): array {
		$client = class_exists( 'ZEST_FreshBooks' ) ? ZEST_FreshBooks::client() : null;
		$acct   = class_exists( 'ZDZ_Core_Settings' ) && method_exists( 'ZDZ_Core_Settings', 'get_fb_account_id' ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) ZDZ_Core_Settings::get_fb_account_id() ) : '';
		if ( ! $client || '' === $acct ) {
			return array();
		}
		$found = array();
		$asks  = array();
		if ( '' !== trim( (string) ( $params['email'] ?? '' ) ) ) {
			$asks[] = 'search[email_like]=' . rawurlencode( strtolower( trim( (string) $params['email'] ) ) );
		}
		if ( '' !== trim( (string) ( $params['lname'] ?? '' ) ) ) {
			$asks[] = 'search[lname]=' . rawurlencode( trim( (string) $params['lname'] ) );
		}
		foreach ( $asks as $q ) {
			try {
				$resp = $client->api_request( 'GET', '/accounting/account/' . $acct . '/users/clients?per_page=50&' . $q );
			} catch ( \Throwable $e ) {
				continue;
			}
			foreach ( (array) ( $resp['response']['result']['clients'] ?? array() ) as $c ) {
				if ( is_array( $c ) && ! empty( $c['id'] ) ) {
					$found[ (int) $c['id'] ] = $c;
				}
			}
		}
		self::upsert_many( array_values( $found ) );   // what the billing system hands over is kept for the next lookup
		return self::rank( $found, $params );
	}

	/** Does $uid own an estimate of this app's for this billing client? */
	private static function owns_for_client( int $uid, string $cid ): bool {
		if ( '' === $cid || ! class_exists( 'ZEST_DB' ) ) {
			return false;
		}
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . ZEST_DB::estimates_table() . ' WHERE billing_cust_id = %s ORDER BY id DESC LIMIT 20', $cid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name (ZEST_DB::estimates_table())
		foreach ( $rows as $r ) {
			if ( ZEST_Access::may_open( $r, $uid ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * zest_search_clients {fname, lname, email, phone, quick, auto}: the index first; the billing system when it can't
	 * answer. After a parse (auto), a look-alike name is dropped when the note's own email or phone says it is someone else.
	 */
	public static function ajax_search(): void {
		$uid = ZEST_Access::guard();
		ZEST_Access::no_kiosk( $uid );
		$params = array();
		foreach ( array( 'fname', 'lname', 'email', 'phone' ) as $k ) {
			$params[ $k ] = isset( $_POST[ $k ] ) ? substr( sanitize_text_field( wp_unslash( $_POST[ $k ] ) ), 0, 120 ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in ZEST_Access::guard() above
		}
		$quick = ! empty( $_POST['quick'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in ZEST_Access::guard() above
		$auto  = ! empty( $_POST['auto'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in ZEST_Access::guard() above
		if ( $quick ) {
			$k = 'zest_ci_q_' . $uid . '_' . (int) floor( time() / 60 );
			$n = (int) get_transient( $k );
			if ( $n >= self::QUICK_PER_MIN ) {
				wp_send_json_success( array( 'results' => array(), 'source' => 'index', 'limited' => true ) );
			}
			set_transient( $k, $n + 1, 90 );
		}
		$res    = self::search( $params );
		$source = 'index';
		if ( ( null === $res || ! $res ) && ! $quick ) {
			$res    = self::live( $params );
			$source = 'live';
		}
		if ( $auto && ! $quick ) {
			$res = array_values( array_filter( (array) $res, static function ( $r ) use ( $params ) {
				return ! self::weak_name_only( is_array( $r ) ? $r : array(), $params['fname'], $params['lname'], $params['email'], $params['phone'] );
			} ) );
		}
		$res = self::withhold( (array) $res, $params, ZEST_Access::is_admin_tier( $uid ), static function ( string $cid ) use ( $uid ) {
			return self::owns_for_client( $uid, $cid );
		} );
		wp_send_json_success( array( 'results' => $res, 'source' => $source, 'ready' => self::ready() ) );
	}
}
