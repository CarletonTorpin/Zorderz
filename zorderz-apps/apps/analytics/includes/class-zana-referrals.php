<?php
/**
 * ZANA_Referrals — "Needs a referral": finding another company for a customer the business does not serve.
 *
 * A stub or won job whose ZIP is outside every service area of its work is a referral case in the Territories app
 * (verb `territories.uncovered`). This panel lets a person find another company for that customer, check it, and write
 * to the customer:
 *
 *   1. "Find a provider" is ONE web search through the Ai router's `web` role. The question carries only the ZIP, the
 *      place name and the kind of work (a short text-only question: the router's web check must pass, or nothing is
 *      sent). The answer is read by rule: at most five candidates, each a name with a public website or a phone, and
 *      where it was found. An answer that cannot be read is said to be unreadable (never "none found") and gives the
 *      case its search back.
 *   2. A person marks one candidate "Vetted" (with an optional note). Nothing is sent.
 *   3. "Press to email this customer" appears only after vetting. It opens a filled-in email in the Email app (filter
 *      `zdz_compose_handoff`, when an app answers it) or as a mailto. The address comes from the estimate app's verb
 *      `estimate.contact_for`, asked as the person who pressed. Nothing is ever sent from here.
 *   4. Found, vetted and emailed are kept with who and when -- never the email's text, never a customer's details. The
 *      case closes when the email is opened from the button, or when an admin closes it: `zdz_referral_closed`
 *      (case id, who, 'emailed' | 'closed'), which Territories answers.
 *
 * WHO: the cases come from `territories.uncovered`, asked as the signed-in person (the admin tier sees every case, a
 * salesperson the cases of their own estimates). Never the shared device, never in Demo Mode, never a Privacy View
 * account. Every press re-reads the case through the verb, so a case that is not yours answers the same as a case that
 * does not exist. A real customer is written to only from a rule that is live (a rule in shadow is the rules' guess, not
 * a decision); a test case may still be opened, with an empty To line.
 *
 * WHAT IS STORED (wp_zana_referrals): one row per case -- the case id, ZIP, place, the work words, the candidates (public
 * business listings), the vetted one and its note, and found / vetted / emailed / closed with who and when.
 *
 * A place name for a ZIP (filter `zdz_zip_place`, which Territories reads) is looked up by the ZIP alone from the public
 * postal-code service the business configures (option `zana_zip_place_url`, a URL with {zip}; Core: none, so no lookup
 * leaves the site), and kept for 30 days.
 *
 * Its steps are also verbs on the registry (referrals.list, referrals.find, referrals.vet, referrals.email,
 * referrals.close), so the chat can propose one; the writes then wait for a Confirm press like every other write.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Referrals {

	const DB_VERSION      = '1.0.0';
	const DB_OPTION       = 'zana_referrals_db';
	const VERB_CASES      = 'territories.uncovered';
	const VERB_CONTACT    = 'estimate.contact_for';
	const MAX_CANDIDATES  = 5;
	const MAX_FINDS_CASE  = 3;     // finds per case per day
	const MAX_FINDS_USER  = 20;    // finds per person per day
	const PLACE_TTL       = 2592000;   // 30 days
	const PLACE_MISS_TTL  = 86400;
	const PLACE_URL_OPT   = 'zana_zip_place_url';
	const FIND_MAX_TOKENS = 3000;
	const FIND_BUDGET_S   = 60;    // the whole search fits inside one request

	/** May the place lookup go to the network in this request? Only the panel's own steps (never a page render). */
	private static $may_fetch = false;
	private static $fetches   = 0;

	/** Tests hand in a stand-in for the router call: callable( $system, $question ) => [ text, stop ] or WP_Error. */
	public static $search = null;
	/** Tests hand in the registry: callable( $verb, $args ) => array. */
	public static $invoke = null;

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 5 );
		add_filter( 'zdz_zip_place', array( __CLASS__, 'zip_place' ), 20, 2 );
		add_filter( 'zdz_register_capabilities', array( __CLASS__, 'register' ), 20 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zana_referrals';
	}

	public static function maybe_install(): void {
		if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( 'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			case_id bigint(20) unsigned NOT NULL,
			zip varchar(10) NOT NULL DEFAULT '',
			place varchar(60) NOT NULL DEFAULT '',
			work varchar(200) NOT NULL DEFAULT '',
			candidates longtext NULL,
			finds int(10) unsigned NOT NULL DEFAULT 0,
			found_at datetime NULL,
			found_by bigint(20) unsigned NOT NULL DEFAULT 0,
			vetted_idx int(11) NOT NULL DEFAULT -1,
			vetted_note varchar(300) NOT NULL DEFAULT '',
			vetted_at datetime NULL,
			vetted_by bigint(20) unsigned NOT NULL DEFAULT 0,
			emailed_at datetime NULL,
			emailed_by bigint(20) unsigned NOT NULL DEFAULT 0,
			emailed_how varchar(10) NOT NULL DEFAULT '',
			closed_at datetime NULL,
			closed_by bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY case_id (case_id)
		) " . $wpdb->get_charset_collate() . ';' );
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	private static function now(): string {
		return current_time( 'mysql', true );
	}

	/* ── who ─────────────────────────────────────────────────────────────────────────────────────────── */

	/** The shared device, as the Ai router reads it (its capability, its role, or the hierarchy's word for it). */
	public static function is_kiosk( int $uid ): bool {
		return ZANA_LLM_Client::is_shared_device( $uid );
	}

	public static function is_admin_tier( int $uid ): bool {
		if ( class_exists( 'ZDZ_Capabilities' ) && method_exists( 'ZDZ_Capabilities', 'tier_of' ) ) {
			return 'admin' === ZDZ_Capabilities::tier_of( $uid );
		}
		return function_exists( 'user_can' ) && user_can( $uid, 'manage_options' );
	}

	private static function invoke( string $verb, array $args ): array {
		if ( is_callable( self::$invoke ) ) {
			return (array) call_user_func( self::$invoke, $verb, $args );
		}
		return class_exists( 'ZDZ_Capabilities' ) ? ZDZ_Capabilities::invoke( $verb, $args ) : array( 'success' => false );
	}

	private static function has_verb( string $verb ): bool {
		if ( is_callable( self::$invoke ) ) {
			return true;
		}
		return class_exists( 'ZDZ_Capabilities' ) && method_exists( 'ZDZ_Capabilities', 'get' ) && null !== ZDZ_Capabilities::get( $verb );
	}

	/** Why this person may not use the panel at all ('' = they may). */
	public static function refusal( int $uid ): string {
		if ( $uid <= 0 ) {
			return __( 'You need to be signed in for that.', 'zorderz' );
		}
		if ( self::is_kiosk( $uid ) ) {
			return __( 'That isn\'t available on the shared device.', 'zorderz' );
		}
		if ( class_exists( 'ZDZ_Kiosk_Demo' ) && method_exists( 'ZDZ_Kiosk_Demo', 'is_active' ) && ZDZ_Kiosk_Demo::is_active() ) {
			return __( 'Not available in Demo Mode.', 'zorderz' );
		}
		if ( class_exists( 'ZDZ_Privacy_View' ) && ZDZ_Privacy_View::is_on( $uid ) ) {
			return __( 'This account can look, not change anything.', 'zorderz' );
		}
		if ( ! self::has_verb( self::VERB_CASES ) ) {
			return __( 'Territories is not active here, so there are no referral cases.', 'zorderz' );
		}
		return '';
	}

	/**
	 * The cases this person may see, from Territories, asked as them (the registry stamps who asks). Returns a list of
	 * cases, or a string saying why not.
	 *
	 * @return array|string
	 */
	public static function cases_for( int $uid, int $id = 0, bool $closed = false ) {
		$why = self::refusal( $uid );
		if ( '' !== $why ) {
			return $why;
		}
		if ( (int) get_current_user_id() !== $uid ) {
			return __( 'You need to be signed in for that.', 'zorderz' );   // never on someone else's behalf
		}
		$args = array();
		if ( $id > 0 ) {
			$args['id'] = $id;
		}
		if ( $closed ) {
			$args['closed'] = true;
		}
		$a = self::invoke( self::VERB_CASES, $args );
		if ( ! empty( $a['denied'] ) ) {
			return __( 'Referral cases are for the admin tier and each estimate\'s salesperson.', 'zorderz' );
		}
		if ( empty( $a['success'] ) || ! is_array( $a['cases'] ?? null ) ) {
			return __( 'The cases could not be read just now. Try again in a minute.', 'zorderz' );
		}
		return $a['cases'];
	}

	/** One case this person may see, a string saying why not, or null (a case that is not theirs answers the same as none). */
	public static function case_for( int $uid, int $id ) {
		$c = self::cases_for( $uid, $id );
		if ( ! is_array( $c ) ) {
			return $c;
		}
		foreach ( $c as $x ) {
			if ( (int) ( $x['id'] ?? 0 ) === $id ) {
				return $x;
			}
		}
		return null;
	}

	/* ── this app's record of a case ─────────────────────────────────────────────────────────────────── */

	public static function row( int $case_id ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE case_id = %d', $case_id ), ARRAY_A );   // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the table name is this app's
		if ( ! is_array( $r ) ) {
			return null;
		}
		$c               = json_decode( (string) ( $r['candidates'] ?? '' ), true );
		$r['candidates'] = is_array( $c ) ? $c : array();
		foreach ( array( 'id', 'case_id', 'finds', 'found_by', 'vetted_idx', 'vetted_by', 'emailed_by', 'closed_by' ) as $k ) {
			$r[ $k ] = (int) ( $r[ $k ] ?? 0 );
		}
		return $r;
	}

	private static function ensure_row( array $case ): ?array {
		global $wpdb;
		if ( ! self::row( (int) $case['id'] ) ) {
			$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::table() . ' (case_id, zip, place, work) VALUES (%d, %s, %s, %s)',   // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				(int) $case['id'], (string) $case['zip'], mb_substr( (string) $case['place'], 0, 60 ), mb_substr( self::work_words( $case ), 0, 200 ) ) );
		}
		return self::row( (int) $case['id'] );
	}

	private static function save( int $case_id, array $fields ): bool {
		global $wpdb;
		return false !== $wpdb->update( self::table(), $fields, array( 'case_id' => $case_id ) );
	}

	/** "gates and awnings" from a case's items, lower case, for the question and the email. */
	public static function work_words( array $case ): string {
		$w = array();
		foreach ( (array) ( $case['items'] ?? array() ) as $it ) {
			$l = trim( (string) ( is_array( $it ) ? ( $it['label'] ?? '' ) : '' ) );
			if ( '' !== $l ) {
				$w[] = strtolower( $l );
			}
		}
		$w = array_values( array_unique( $w ) );
		if ( count( $w ) <= 1 ) {
			return (string) ( $w[0] ?? __( 'this kind of work', 'zorderz' ) );
		}
		/* translators: 1: a list of kinds of work, 2: the last one */
		return sprintf( __( '%1$s and %2$s', 'zorderz' ), implode( ', ', array_slice( $w, 0, -1 ) ), $w[ count( $w ) - 1 ] );
	}

	/** A case and this app's record of it, as the panel shows it. */
	public static function view( array $case, int $uid ): array {
		$r     = self::row( (int) $case['id'] );
		$cands = $r ? $r['candidates'] : array();
		$vi    = $r ? (int) $r['vetted_idx'] : -1;
		$who   = function ( $u ) { $u = (int) $u; return $u > 0 ? self::initials( $u ) : ''; };
		$block = self::email_blocked( $case );
		return array(
			'id'          => (int) $case['id'],
			'stage'       => (string) ( $case['stage'] ?? '' ),
			'mode'        => (string) ( $case['mode'] ?? '' ),
			'test'        => ! empty( $case['test'] ),
			'zip'         => (string) ( $case['zip'] ?? '' ),
			'place'       => (string) ( $case['place'] ?? '' ),
			'work'        => self::work_words( $case ),
			'created'     => (string) ( $case['created'] ?? '' ),
			'estimate'    => (string) ( $case['estimate_number'] ?? '' ),
			'project'     => (string) ( $case['project'] ?? '' ),
			'salesperson' => (string) ( $case['salesperson']['initials'] ?? '' ),
			'candidates'  => $cands,
			'vetted'      => $vi >= 0 && isset( $cands[ $vi ] ) ? $vi : -1,
			'note'        => $r ? (string) $r['vetted_note'] : '',
			'found'       => $r && $r['found_at'] ? array( 'at' => $r['found_at'], 'by' => $who( $r['found_by'] ) ) : null,
			'vettedBy'    => $r && $r['vetted_at'] ? array( 'at' => $r['vetted_at'], 'by' => $who( $r['vetted_by'] ) ) : null,
			'emailed'     => $r && $r['emailed_at'] ? array( 'at' => $r['emailed_at'], 'by' => $who( $r['emailed_by'] ), 'how' => $r['emailed_how'] ) : null,
			'canClose'    => self::is_admin_tier( $uid ),
			'saved'       => ! empty( $case['saved'] ),
			'findsLeft'   => max( 0, self::MAX_FINDS_CASE - self::finds_today( (int) $case['id'] ) ),
			'mayEmail'    => '' === $block,
			'emailBlocked' => $block,
		);
	}

	private static function initials( int $uid ): string {
		$i = strtoupper( trim( (string) get_user_meta( $uid, 'zdz_user_initials', true ) ) );
		if ( preg_match( '/^[A-Z]{2,3}$/', $i ) ) {
			return $i;
		}
		$u = get_userdata( $uid );
		return $u ? (string) preg_split( '/\s+/', trim( (string) ( $u->display_name ?? '' ) ) )[0] : '';
	}

	/* ── 1. Find a provider: one web search, the question only ───────────────────────────────────────── */

	/**
	 * The question, and nothing else: the ZIP, the place and the kind of work. Never a customer's name, street, phone or
	 * email (Territories has none; this is built from the case alone), never the estimate number, never business data.
	 */
	public static function question( array $case ): string {
		$zip   = preg_replace( '/[^0-9A-Za-z \-]/', '', (string) ( $case['zip'] ?? '' ) );
		$place = trim( (string) preg_replace( '/[^\p{L}\p{N} .\'\-]/u', '', (string) ( $case['place'] ?? '' ) ) );
		$work  = trim( (string) preg_replace( '/[^\p{L}\p{N} ,\/&\-]/u', '', self::work_words( $case ) ) );
		return 'Which local companies install or repair ' . $work . ' for homes in ' . ( '' !== $place ? $place . ' ' : '' ) . 'ZIP ' . $zip
			. '? List up to ' . self::MAX_CANDIDATES . ' that serve that area, each with its public website, its business phone and the page you found it on.';
	}

	/** The instructions: fixed words, no data. */
	public static function system_prompt(): string {
		return 'You find local service companies on the web. Answer with JSON only, no other words: '
			. '{"candidates":[{"name":"","website":"","phone":"","source":""}]}. Only real businesses you found on the web in this '
			. 'search, each with a website or phone you saw published. If you find none, answer {"candidates":[]}. Never invent one.';
	}

	private static function finds_today( int $case_id ): int {
		$n = get_transient( 'zana_ref_finds_c' . $case_id );
		return is_numeric( $n ) ? (int) $n : 0;
	}

	private static function bump_finds( int $case_id, int $uid ): void {
		set_transient( 'zana_ref_finds_c' . $case_id, self::finds_today( $case_id ) + 1, 86400 );
		$k = 'zana_ref_finds_u' . $uid;
		$n = get_transient( $k );
		set_transient( $k, ( is_numeric( $n ) ? (int) $n : 0 ) + 1, 86400 );
	}

	/** An answer that could not be read gives the case its search back; the person's own daily ceiling keeps it. */
	private static function give_back_find( int $case_id ): void {
		$n = self::finds_today( $case_id );
		if ( $n > 0 ) {
			set_transient( 'zana_ref_finds_c' . $case_id, $n - 1, 86400 );
		}
	}

	/** The one search call: [ text, stop ] or a WP_Error. */
	private static function search( int $uid, string $system, string $question ) {
		if ( is_callable( self::$search ) ) {
			return call_user_func( self::$search, $system, $question );
		}
		if ( ! class_exists( 'ZDZ_Core_LLM' ) ) {
			return new WP_Error( 'zana_ref', __( 'The Ai is not set up on this site.', 'zorderz' ) );
		}
		$msgs = array( array( 'role' => 'user', 'content' => $question ) );
		if ( ! ZDZ_Core_LLM::web_safe( array_merge( array( array( 'role' => 'system', 'content' => $system ) ), $msgs ) ) ) {
			return new WP_Error( 'zana_ref', __( 'The search question could not be made safe to send, so nothing was sent.', 'zorderz' ) );
		}
		$r = ZDZ_Core_LLM::ask( 'web', array( 'system' => $system, 'messages' => $msgs, 'max_output' => self::FIND_MAX_TOKENS ), array(
			'caller'   => ZANA_LLM_Client::CALLER,
			'user_id'  => $uid,
			'deadline' => time() + self::FIND_BUDGET_S,
		) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$text = class_exists( 'ZANA_LLM_Client' ) ? ZANA_LLM_Client::decode( (string) ( $r['text'] ?? '' ) ) : (string) ( $r['text'] ?? '' );
		return array( $text, (string) ( $r['stop'] ?? '' ) );
	}

	/**
	 * Find candidates for one case. Returns the view (with 'unreadable' when the answer could not be read), or a WP_Error.
	 *
	 * @return array|WP_Error
	 */
	public static function find( int $uid, int $case_id ) {
		self::$may_fetch = true;   // the place name is part of the question (the ZIP alone is looked up)
		$case            = self::case_for( $uid, $case_id );
		self::$may_fetch = false;
		if ( ! is_array( $case ) ) {
			return new WP_Error( 'zana_ref', is_string( $case ) ? $case : __( 'No such case.', 'zorderz' ) );
		}
		$r = self::ensure_row( $case );
		if ( ! $r ) {
			return new WP_Error( 'zana_ref', __( 'The case could not be saved just now.', 'zorderz' ) );
		}
		if ( $r['vetted_idx'] >= 0 ) {
			return new WP_Error( 'zana_ref', __( 'A provider is already vetted for this case.', 'zorderz' ) );
		}
		if ( $r['emailed_at'] || $r['closed_at'] ) {
			return new WP_Error( 'zana_ref', __( 'This case is closed.', 'zorderz' ) );
		}
		$u = get_transient( 'zana_ref_finds_u' . $uid );
		if ( self::finds_today( (int) $case['id'] ) >= self::MAX_FINDS_CASE || ( is_numeric( $u ) && (int) $u >= self::MAX_FINDS_USER ) ) {
			return new WP_Error( 'zana_ref', __( 'That is as many searches as today allows. Try again tomorrow.', 'zorderz' ) );
		}
		if ( '' === (string) ( $case['zip'] ?? '' ) ) {
			return new WP_Error( 'zana_ref', __( 'This case has no ZIP to search near.', 'zorderz' ) );
		}
		self::bump_finds( (int) $case['id'], $uid );
		$res = self::search( $uid, self::system_prompt(), self::question( $case ) );
		if ( is_wp_error( $res ) ) {
			error_log( 'ZANA_Referrals: the provider search did not answer (' . preg_replace( '/[^a-z_]/', '', (string) $res->get_error_code() ) . ').' );
			return new WP_Error( 'zana_ref', 'zana_ref' === $res->get_error_code() ? $res->get_error_message() : __( 'The search did not answer just now. Try again in a minute.', 'zorderz' ) );
		}
		list( $text, $stop ) = $res;
		$read = self::read_answer( (string) $text );
		if ( 'max_tokens' === $stop ) {
			$read['readable'] = false;   // cut off by the cap: whatever parses may be part of the list
		}
		if ( ! $read['readable'] ) {
			// never "none found" for an answer nobody could read; logged by its shape only, never its text
			error_log( sprintf( 'ZANA_Referrals: the provider search answer could not be read (case %d: length %d, "[" %s, "{" %s).',
				(int) $case['id'], strlen( (string) $text ), false !== strpos( (string) $text, '[' ) ? 'yes' : 'no', false !== strpos( (string) $text, '{' ) ? 'yes' : 'no' ) );
			self::give_back_find( (int) $case['id'] );
			return array( 'unreadable' => true ) + self::view( $case, $uid );
		}
		self::save( (int) $case['id'], array(
			'candidates' => wp_json_encode( $read['candidates'] ),
			'finds'      => $r['finds'] + 1,
			'found_at'   => self::now(),
			'found_by'   => $uid,
			'zip'        => (string) $case['zip'],
			'place'      => mb_substr( (string) ( $case['place'] ?? '' ), 0, 60 ),
			'work'       => mb_substr( self::work_words( $case ), 0, 200 ),
		) );
		return self::view( $case, $uid );
	}

	/**
	 * The answer read by rule, and whether it could be read at all.
	 *   readable    the answer holds the list asked for: {"candidates":[...]} or a bare [...] (a true "[]" is readable:
	 *               the search found none). Anything else -- cut off mid-object, words, other JSON -- is not.
	 *   candidates  at most five, each a name with a website or a phone; the site's own address never.
	 *
	 * @return array{readable:bool,candidates:array}
	 */
	public static function read_answer( string $text, ?string $own_host = null ): array {
		$t    = substr( trim( $text ), 0, 20000 );
		$list = null;
		// the object can sit among other words: try each "{" with each "}" from the last back
		foreach ( self::positions( $t, '{', false ) as $a ) {
			foreach ( self::positions( $t, '}', true ) as $b ) {
				if ( $b <= $a ) {
					break;
				}
				$j = json_decode( substr( $t, $a, $b - $a + 1 ), true );
				if ( is_array( $j ) && isset( $j['candidates'] ) && is_array( $j['candidates'] ) && self::is_list( $j['candidates'] ) ) {
					$list = $j['candidates'];
					break 2;
				}
			}
		}
		// a bare list: only one that starts before any object, and only an empty list or a list of objects ("[1]" is a
		// citation, not an answer)
		$first_obj = strpos( $t, '{' );
		if ( null === $list ) {
			foreach ( self::positions( $t, '[', false ) as $s ) {
				if ( false !== $first_obj && $s > $first_obj ) {
					break;
				}
				foreach ( self::positions( $t, ']', true ) as $e ) {
					if ( $e <= $s ) {
						break;
					}
					$k = json_decode( substr( $t, $s, $e - $s + 1 ), true );
					if ( is_array( $k ) && self::is_list( $k ) && count( array_filter( $k, 'is_array' ) ) === count( $k ) && ( ! $k || ! self::is_list( $k[0] ) ) ) {
						$list = $k;
						break 2;
					}
				}
			}
		}
		if ( null === $list ) {
			return array( 'readable' => false, 'candidates' => array() );
		}
		$own  = strtolower( null !== $own_host ? $own_host : (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$out  = array();
		$seen = array();
		foreach ( $list as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$name  = trim( mb_substr( sanitize_text_field( (string) ( $c['name'] ?? '' ) ), 0, 80 ) );
			$site  = self::clean_url( (string) ( $c['website'] ?? '' ) );
			$src   = self::clean_url( (string) ( $c['source'] ?? '' ) );
			$ph    = preg_replace( '/[^\d+]/', '', (string) ( $c['phone'] ?? '' ) );
			$phone = ( strlen( preg_replace( '/\D/', '', $ph ) ) >= 7 && strlen( $ph ) <= 16 ) ? self::phone_text( $ph ) : '';
			if ( mb_strlen( $name ) < 2 || ( '' === $site && '' === $phone ) ) {
				continue;
			}
			$host = strtolower( (string) wp_parse_url( $site, PHP_URL_HOST ) );
			if ( '' !== $own && '' !== $host && preg_replace( '/^www\./', '', $host ) === preg_replace( '/^www\./', '', $own ) ) {
				continue;   // never the business itself
			}
			$k = strtolower( $name );
			if ( isset( $seen[ $k ] ) ) {
				continue;
			}
			$seen[ $k ] = true;
			$out[]      = array( 'name' => $name, 'website' => $site, 'phone' => $phone, 'source' => $src );
			if ( count( $out ) >= self::MAX_CANDIDATES ) {
				break;
			}
		}
		return array( 'readable' => true, 'candidates' => $out );
	}

	/** A phone as written for a person: a ten-digit number with a leading 1 or none in the 3-3-4 shape, else its digits. */
	public static function phone_text( string $ph ): string {
		$d = preg_replace( '/\D/', '', $ph );
		if ( 11 === strlen( $d ) && '1' === $d[0] ) {
			$d = substr( $d, 1 );
		}
		if ( 10 === strlen( $d ) && '+' !== ( $ph[0] ?? '' ) ) {
			return '(' . substr( $d, 0, 3 ) . ') ' . substr( $d, 3, 3 ) . '-' . substr( $d, 6 );
		}
		return ( '+' === ( $ph[0] ?? '' ) ? '+' : '' ) . $d;
	}

	/** Where $ch is in $t (at most 40), first to last or last to first. */
	private static function positions( string $t, string $ch, bool $reverse ): array {
		$out = array();
		$off = 0;
		while ( count( $out ) < ( $reverse ? 400 : 40 ) && false !== ( $p = strpos( $t, $ch, $off ) ) ) {
			$out[] = $p;
			$off   = $p + 1;
		}
		return $reverse ? array_slice( array_reverse( $out ), 0, 40 ) : $out;
	}

	private static function is_list( array $a ): bool {
		return array() === $a || array_keys( $a ) === range( 0, count( $a ) - 1 );
	}

	private static function clean_url( string $u ): string {
		$u = trim( $u );
		if ( '' !== $u && ! preg_match( '#^https?://#i', $u ) && preg_match( '/^[a-z0-9.\-]+\.[a-z]{2,}(\/|$)/i', $u ) ) {
			$u = 'https://' . $u;
		}
		if ( ! preg_match( '#^https?://[^\s<>"\']+$#i', $u ) ) {
			return '';   // a web address and nothing else: no other scheme, no markup
		}
		$u = esc_url_raw( $u, array( 'http', 'https' ) );
		return ( '' !== $u && wp_parse_url( $u, PHP_URL_HOST ) && strlen( $u ) <= 300 ) ? $u : '';
	}

	/* ── 2. Vetted ───────────────────────────────────────────────────────────────────────────────────── */

	/** @return array|WP_Error */
	public static function vet( int $uid, int $case_id, int $idx, string $note = '' ) {
		$case = self::case_for( $uid, $case_id );
		if ( ! is_array( $case ) ) {
			return new WP_Error( 'zana_ref', is_string( $case ) ? $case : __( 'No such case.', 'zorderz' ) );
		}
		$r = self::row( (int) $case['id'] );
		if ( ! $r || $idx < 0 || ! isset( $r['candidates'][ $idx ] ) ) {
			return new WP_Error( 'zana_ref', __( 'Find a provider first, then mark the one you checked.', 'zorderz' ) );
		}
		if ( $r['emailed_at'] || $r['closed_at'] ) {
			return new WP_Error( 'zana_ref', __( 'This case is closed.', 'zorderz' ) );
		}
		$note = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $note ) );
		self::save( (int) $case['id'], array( 'vetted_idx' => $idx, 'vetted_note' => mb_substr( sanitize_text_field( $note ), 0, 300 ), 'vetted_at' => self::now(), 'vetted_by' => $uid ) );
		return self::view( $case, $uid );
	}

	/* ── 3. Press to email this customer ─────────────────────────────────────────────────────────────── */

	/** Why this case may not be emailed from here ('' = it may). Fails closed: any mode but "live" refuses a real customer. */
	public static function email_blocked( array $case ): string {
		if ( ! empty( $case['test'] ) ) {
			return '';
		}
		if ( 'live' !== (string) ( $case['mode'] ?? '' ) ) {
			return __( 'The referral rule is still in shadow, so this customer is not written to yet. It can be emailed once the rule is live (Territories → Rules).', 'zorderz' );
		}
		return '';
	}

	/** The estimate's own id, from the case: through the estimate app's number seam (never another app's table read here). */
	public static function estimate_id( array $case ): int {
		$num = preg_replace( '/[^0-9]/', '', (string) ( $case['estimate_number'] ?? '' ) );
		if ( '' === $num || ! function_exists( 'apply_filters' ) ) {
			return 0;
		}
		return max( 0, (int) apply_filters( 'zdz_estimate_id_for_number', 0, $num ) );
	}

	/**
	 * The email: plain, no exclamation marks, signed with the person's own first name and the business's name. A voice
	 * pack may write its own (filter `zdz_referral_email_draft`: subject and body, with the same inputs).
	 *
	 * @return array{subject:string,body:string}
	 */
	public static function draft( string $first, array $case, array $cand, string $sender ): array {
		$first    = trim( $first );
		$place    = '' !== trim( (string) ( $case['place'] ?? '' ) ) ? trim( (string) $case['place'] ) : __( 'your area', 'zorderz' );
		$work     = self::work_words( $case );
		$business = class_exists( 'ZDZ_Business_Profile' ) ? (string) ZDZ_Business_Profile::name() : '';
		$who      = array( (string) $cand['name'] );
		foreach ( array( 'website', 'phone' ) as $k ) {
			if ( '' !== (string) ( $cand[ $k ] ?? '' ) ) {
				$who[] = (string) $cand[ $k ];
			}
		}
		$lines = array(
			'' !== $first ? sprintf( /* translators: %s: first name */ __( 'Hello %s,', 'zorderz' ), $first ) : __( 'Hello,', 'zorderz' ),
			/* translators: 1: the kind of work, 2: the place */
			sprintf( __( 'Thank you for contacting us about %1$s. We don\'t serve %2$s, so we looked for a company that does:', 'zorderz' ), $work, $place ),
			implode( "\n", $who ),
			__( 'We hope they can help. If there is anything else we can do, reply to this email.', 'zorderz' ),
			__( 'Kind regards,', 'zorderz' ) . "\n" . ( '' !== trim( $sender ) ? trim( $sender ) . "\n" : '' ) . $business,
		);
		$d = array(
			/* translators: %s: the place */
			'subject' => sprintf( __( 'A company that can help in %s', 'zorderz' ), $place ),
			'body'    => trim( implode( "\n\n", $lines ) ),
		);
		if ( function_exists( 'apply_filters' ) ) {
			$f = apply_filters( 'zdz_referral_email_draft', $d, $first, $case, $cand, $sender );
			if ( is_array( $f ) && is_string( $f['subject'] ?? null ) && is_string( $f['body'] ?? null ) ) {
				$d = array( 'subject' => $f['subject'], 'body' => $f['body'] );
			}
		}
		return $d;
	}

	private static function sender_first( int $uid ): string {
		$f = trim( (string) get_user_meta( $uid, 'first_name', true ) );
		if ( '' !== $f ) {
			return $f;
		}
		$u = get_userdata( $uid );
		$d = $u ? trim( (string) ( $u->display_name ?? '' ) ) : '';
		return '' !== $d ? (string) preg_split( '/\s+/', $d )[0] : '';
	}

	/**
	 * A person pressed "Press to email this customer". Only after vetting. The email opens in the Email app or as a
	 * mailto; nothing is sent. Logged with who and when (never the text), and the case closes.
	 *
	 * @return array|WP_Error { mode: inbox|mailto, token?, href, to_known, note }
	 */
	public static function email( int $uid, int $case_id ) {
		self::$may_fetch = true;
		$case            = self::case_for( $uid, $case_id );
		self::$may_fetch = false;
		if ( ! is_array( $case ) ) {
			return new WP_Error( 'zana_ref', is_string( $case ) ? $case : __( 'No such case.', 'zorderz' ) );
		}
		$r = self::row( (int) $case['id'] );
		if ( ! $r || $r['vetted_idx'] < 0 || ! isset( $r['candidates'][ $r['vetted_idx'] ] ) ) {
			return new WP_Error( 'zana_ref', __( 'Mark a provider Vetted first.', 'zorderz' ) );
		}
		if ( $r['emailed_at'] || $r['closed_at'] ) {
			return new WP_Error( 'zana_ref', __( 'This case is closed.', 'zorderz' ) );
		}
		if ( empty( $case['saved'] ) ) {
			return new WP_Error( 'zana_ref', __( 'The estimate app has not saved this stub yet, so there is no customer to write to. An admin can close the case if it was never saved.', 'zorderz' ) );
		}
		$blocked = self::email_blocked( $case );
		if ( '' !== $blocked ) {
			return new WP_Error( 'zana_ref', $blocked );   // before the estimate app is asked for the address
		}
		$to    = '';
		$first = '';
		$note  = '';
		if ( ! empty( $case['test'] ) ) {
			$note = __( 'A test case: the To line is left empty. Nothing is sent.', 'zorderz' );
		} else {
			$eid = self::estimate_id( $case );
			$a   = null;
			if ( $eid > 0 && self::has_verb( self::VERB_CONTACT ) ) {
				$a = self::invoke( self::VERB_CONTACT, array( 'ref' => 'estimate:' . $eid ) );
			}
			if ( is_array( $a ) && ! empty( $a['denied'] ) ) {
				return new WP_Error( 'zana_ref', __( 'The estimate app shows the customer\'s email to the estimate\'s salesperson and administrators. Ask the salesperson.', 'zorderz' ) );
			}
			if ( is_array( $a ) && ! empty( $a['success'] ) ) {
				$to    = sanitize_email( (string) ( $a['email'] ?? '' ) );
				$first = sanitize_text_field( (string) ( $a['first_name'] ?? '' ) );
			}
			if ( '' === $to ) {
				$note = $eid > 0 ? __( 'The estimate has no email address for this customer: add it in the To line.', 'zorderz' ) : __( 'The estimate could not be found: add the customer\'s address in the To line.', 'zorderz' );
			}
		}
		$d    = self::draft( $first, $case, $r['candidates'][ $r['vetted_idx'] ], self::sender_first( $uid ) );
		$href = 'mailto:' . rawurlencode( $to ) . '?subject=' . rawurlencode( $d['subject'] ) . '&body=' . rawurlencode( $d['body'] );
		$h    = has_filter( 'zdz_compose_handoff' ) ? apply_filters( 'zdz_compose_handoff', null, $uid, array( 'to' => $to, 'subject' => $d['subject'], 'body' => $d['body'], 'docs' => array() ) ) : null;
		if ( is_array( $h ) && ! empty( $h['token'] ) && preg_match( '/^[a-f0-9]{32}$/', (string) $h['token'] ) ) {
			$out = array( 'mode' => 'inbox', 'token' => (string) $h['token'], 'app' => (string) ( $h['app'] ?? 'inbox' ) );
		} else {
			$out = array( 'mode' => 'mailto' );
		}
		$out['href'] = $href;   // the person's own mail program, for a page without the app shell
		// opened by this press: the case closes in Territories first, then here, with who and when, never the text
		do_action( 'zdz_referral_closed', (int) $case['id'], $uid, 'emailed' );
		self::save( (int) $case['id'], array( 'emailed_at' => self::now(), 'emailed_by' => $uid, 'emailed_how' => $out['mode'], 'closed_at' => self::now(), 'closed_by' => $uid ) );
		return $out + array( 'to_known' => '' !== $to, 'note' => $note );
	}

	/** An admin closes a case by hand (no email). @return true|WP_Error */
	public static function close( int $uid, int $case_id ) {
		if ( ! self::is_admin_tier( $uid ) ) {
			return new WP_Error( 'zana_ref', __( 'Only the admin tier closes a case without the email.', 'zorderz' ) );
		}
		$case = self::case_for( $uid, $case_id );
		if ( ! is_array( $case ) ) {
			return new WP_Error( 'zana_ref', is_string( $case ) ? $case : __( 'No such case.', 'zorderz' ) );
		}
		self::ensure_row( $case );
		self::save( (int) $case['id'], array( 'closed_at' => self::now(), 'closed_by' => $uid ) );
		do_action( 'zdz_referral_closed', (int) $case['id'], $uid, 'closed' );
		return true;
	}

	/* ── place names (filter zdz_zip_place) ──────────────────────────────────────────────────────────── */

	/** The town for a postal code, from the configured public service asked with the code alone; cached. '' when unknown. */
	public static function zip_place( $place, $zip ) {
		if ( '' !== (string) $place ) {
			return $place;
		}
		$zip = preg_replace( '/[^0-9A-Za-z]/', '', (string) $zip );
		if ( strlen( $zip ) < 3 || strlen( $zip ) > 10 ) {
			return '';
		}
		$k = 'zana_zip_place_' . strtolower( $zip );
		$c = get_transient( $k );
		if ( false !== $c ) {
			return (string) $c;
		}
		$tpl = (string) apply_filters( self::PLACE_URL_OPT, (string) get_option( self::PLACE_URL_OPT, '' ) );
		if ( ! self::$may_fetch || self::$fetches >= 8 || '' === $tpl || false === strpos( $tpl, '{zip}' ) || ! preg_match( '#^https://#i', $tpl ) ) {
			return '';
		}
		self::$fetches++;
		$res = wp_remote_get( str_replace( '{zip}', rawurlencode( $zip ), $tpl ), array( 'timeout' => 4, 'redirection' => 0 ) );
		$out = '';
		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$j   = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			$out = is_array( $j ) ? trim( (string) ( $j['places'][0]['place name'] ?? ( $j['place'] ?? '' ) ) ) : '';
			$out = mb_substr( sanitize_text_field( $out ), 0, 60 );
		}
		set_transient( $k, $out, '' !== $out ? self::PLACE_TTL : self::PLACE_MISS_TTL );
		return $out;
	}

	/* ── the panel's routes (POST, wp_rest nonce; same gate as the chat) ─────────────────────────────── */

	public static function routes(): void {
		$ns   = ZDZ_REST_NS;
		$args = array( 'case' => array( 'sanitize_callback' => 'absint' ) );
		register_rest_route( $ns, '/analytics/referrals', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_list' ), 'permission_callback' => array( 'ZANA_REST', 'can_access' ) ) );
		foreach ( array( 'find', 'vet', 'email', 'close' ) as $step ) {
			register_rest_route( $ns, '/analytics/referrals/' . $step, array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_' . $step ),
				'permission_callback' => array( 'ZANA_REST', 'can_access' ),
				'args'                => $args,
			) );
		}
	}

	private static function rest_reply( $v ) {
		if ( is_wp_error( $v ) ) {
			return new WP_Error( 'zana_ref', $v->get_error_message(), array( 'status' => 400 ) );
		}
		return rest_ensure_response( $v );
	}

	public static function rest_list() {
		$uid = (int) get_current_user_id();
		self::$may_fetch = true;
		$cases           = self::cases_for( $uid );
		self::$may_fetch = false;
		if ( ! is_array( $cases ) ) {
			return new WP_Error( 'zana_ref', $cases, array( 'status' => 400 ) );
		}
		$out = array();
		foreach ( $cases as $c ) {
			$out[] = self::view( $c, $uid );
		}
		return rest_ensure_response( array( 'cases' => $out, 'admin' => self::is_admin_tier( $uid ) ) );
	}

	public static function rest_find( WP_REST_Request $req ) {
		return self::rest_reply( self::find( (int) get_current_user_id(), (int) $req->get_param( 'case' ) ) );
	}

	public static function rest_vet( WP_REST_Request $req ) {
		$idx = $req->get_param( 'idx' );
		return self::rest_reply( self::vet( (int) get_current_user_id(), (int) $req->get_param( 'case' ), is_numeric( $idx ) ? (int) $idx : -1, is_scalar( $req->get_param( 'note' ) ) ? (string) $req->get_param( 'note' ) : '' ) );
	}

	public static function rest_email( WP_REST_Request $req ) {
		return self::rest_reply( self::email( (int) get_current_user_id(), (int) $req->get_param( 'case' ) ) );
	}

	public static function rest_close( WP_REST_Request $req ) {
		$r = self::close( (int) get_current_user_id(), (int) $req->get_param( 'case' ) );
		return self::rest_reply( is_wp_error( $r ) ? $r : array( 'closed' => true ) );
	}

	/* ── the verbs (the chat may propose a step; writes wait for Confirm) ────────────────────────────── */

	public static function register( $caps ) {
		$caps = is_array( $caps ) ? $caps : array();
		$base = array( 'source' => 'sales-analytics', 'app' => ZANA_APP_ID, 'needs_app' => true, 'kiosk' => false, 'kiosk_bounded' => false, 'outside' => false );
		$rows = array(
			'referrals.list'  => array( 'list_verb', 'staff', false, 'The open referral cases this person may work (ZIP, place, the work, candidates found, vetted, emailed). No customer details.' ),
			'referrals.find'  => array( 'find_verb', 'staff', true, 'Search the web for companies that serve one referral case\'s ZIP and kind of work (args: case). One search, the question only.' ),
			'referrals.vet'   => array( 'vet_verb', 'staff', true, 'Mark one found company Vetted for a referral case (args: case, idx, note). Nothing is sent.' ),
			'referrals.email' => array( 'email_verb', 'staff', true, 'Open a filled-in email to the customer of a referral case, naming the vetted company (args: case). Nothing is sent; the case closes.' ),
			'referrals.close' => array( 'close_verb', 'admin', true, 'Close a referral case without an email (args: case). Admin tier only.' ),
		);
		foreach ( $rows as $verb => $r ) {
			$caps[ $verb ] = array(
				'callable'   => array( __CLASS__, $r[0] ),
				'descriptor' => $base + array( 'verb' => $verb, 'min_tier' => $r[1], 'side_effect' => $r[2], 'confirm' => $r[2], 'summary' => $r[3] ),
			);
		}
		return $caps;
	}

	private static function verb_uid( array $args ): int {
		$uid = (int) ( $args['user_id'] ?? 0 );
		return ( $uid > 0 && (int) get_current_user_id() === $uid && empty( $args['is_kiosk'] ) ) ? $uid : 0;
	}

	private static function verb_out( $v, string $verb ): array {
		if ( is_wp_error( $v ) ) {
			return array( 'success' => false, 'message' => $v->get_error_message(), 'verb' => $verb );
		}
		if ( true === $v ) {
			return array( 'success' => true, 'message' => __( 'The case is closed.', 'zorderz' ), 'verb' => $verb );
		}
		return array( 'success' => true, 'verb' => $verb ) + (array) $v;
	}

	public static function list_verb( array $args ): array {
		$uid   = self::verb_uid( $args );
		$cases = $uid > 0 ? self::cases_for( $uid ) : __( 'You need to be signed in for that.', 'zorderz' );
		if ( ! is_array( $cases ) ) {
			return array( 'success' => false, 'message' => (string) $cases, 'verb' => 'referrals.list' );
		}
		$views = array_map( function ( $c ) use ( $uid ) { return self::view( $c, $uid ); }, $cases );
		$lines = array();
		foreach ( $views as $v ) {
			$lines[] = '- ' . $v['zip'] . ( '' !== $v['place'] ? ' (' . $v['place'] . ')' : '' ) . ': ' . $v['work']
				. ( $v['vetted'] >= 0 ? ' · ' . __( 'vetted', 'zorderz' ) : ( $v['candidates'] ? ' · ' . __( 'candidates found', 'zorderz' ) : '' ) );
		}
		return array(
			'success' => true,
			'verb'    => 'referrals.list',
			'cases'   => $views,
			/* translators: %d: how many open cases */
			'message' => $views ? sprintf( _n( '%d case needs a referral:', '%d cases need a referral:', count( $views ), 'zorderz' ), count( $views ) ) . "\n" . implode( "\n", $lines ) : __( 'Nothing needs a referral right now.', 'zorderz' ),
		);
	}

	public static function find_verb( array $args ): array {
		$uid = self::verb_uid( $args );
		return self::verb_out( empty( $args['confirmed'] ) || $uid <= 0 ? new WP_Error( 'zana_ref', __( 'That waits for a press of Confirm.', 'zorderz' ) ) : self::find( $uid, (int) ( $args['case'] ?? 0 ) ), 'referrals.find' );
	}

	public static function vet_verb( array $args ): array {
		$uid = self::verb_uid( $args );
		return self::verb_out( empty( $args['confirmed'] ) || $uid <= 0 ? new WP_Error( 'zana_ref', __( 'That waits for a press of Confirm.', 'zorderz' ) ) : self::vet( $uid, (int) ( $args['case'] ?? 0 ), is_numeric( $args['idx'] ?? null ) ? (int) $args['idx'] : -1, is_scalar( $args['note'] ?? null ) ? (string) $args['note'] : '' ), 'referrals.vet' );
	}

	public static function email_verb( array $args ): array {
		$uid = self::verb_uid( $args );
		return self::verb_out( empty( $args['confirmed'] ) || $uid <= 0 ? new WP_Error( 'zana_ref', __( 'That waits for a press of Confirm.', 'zorderz' ) ) : self::email( $uid, (int) ( $args['case'] ?? 0 ) ), 'referrals.email' );
	}

	public static function close_verb( array $args ): array {
		$uid = self::verb_uid( $args );
		return self::verb_out( empty( $args['confirmed'] ) || $uid <= 0 ? new WP_Error( 'zana_ref', __( 'That waits for a press of Confirm.', 'zorderz' ) ) : self::close( $uid, (int) ( $args['case'] ?? 0 ) ), 'referrals.close' );
	}
}
