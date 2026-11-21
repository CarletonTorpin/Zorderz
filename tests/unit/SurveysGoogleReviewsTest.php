<?php
/**
 * Surveys: the hourly check of the business's own Google reviews.
 *
 * The real check (ZSV_Google_Reviews) on the real connector (ZDZ_Core_Google_Business) and the real
 * list lock (Zdz_Request_Guard::guarded). Stand-ins: the network (scripted pages of reviews), the
 * survey table and the options table (in memory), and a CRM that keeps what it is asked to do.
 * Neutral data only.
 *
 *   A  names: what counts as the same person, and what never does
 *   B  the hourly run, watch-only: what is listed, what is never written, how far it reads
 *   C  automatic recording, and the safety floor it stays under
 *   D  Confirm, "Not them", the panel's list, Google not ready
 *   E  two writers: a Confirm during a run, a review recorded twice, a page that failed
 *   F  who may use the panel
 *
 * NOT PROVEN HERE: two real database connections racing for the list's lock or for one customer's
 * row (the lock row and the one-statement write are modelled), the run's time budget running out
 * between pages, and a real answer from Google.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'ZSV_NONCE' ) ) {
	define( 'ZSV_NONCE', 'zsv_nonce' );
}
foreach ( array( 'MINUTE_IN_SECONDS' => 60, 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400 ) as $zsv210_name => $zsv210_seconds ) {
	if ( ! defined( $zsv210_name ) ) {
		define( $zsv210_name, $zsv210_seconds );
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['zdz_test_options'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $k ) { unset( $GLOBALS['zdz_test_options'][ $k ] ); return true; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return (int) ( $GLOBALS['zdz_test_current_user'] ?? 0 ); }
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $k ) { return $GLOBALS['zdz_test_transients'][ $k ] ?? false; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['zdz_test_transients'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $k ) { unset( $GLOBALS['zdz_test_transients'][ $k ] ); return true; }
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( ...$a ) { return true; }
}
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $f, $ts = null ) { return gmdate( $f, null === $ts ? time() : (int) $ts ); }
}
if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length = 12, $special = true, $extra = false ) { return substr( bin2hex( random_bytes( 16 ) ), 0, (int) $length ); }
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $v ) { return abs( (int) $v ); }
}
if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user() {
		$u = get_userdata( get_current_user_id() );
		return $u ? $u : (object) array( 'ID' => 0, 'display_name' => '' );
	}
}

/**
 * What an AJAX handler answered. The reply helpers below end the handler the way WordPress does
 * (nothing after them runs), by throwing this. They are this file's own only when nothing else
 * defined them first; ZSV210_REPLY_STUBS says so, and the handler tests skip themselves otherwise.
 */
if ( ! class_exists( 'ZSV210_Reply' ) ) {
	class ZSV210_Reply extends RuntimeException {
		public $ok;
		public $data;
		public function __construct( bool $ok, $data ) {
			parent::__construct( 'reply' );
			$this->ok   = $ok;
			$this->data = $data;
		}
	}
}
if ( ! function_exists( 'wp_send_json_success' ) && ! function_exists( 'wp_send_json_error' ) && ! function_exists( 'check_ajax_referer' ) ) {
	define( 'ZSV210_REPLY_STUBS', true );
	function wp_send_json_success( $data = null ) { throw new ZSV210_Reply( true, $data ); }
	function wp_send_json_error( $data = null ) { throw new ZSV210_Reply( false, $data ); }
	function check_ajax_referer( $action = -1, $arg = false ) {
		$GLOBALS['zsv210_referer'][] = array( $action, $arg );
		if ( ( $_POST[ (string) $arg ] ?? '' ) !== 'good-' . $action ) {
			throw new ZSV210_Reply( false, 'bad nonce' );
		}
		return 1;
	}
}

$zsv210_root = dirname( __DIR__, 2 );
require_once $zsv210_root . '/zorderz/inc/class-zdz-core-crypto.php';
require_once $zsv210_root . '/zorderz/inc/class-zdz-core-credentials.php';
require_once $zsv210_root . '/zorderz/inc/class-zdz-staging-guard.php';
require_once $zsv210_root . '/zorderz/inc/class-zdz-core-google-business.php';
require_once $zsv210_root . '/zorderz/inc/class-zdz-request-guard.php';
$zsv210_inc = $zsv210_root . '/zorderz-apps/apps/surveys/includes/';
require_once $zsv210_inc . 'class-zsv-db.php';
require_once $zsv210_inc . 'class-zsv-settings.php';
require_once $zsv210_inc . 'class-zsv-budget.php';
require_once $zsv210_inc . 'class-zsv-owed.php';
require_once $zsv210_inc . 'class-zsv-note-reader.php';
require_once $zsv210_inc . 'class-zsv-send-once.php';
require_once $zsv210_inc . 'class-zsv-survey-manager.php';
require_once $zsv210_inc . 'class-zsv-dashboard.php';
require_once $zsv210_inc . 'class-zsv-google-reviews.php';

/**
 * The survey table and the options table in memory. The list's lock is a row in the options table
 * (INSERT IGNORE takes it, a DELETE naming its own value lets it go); the statement that records a
 * review writes only a customer with no review yet, as its WHERE says.
 */
if ( ! class_exists( 'ZSV210_Reviews_DB' ) ) {
	class ZSV210_Reviews_DB {
		public $prefix      = 'wp_';
		public $options     = 'wp_options';
		public $last_error  = '';
		public $rows        = array();   // survey rows by id
		public $lock_rows   = array();   // option name => value, for the rows the lock makes
		public $fail_record = false;     // the statement that records a review writes nothing
		public $writes      = array();   // every statement that changed a survey row

		public function prepare( $q, ...$a ) {
			if ( 1 === count( $a ) && is_array( $a[0] ) ) {
				$a = $a[0];
			}
			return array( 'q' => $q, 'a' => $a );
		}

		public function get_var( $p ) {
			$q = is_array( $p ) ? $p['q'] : (string) $p;
			$a = is_array( $p ) ? $p['a'] : array();
			if ( false !== strpos( $q, 'GET_LOCK(' ) || false !== strpos( $q, 'RELEASE_LOCK(' ) ) {
				return '1';
			}
			if ( false !== strpos( $q, 'SELECT option_value FROM' ) ) {
				$n = (string) $a[0];
				if ( isset( $this->lock_rows[ $n ] ) ) {
					return $this->lock_rows[ $n ];
				}
				if ( ! array_key_exists( $n, (array) ( $GLOBALS['zdz_test_options'] ?? array() ) ) ) {
					return null;
				}
				$v = $GLOBALS['zdz_test_options'][ $n ];
				return is_array( $v ) ? serialize( $v ) : (string) $v;
			}
			if ( false !== strpos( $q, 'SELECT crm_status FROM' ) ) {
				return $this->rows[ (int) $a[0] ]['crm_status'] ?? null;
			}
			return null;
		}

		public function get_row( $p, $out = null ) {
			$q  = is_array( $p ) ? $p['q'] : (string) $p;
			$id = (int) ( is_array( $p ) ? ( $p['a'][0] ?? 0 ) : 0 );
			if ( false !== strpos( $q, 'SELECT id, crm_lead_id, review_left, operator_status' ) && isset( $this->rows[ $id ] ) ) {
				$r = $this->rows[ $id ];
				return array( 'id' => $r['id'], 'crm_lead_id' => $r['crm_lead_id'], 'review_left' => $r['review_left'], 'operator_status' => $r['operator_status'] );
			}
			return null;
		}

		public function get_results( $p, $out = null ) {
			$q = is_array( $p ) ? $p['q'] : (string) $p;
			if ( false !== strpos( $q, 'created_at >= %s AND created_at <= %s' ) ) {
				list( $from, $to ) = $p['a'];
				$open_only         = false !== strpos( $q, 'review_left = 0 OR review_left IS NULL' );   // the statement decides, not the stand-in
				$hit               = array_filter(
					$this->rows,
					static function ( $r ) use ( $from, $to, $open_only ) {
						return $r['created_at'] >= $from && $r['created_at'] <= $to && ( ! $open_only || empty( $r['review_left'] ) );
					}
				);
				usort( $hit, static function ( $x, $y ) { return strcmp( $y['created_at'], $x['created_at'] ); } );
				return array_map( static function ( $r ) { return array( 'id' => $r['id'], 'first_name' => $r['first_name'], 'last_name' => $r['last_name'] ); }, $hit );
			}
			if ( preg_match( '/WHERE id IN \(([0-9,]+)\)/', $q, $m ) ) {
				$ids = array_map( 'intval', explode( ',', $m[1] ) );
				return array_values( array_filter( $this->rows, static function ( $r ) use ( $ids ) { return in_array( (int) $r['id'], $ids, true ); } ) );
			}
			return array();
		}

		public function query( $p ) {
			$q = is_array( $p ) ? $p['q'] : (string) $p;
			$a = is_array( $p ) ? $p['a'] : array();
			if ( false !== strpos( $q, 'INSERT IGNORE INTO ' . $this->options ) ) {
				if ( isset( $this->lock_rows[ (string) $a[0] ] ) ) {
					return 0;
				}
				$this->lock_rows[ (string) $a[0] ] = (string) $a[1];
				return 1;
			}
			if ( false !== strpos( $q, 'DELETE FROM ' . $this->options ) ) {
				$n = (string) $a[0];
				if ( isset( $this->lock_rows[ $n ] ) && ( ! isset( $a[1] ) || $this->lock_rows[ $n ] === (string) $a[1] ) ) {
					unset( $this->lock_rows[ $n ] );
					return 1;
				}
				return 0;
			}
			if ( false !== strpos( $q, "SET review_left = 1, review_source = 'google'" ) ) {
				list( $date, $snip, $id ) = $a;
				$guarded                  = false !== strpos( $q, 'review_left = 0 OR review_left IS NULL' );
				if ( $this->fail_record || ! isset( $this->rows[ $id ] ) || ( $guarded && ! empty( $this->rows[ $id ]['review_left'] ) ) ) {
					return 0;
				}
				$this->rows[ $id ] = array_merge( $this->rows[ $id ], array( 'review_left' => 1, 'review_source' => 'google', 'review_date' => $date, 'review_snippet' => $snip ) );
				$this->writes[]    = 'review:' . $id;
				return 1;
			}
			return 1;
		}

		public function update( $table, $data, $where, $f = null, $wf = null ) {
			$id = (int) ( $where['id'] ?? 0 );
			if ( ! isset( $this->rows[ $id ] ) ) {
				return 0;
			}
			$this->rows[ $id ] = array_merge( $this->rows[ $id ], $data );
			$this->writes[]    = 'update:' . $id . ':' . implode( ',', array_keys( $data ) );
			return 1;
		}
	}
}

/** A CRM that closes what it is asked to close and keeps the notes it is given, per lead. */
if ( ! class_exists( 'ZSV210_Reviews_CRM' ) ) {
	class ZSV210_Reviews_CRM {
		public $notes  = array();   // crm lead id => [ body, … ]
		public $closed = array();
		public $calls  = 0;
		public function resolve_won_outcome() { $this->calls++; return array( 'id' => 5, 'name' => 'Won' ); }
		public function close_lead_by_outcome( $lead_id, $outcome_id, $note = '' ) {
			$this->calls++;
			$this->closed[ (int) $lead_id ] = true;
			return array( 'closed' => true, 'verified' => true, 'status' => 1 );
		}
		public function add_note( $a ) {
			$this->calls++;
			$this->notes[ (int) $a['entity']['id'] ][] = (string) $a['note']['body'];
			return array( 'id' => 1 );
		}
		public function get_activities( $q ) { $this->calls++; return array(); }
		public function get_lead( $id, $rev = '0' ) { $this->calls++; return array( 'status' => empty( $this->closed[ (int) $id ] ) ? 0 : 1 ); }
	}
}

final class SurveysGoogleReviewsTest extends TestCase {

	const NOW        = 1773176400;   // 2026-03-10 21:00:00 UTC
	const STAR_WORDS = array( 1 => 'ONE', 2 => 'TWO', 3 => 'THREE', 4 => 'FOUR', 5 => 'FIVE' );
	const ADMIN      = 9107;
	const OTHER      = 9108;

	private $prev_db;
	private $prev_log;
	private $log_file;
	/** @var ZSV210_Reviews_DB */
	private $db;
	/** @var ZSV210_Reviews_CRM */
	private $crm;

	protected function setUp(): void {
		$this->prev_db   = $GLOBALS['wpdb'] ?? null;
		$this->db        = new ZSV210_Reviews_DB();
		$GLOBALS['wpdb'] = $this->db;
		$GLOBALS['zdz_test_options']      = array();
		$GLOBALS['zdz_test_transients']   = array();
		$GLOBALS['zdz_test_current_user'] = self::ADMIN;
		$GLOBALS['zdz_test_user_roles']   = array( self::ADMIN => array( 'administrator' ), self::OTHER => array( 'subscriber' ) );
		$GLOBALS['zdz_test_user_names']   = array( self::ADMIN => 'Sam Example', self::OTHER => 'Blake Example' );
		$GLOBALS['zsv210_referer']        = array();
		$GLOBALS['zsv210_gr']             = array( 'pages' => array(), 'asked' => array(), 'calls' => 0, 'during' => null );
		$_POST                            = array();

		// The Credentials entry the connector reads its client from (the kernel adds it at boot).
		$GLOBALS['zdz_test_filters']['zdz_core_credentials_services'] = array( 'ZDZ_Core_Google_Business', 'add_service' );
		ZDZ_Core_Google_Business::$now       = self::NOW;
		ZDZ_Core_Google_Business::$transport = static function ( string $method, string $url, array $args ): array {
			$GLOBALS['zsv210_gr']['calls']++;
			if ( 0 === strpos( $url, ZDZ_Core_Google_Business::TOKEN_URL ) ) {
				return array( 200, (string) json_encode( array( 'access_token' => 'ya29.example-access-token-0004', 'expires_in' => 3599 ) ) );
			}
			$q = array();
			parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
			$token                           = (string) ( $q['pageToken'] ?? '' );
			$GLOBALS['zsv210_gr']['asked'][] = $token;
			if ( $GLOBALS['zsv210_gr']['during'] ) {   // what another request does while this page is on its way
				$f                              = $GLOBALS['zsv210_gr']['during'];
				$GLOBALS['zsv210_gr']['during'] = null;
				$f();
			}
			$page = $GLOBALS['zsv210_gr']['pages'][ $token ] ?? array( 200, array( 'reviews' => array() ) );
			return array( (int) $page[0], is_string( $page[1] ) ? $page[1] : (string) json_encode( $page[1] ) );
		};
		( new ReflectionProperty( 'ZDZ_Staging_Guard', 'status' ) )->setValue( null, array( 'active' => false, 'reason' => 'live' ) );
		self::connect();

		ZSV_Budget::close();
		ZSV_Owed::$now            = null;
		ZSV_Google_Reviews::$now  = self::NOW;
		$this->crm                = new ZSV210_Reviews_CRM();
		ZSV_Google_Reviews::$manager = new ZSV_Survey_Manager( new stdClass(), $this->crm );

		$this->log_file = tempnam( sys_get_temp_dir(), 'zsv210-gr-' );
		$this->prev_log = ini_set( 'error_log', $this->log_file );
	}

	protected function tearDown(): void {
		ini_set( 'error_log', false === $this->prev_log ? '' : (string) $this->prev_log );
		if ( $this->log_file && file_exists( $this->log_file ) ) {
			unlink( $this->log_file );
		}
		ZSV_Budget::close();
		ZSV_Google_Reviews::$now             = null;
		ZSV_Google_Reviews::$manager         = null;
		ZDZ_Core_Google_Business::$now       = null;
		ZDZ_Core_Google_Business::$transport = null;
		( new ReflectionProperty( 'ZDZ_Staging_Guard', 'status' ) )->setValue( null, null );
		$GLOBALS['wpdb'] = $this->prev_db;
		$_POST           = array();
		unset(
			$GLOBALS['zdz_test_options'],
			$GLOBALS['zdz_test_transients'],
			$GLOBALS['zdz_test_current_user'],
			$GLOBALS['zdz_test_user_roles'],
			$GLOBALS['zdz_test_user_names'],
			$GLOBALS['zdz_test_user_caps'],
			$GLOBALS['zdz_test_filters']['zdz_core_credentials_services'],
			$GLOBALS['zdz_test_filters']['zsv_greviews_lookback_days'],
			$GLOBALS['zdz_test_filters']['zsv_greviews_candidate_days'],
			$GLOBALS['zsv210_referer'],
			$GLOBALS['zsv210_gr']
		);
	}

	/* ── helpers ─────────────────────────────────────────────────────────────────── */

	/** The site is connected to Google: a client, a sign-in and a picked location. */
	private static function connect(): void {
		$GLOBALS['zdz_test_options']['zdz_core_gbp_client_id']     = ZDZ_Core_Crypto::encode( 'example-client-id-0001', ZDZ_Core_Crypto::FIXED );
		$GLOBALS['zdz_test_options']['zdz_core_gbp_client_secret'] = ZDZ_Core_Crypto::encode( 'example-client-secret-0002', ZDZ_Core_Crypto::FIXED );
		$GLOBALS['zdz_test_options']['zdz_core_gbp_token']         = array( 'refresh' => ZDZ_Core_Crypto::encode( '1//example-refresh-token-0003', ZDZ_Core_Crypto::FIXED ), 'at' => self::NOW - 86400, 'by' => self::ADMIN );
		$GLOBALS['zdz_test_options']['zdz_core_gbp_location']      = array( 'account' => 'accounts/1001', 'location' => 'locations/2002', 'title' => 'Example Co' );
	}

	/** A surveyed customer whose follow-up was opened $days_ago before now. */
	private function cust( int $id, string $first, string $last, float $days_ago, array $more = array() ): array {
		return array_merge(
			array(
				'id'              => $id,
				'first_name'      => $first,
				'last_name'       => $last,
				'email'           => 'customer' . $id . '@example.com',
				'crm_lead_id'     => (string) ( 9000 + $id ),
				'crm_status'      => 'Open',
				'operator_status' => 'satisfied',
				'created_at'      => gmdate( 'Y-m-d H:i:s', self::NOW - (int) ( $days_ago * 86400 ) ),
				'review_left'     => 0,
				'review_source'   => null,
				'review_date'     => null,
				'review_snippet'  => null,
			),
			$more
		);
	}

	private function seed( array ...$rows ): void {
		foreach ( $rows as $r ) {
			$this->db->rows[ $r['id'] ] = $r;
		}
	}

	/** One review as Google sends it, made $hours_ago before now. */
	private static function rv( string $id, string $who, int $stars, float $hours_ago, string $comment = 'Tidy work, on time.', bool $anon = false ): array {
		$t = gmdate( 'Y-m-d\TH:i:s\Z', self::NOW - (int) ( $hours_ago * 3600 ) );
		return array(
			'reviewId'   => $id,
			'reviewer'   => array( 'displayName' => $who, 'isAnonymous' => $anon ),
			'starRating' => self::STAR_WORDS[ $stars ],
			'comment'    => $comment,
			'createTime' => $t,
			'updateTime' => $t,
		);
	}

	/** What Google answers for one page. */
	private static function page( string $token, array $reviews, string $next = '' ): void {
		$GLOBALS['zsv210_gr']['pages'][ $token ] = array( 200, array( 'reviews' => $reviews, 'nextPageToken' => $next, 'totalReviewCount' => 40, 'averageRating' => 4.8 ) );
	}

	private static function pages_reset(): void {
		$GLOBALS['zsv210_gr']['pages'] = array();
		$GLOBALS['zsv210_gr']['asked'] = array();
	}

	private static function asked(): array {
		return $GLOBALS['zsv210_gr']['asked'];
	}

	private static function status_of( string $id ): ?string {
		$s = ZSV_Google_Reviews::seen();
		return isset( $s[ $id ] ) ? (string) $s[ $id ]['status'] : null;
	}

	private function log_text(): string {
		return (string) file_get_contents( $this->log_file );
	}

	private function reviewed_ids(): array {
		return array_keys( array_filter( $this->db->rows, static function ( $r ) { return ! empty( $r['review_left'] ); } ) );
	}

	private function lock_held(): bool {
		return array() !== $this->db->lock_rows;
	}

	/** Run one AJAX handler as the signed-in person: [ ok, data ]. */
	private function ajax( string $handler, array $post = array() ): array {
		$_POST = $post + array( 'nonce' => 'good-' . ZSV_NONCE );
		try {
			call_user_func( array( 'ZSV_Google_Reviews', $handler ) );
		} catch ( ZSV210_Reply $r ) {
			return array( $r->ok, $r->data );
		} finally {
			$_POST = array();
		}
		return array( null, null );
	}

	/* ── A. names ────────────────────────────────────────────────────────────────── */

	public function test_how_a_reviewers_name_fits_one_customer(): void {
		$f = static function ( string $reviewer, string $first, string $last ): string {
			return ZSV_Google_Reviews::fit( $reviewer, $first, $last );
		};
		$this->assertSame( 'full', $f( 'Jordan Linden', 'Jordan', 'Linden' ) );
		$this->assertSame( 'full', $f( 'Jordan A. Linden', 'Jordan', 'Linden' ), 'a middle initial' );
		$this->assertSame( 'full', $f( 'JORDAN  linden', 'Jordan', 'Linden' ), 'case and spacing do not matter' );
		$this->assertSame( 'initial', $f( 'Jordan L.', 'Jordan', 'Linden' ) );
		$this->assertSame( 'initial', $f( 'jordan l', 'Jordan', 'Linden' ) );
		$this->assertSame( 'first', $f( 'Jordan', 'Jordan', 'Linden' ), 'a first name alone is never more than possible' );
		$this->assertSame( 'first', $f( 'Jordan Brook', 'Jordan', 'Linden' ), 'another surname: first name only' );
		$this->assertSame( 'last', $f( 'Sam Linden', 'Jordan', 'Linden' ), 'a partner, or a nickname' );
		$this->assertSame( 'full', $f( 'Riley Linden', 'Sam & Riley', 'Linden' ), 'either of a couple' );
		$this->assertSame( 'full', $f( 'Sam Linden', 'Sam and Riley', 'Linden' ) );
		$this->assertSame( 'full', $f( "Dev\u{00ED}n O\u{2019}Marsh", 'Devin', "O'Marsh" ), 'accents and apostrophes' );
		$this->assertSame( '', $f( 'Morgan Moss', 'Jordan', 'Linden' ), 'a different person' );
		$this->assertSame( '', $f( '', 'Jordan', 'Linden' ) );
		$this->assertSame( '', $f( 'Jordan Linden', '', 'Linden' ), 'a customer with no first name fits nobody' );
	}

	public function test_a_name_is_compared_as_whole_words_never_as_part_of_one(): void {
		$f = static function ( string $reviewer, string $first, string $last ): string {
			return ZSV_Google_Reviews::fit( $reviewer, $first, $last );
		};
		$this->assertSame( '', $f( 'Jordana Lindens', 'Jordan', 'Linden' ), 'a longer word is another name' );
		$this->assertSame( '', $f( 'Jo Lin', 'Jordan', 'Linden' ), 'and so is a shorter one' );
		$this->assertSame( 'first', $f( 'Jordan Lindenmoss', 'Jordan', 'Linden' ), 'a surname that only starts the same is not the surname' );
		$this->assertSame( '', $f( 'Lin', 'Jordan', 'Linden' ) );
		$this->assertSame( '', $f( 'Morgan L', 'Jordan', 'Linden' ), 'a last initial with another first name is nobody' );
	}

	public function test_suffixes_double_surnames_and_middle_names(): void {
		$f = static function ( string $reviewer, string $first, string $last ): string {
			return ZSV_Google_Reviews::fit( $reviewer, $first, $last );
		};
		$this->assertSame( 'full', $f( 'Jordan Linden', 'Jordan', 'Linden Jr.' ), 'a suffix is not the surname' );
		$this->assertSame( 'first', $f( 'Jordan J.', 'Jordan', 'Linden Jr.' ), 'and its letter is not the surname\'s initial' );
		$this->assertSame( 'initial', $f( 'Jordan L.', 'Jordan', 'Linden Jr.' ) );
		$this->assertSame( 'full', $f( 'Jordan Linden Jr', 'Jordan', 'Linden' ) );
		$this->assertSame( 'full', $f( 'Robin Alder Birch', 'Robin', 'Alder-Birch' ), 'a double surname, with or without its hyphen' );
		$this->assertSame( 'full', $f( 'Robin Alder-Birch', 'Robin', 'Alder Birch' ) );
		$this->assertSame( 'full', $f( 'Jordan Moss Linden', 'Jordan', 'Linden' ), 'a middle name before the surname' );
	}

	public function test_the_verdict_for_one_review(): void {
		$c = array(
			array( 'id' => 1, 'first_name' => 'Jordan', 'last_name' => 'Linden' ),
			array( 'id' => 2, 'first_name' => 'Jordan', 'last_name' => 'Larch' ),
			array( 'id' => 3, 'first_name' => 'Morgan', 'last_name' => 'Moss' ),
		);
		$m = static function ( string $who, bool $anon = false ) use ( $c ): array {
			return ZSV_Google_Reviews::match( array( 'reviewer' => $who, 'anonymous' => $anon ), $c );
		};
		$this->assertSame( array( 'verdict' => 'sure', 'ids' => array( 1 ) ), $m( 'Jordan Linden' ) );
		$this->assertSame( array( 'verdict' => 'possible', 'ids' => array( 1, 2 ) ), $m( 'Jordan L.' ), 'the initial fits two customers: a person decides' );
		$this->assertSame( array( 'verdict' => 'possible', 'ids' => array( 1, 2 ) ), $m( 'Jordan' ), 'a first name alone is never sure' );
		$this->assertSame( array( 'verdict' => 'possible', 'ids' => array( 3 ) ), $m( 'Morgan' ), 'not even when only one customer has it' );
		$this->assertSame( array( 'verdict' => 'possible', 'ids' => array( 3 ) ), $m( 'Casey Moss' ), 'nor a surname alone' );
		$this->assertSame( 'none', $m( 'A Google user', true )['verdict'], 'anonymous' );
		$this->assertSame( 'none', $m( 'Jordan Linden', true )['verdict'], 'anonymous, whatever name is shown' );
		$this->assertSame( 'none', $m( 'Casey Stone' )['verdict'], 'a stranger' );
		$this->assertSame( 'none', $m( '   ' )['verdict'] );
	}

	public function test_two_customers_with_the_same_full_name_are_never_sure_and_at_most_four_are_offered(): void {
		$c = array();
		for ( $i = 1; $i <= 6; $i++ ) {
			$c[] = array( 'id' => $i, 'first_name' => 'Jordan', 'last_name' => 'Linden' );
		}
		$v = ZSV_Google_Reviews::match( array( 'reviewer' => 'Jordan Linden', 'anonymous' => false ), $c );
		$this->assertSame( 'possible', $v['verdict'] );
		$this->assertSame( array( 1, 2, 3, 4 ), $v['ids'] );
	}

	/* ── B. the hourly run, watch-only ───────────────────────────────────────────── */

	private function seed_five(): void {
		$this->seed(
			$this->cust( 1, 'Jordan', 'Linden', 20 ),
			$this->cust( 2, 'Morgan', 'Moss', 30 ),
			$this->cust( 3, 'Morgan', 'Mill', 25 ),
			$this->cust( 4, 'Casey', 'Winter', 200 ),
			$this->cust( 5, 'Riley', 'Reed', 10, array( 'review_left' => 1 ) )
		);
	}

	private static function six_reviews(): array {
		return array(
			self::rv( 'g4', 'Riley Reed', 5, 1 ),               // already has a review: not offered
			self::rv( 'g3', 'A Google user', 4, 2, '', true ),
			self::rv( 'g2', 'Morgan M', 5, 3 ),                 // two customers fit
			self::rv( 'g1', 'Jordan Linden', 5, 4 ),            // one customer, full name
			self::rv( 'g0', 'Casey Winter', 5, 5 ),             // their follow-up was opened 200 days ago
			self::rv( 'gx', 'Jordan Linden', 5, 24 * 70 ),      // older than the look-back
		);
	}

	public function test_not_connected_the_check_stops_at_once_and_says_so_once(): void {
		$this->seed_five();
		unset( $GLOBALS['zdz_test_options']['zdz_core_gbp_location'] );
		$r = ZSV_Google_Reviews::run();
		$this->assertFalse( $r['ran'] );
		$this->assertSame( 'not_ready', $r['why'] );
		$this->assertSame( array(), ZSV_Google_Reviews::seen() );
		$this->assertSame( 0, $GLOBALS['zsv210_gr']['calls'], 'nothing was asked of Google, not even a sign-in' );
		$this->assertFalse( $this->lock_held() );
		$this->assertSame( self::NOW, get_option( ZSV_Google_Reviews::OPT_LAST )['at'] );

		ZSV_Google_Reviews::$now = self::NOW + 3600;
		ZSV_Google_Reviews::run();
		$this->assertSame( self::NOW, get_option( ZSV_Google_Reviews::OPT_LAST )['at'], 'the next hour writes nothing more' );
		$this->assertFalse( ZSV_Google_Reviews::list_payload( self::ADMIN )['ready'], 'and the panel is told to stay hidden' );
	}

	public function test_watch_only_lists_what_it_would_do_and_writes_nothing(): void {
		$this->seed_five();
		self::page( '', self::six_reviews() );
		$before = $this->db->rows;
		$r      = ZSV_Google_Reviews::run();

		$this->assertTrue( $r['ran'] );
		$this->assertSame( 6, $r['read'] );
		$this->assertSame( 5, $r['new'], 'the one older than the look-back is not listed' );
		$this->assertSame( 'would', self::status_of( 'g1' ) );
		$this->assertSame( array( 1 ), ZSV_Google_Reviews::seen()['g1']['ids'] );
		$this->assertSame( 'possible', self::status_of( 'g2' ) );
		$this->assertSame( array( 3, 2 ), ZSV_Google_Reviews::seen()['g2']['ids'], 'both customers the initial fits are offered' );
		$this->assertSame( 'none', self::status_of( 'g3' ), 'anonymous' );
		$this->assertSame( 'none', self::status_of( 'g0' ), 'a customer outside the candidate window' );
		$this->assertSame( 'none', self::status_of( 'g4' ), 'a customer who already has a review' );
		$this->assertNull( self::status_of( 'gx' ) );

		$this->assertSame( $before, $this->db->rows, 'no customer was written' );
		$this->assertSame( array(), $this->db->writes );
		$this->assertSame( 0, $this->crm->calls, 'and the CRM was not asked anything' );
		$this->assertSame( array( 'ran' => true, 'why' => '', 'read' => 6, 'new' => 5, 'sure' => 1, 'recorded' => 0, 'possible' => 1, 'none' => 3 ), $r );
		$this->assertFalse( ZSV_Budget::is_open(), 'the run\'s time budget is closed' );
		$this->assertFalse( $this->lock_held(), 'and its lock let go' );
	}

	public function test_the_log_counts_and_names_no_one(): void {
		$this->seed_five();
		self::page( '', self::six_reviews() );
		ZSV_Google_Reviews::run();
		$log = $this->log_text();
		$this->assertMatchesRegularExpression( '/read 6, new 5, sure 1 \(watch-only\), recorded 0, possible 1, no match 3/', $log );
		foreach ( array( 'Jordan', 'Linden', 'Morgan', 'Riley', 'Casey', 'example.com', 'Tidy work' ) as $word ) {
			$this->assertStringNotContainsString( $word, $log );
		}
	}

	public function test_what_is_kept_about_a_review_is_its_public_part_only(): void {
		$this->seed_five();
		self::page( '', array( self::rv( 'g1', 'Jordan Linden', 5, 4, str_repeat( 'Very tidy work. ', 40 ) ) ) );
		ZSV_Google_Reviews::run();
		$kept = ZSV_Google_Reviews::seen()['g1'];
		$this->assertSame( array( 'id', 'reviewer', 'stars', 'create', 'snippet', 'status', 'ids', 'at', 'lead', 'by' ), array_keys( $kept ) );
		$this->assertSame( 140, strlen( $kept['snippet'] ), 'the first words of the review, not all of it' );
		$this->assertSame( self::NOW - 4 * 3600, $kept['create'] );
		$this->assertStringNotContainsString( 'example.com', serialize( ZSV_Google_Reviews::seen() ), 'no address of any customer' );
	}

	public function test_a_review_is_new_once_and_the_mark_is_the_newest_review(): void {
		$this->seed_five();
		self::page( '', self::six_reviews() );
		ZSV_Google_Reviews::run();
		$this->assertSame( self::NOW - 3600, (int) get_option( ZSV_Google_Reviews::OPT_HW ) );
		$r = ZSV_Google_Reviews::run();
		$this->assertSame( 0, $r['new'], 'the next hour: the same reviews are not new again' );
		$this->assertSame( 6, $r['read'] );
	}

	public function test_paging_stops_where_the_reviews_get_older_than_the_mark(): void {
		$this->seed_five();
		self::page( '', self::six_reviews() );
		ZSV_Google_Reviews::run();

		self::pages_reset();
		self::page( '', array( self::rv( 'h3', 'Alex Rivers', 5, 0.5 ) ), 'p2' );
		self::page( 'p2', array( self::rv( 'h2', 'Alex Vale', 5, 0.7 ), self::rv( 'g1', 'Jordan Linden', 5, 4 ), self::rv( 'old', 'Alex Lake', 5, 24 * 3 ) ), 'p3' );
		self::page( 'p3', array( self::rv( 'never', 'Blake Field', 5, 24 * 4 ) ) );
		$r = ZSV_Google_Reviews::run();
		$this->assertSame( array( '', 'p2' ), self::asked(), 'page 2 reached reviews older than the mark less a day: page 3 is never asked for' );
		$this->assertSame( 2, $r['new'] );
		$this->assertSame( '', $r['why'] );
		$this->assertNull( self::status_of( 'old' ) );
		$this->assertSame( self::NOW - 1800, (int) get_option( ZSV_Google_Reviews::OPT_HW ) );
	}

	public function test_a_run_reads_at_most_its_pages_and_leaves_the_mark_for_the_rest(): void {
		$this->seed_five();
		$tokens = array( '', 'p2', 'p3', 'p4', 'p5', 'p6' );
		foreach ( $tokens as $i => $t ) {
			self::page( $t, array( self::rv( 'n' . $i, 'Blake Wells', 5, 1 + $i ) ), $tokens[ $i + 1 ] ?? '' );
		}
		$r = ZSV_Google_Reviews::run();
		$this->assertSame( array( '', 'p2', 'p3', 'p4' ), self::asked() );
		$this->assertSame( ZSV_Google_Reviews::PAGES, count( self::asked() ) );
		$this->assertSame( 'more_next_run', $r['why'] );
		$this->assertSame( 4, $r['new'] );
		$this->assertSame( 0, (int) get_option( ZSV_Google_Reviews::OPT_HW, 0 ), 'the mark stays, so the next hour reads on from the top' );
	}

	public function test_the_look_back_and_the_candidate_window_are_settings(): void {
		$this->assertSame( 60, ZSV_Settings::greviews_lookback_days() );
		$this->assertSame( 120, ZSV_Settings::greviews_candidate_days() );

		$this->seed( $this->cust( 1, 'Jordan', 'Linden', 20 ), $this->cust( 2, 'Morgan', 'Moss', 100 ) );
		$GLOBALS['zdz_test_options']['zsv_greviews_lookback_days']  = 10;
		$GLOBALS['zdz_test_options']['zsv_greviews_candidate_days'] = 30;
		self::page( '', array(
			self::rv( 'a1', 'Jordan Linden', 5, 24 * 2 ),
			self::rv( 'a2', 'Morgan Moss', 5, 24 * 3 ),      // their follow-up was opened 97 days before the review
			self::rv( 'a3', 'Jordan Linden', 5, 24 * 12 ),   // older than a ten-day look-back
		) );
		$r = ZSV_Google_Reviews::run();
		$this->assertSame( 2, $r['new'] );
		$this->assertSame( 'would', self::status_of( 'a1' ) );
		$this->assertSame( 'none', self::status_of( 'a2' ) );
		$this->assertNull( self::status_of( 'a3' ) );
		$this->assertSame( 10, ZSV_Google_Reviews::list_payload( self::ADMIN )['lookback'] );

		$GLOBALS['zdz_test_options']['zsv_greviews_lookback_days']  = 0;
		$GLOBALS['zdz_test_options']['zsv_greviews_candidate_days'] = 99999;
		$this->assertSame( 1, ZSV_Settings::greviews_lookback_days(), 'kept inside 1 to 365 days' );
		$this->assertSame( 730, ZSV_Settings::greviews_candidate_days(), 'and 1 to 730' );
		$GLOBALS['zdz_test_filters']['zsv_greviews_lookback_days'] = static function () { return 45; };
		$this->assertSame( 45, ZSV_Settings::greviews_lookback_days(), 'a filter has the last word' );
	}

	public function test_an_old_review_edited_lately_is_not_listed(): void {
		$this->seed( $this->cust( 1, 'Jordan', 'Linden', 100 ) );
		$old           = self::rv( 'e1', 'Jordan Linden', 5, 24 * 70 );
		$old['updateTime'] = gmdate( 'Y-m-d\TH:i:s\Z', self::NOW - 3600 );   // written 70 days ago, edited an hour ago
		self::page( '', array( $old ) );
		$r = ZSV_Google_Reviews::run();
		$this->assertSame( 1, $r['read'] );
		$this->assertSame( 0, $r['new'], 'the look-back goes by when the review was written' );
		$this->assertNull( self::status_of( 'e1' ) );
		$this->assertSame( self::NOW - 3600, (int) get_option( ZSV_Google_Reviews::OPT_HW ), 'the mark still moves past it' );
	}

	public function test_a_customer_surveyed_after_the_review_is_not_its_writer(): void {
		$this->seed( $this->cust( 1, 'Jordan', 'Linden', 2 ) );
		self::page( '', array( self::rv( 'b1', 'Jordan Linden', 5, 24 * 5 ) ) );   // written three days before their follow-up was opened
		ZSV_Google_Reviews::run();
		$this->assertSame( 'none', self::status_of( 'b1' ) );
	}

	public function test_the_kept_list_holds_the_newest_reviews_only(): void {
		$seen = array();
		for ( $i = 0; $i < ZSV_Google_Reviews::MAX_KEPT; $i++ ) {
			$seen[ 'old' . $i ] = array( 'id' => 'old' . $i, 'reviewer' => 'Alex Rivers', 'stars' => 5, 'create' => self::NOW - 86400 - $i, 'snippet' => '', 'status' => 'none', 'ids' => array(), 'at' => self::NOW - 86400, 'lead' => 0, 'by' => '' );
		}
		$GLOBALS['zdz_test_options'][ ZSV_Google_Reviews::OPT_SEEN ] = $seen;
		self::page( '', array( self::rv( 'fresh', 'Alex Vale', 5, 1 ) ) );
		ZSV_Google_Reviews::run();
		$kept = ZSV_Google_Reviews::seen();
		$this->assertCount( ZSV_Google_Reviews::MAX_KEPT, $kept );
		$this->assertSame( 'fresh', array_key_first( $kept ), 'newest first' );
		$this->assertArrayNotHasKey( 'old' . ( ZSV_Google_Reviews::MAX_KEPT - 1 ), $kept, 'the oldest one made room' );
	}

	public function test_on_a_copy_of_the_site_nothing_is_asked_of_google(): void {
		$this->seed_five();
		self::page( '', self::six_reviews() );
		( new ReflectionProperty( 'ZDZ_Staging_Guard', 'status' ) )->setValue( null, array( 'active' => true, 'reason' => 'forced_on' ) );
		$r = ZSV_Google_Reviews::run();
		$this->assertFalse( $r['ran'] );
		$this->assertSame( 'staging', $r['why'] );
		$this->assertSame( 0, $GLOBALS['zsv210_gr']['calls'] );
		$this->assertSame( array(), ZSV_Google_Reviews::seen() );
	}

	/* ── C. automatic ────────────────────────────────────────────────────────────── */

	public function test_automatic_is_off_until_an_administrator_turns_it_on(): void {
		$this->assertFalse( ZSV_Google_Reviews::automatic() );
		ZSV_Google_Reviews::set_mode( true, self::ADMIN );
		$this->assertTrue( ZSV_Google_Reviews::automatic() );
		$this->assertStringContainsString( 'turned ON by user #' . self::ADMIN, $this->log_text() );
		$this->assertStringNotContainsString( 'Sam Example', $this->log_text(), 'the log has the person\'s number, not their name' );
		ZSV_Google_Reviews::set_mode( false, self::ADMIN );
		$this->assertFalse( ZSV_Google_Reviews::automatic() );
	}

	public function test_automatic_records_a_sure_match_and_closes_the_lead_where_the_site_lets_a_system_close(): void {
		$this->seed_five();
		$GLOBALS['zdz_test_options']['zsv_allow_system_close_won'] = true;
		ZSV_Google_Reviews::set_mode( true, self::ADMIN );
		$words = str_repeat( 'Careful and quick. ', 30 );
		self::page( '', array( self::rv( 'k1', 'Morgan Moss', 4, 0.2, $words ) ) );
		$r = ZSV_Google_Reviews::run();

		$this->assertSame( 1, $r['recorded'] );
		$this->assertSame( 'recorded', self::status_of( 'k1' ) );
		$this->assertSame( 2, ZSV_Google_Reviews::seen()['k1']['lead'] );
		$this->assertSame( 'auto', ZSV_Google_Reviews::seen()['k1']['by'] );
		$row = $this->db->rows[2];
		$this->assertSame( 1, $row['review_left'] );
		$this->assertSame( 'google', $row['review_source'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::NOW - 720 ), $row['review_date'], 'the review\'s own date' );
		$this->assertSame( 300, strlen( $row['review_snippet'] ), 'the first 300 characters of its words' );
		$this->assertSame( array( 2, 5 ), $this->reviewed_ids(), 'that customer and no other (5 had a review before)' );

		$this->assertTrue( ! empty( $this->crm->closed[9002] ), 'the CRM lead is closed as Won' );
		$this->assertCount( 1, $this->crm->notes[9002], 'with one note' );
		$note = $this->crm->notes[9002][0];
		$this->assertStringContainsString( 'Review recorded', $note );
		$this->assertStringContainsString( 'Review Source: Google', $note );
		$this->assertStringContainsString( 'Stars: 4', $note );
		$this->assertStringContainsString( 'Found By: the hourly Google check', $note );
		$this->assertSame( 'Won', $this->db->rows[2]['crm_status'] );
		$this->assertStringContainsString( 'sure 1 (automatic), recorded 1', $this->log_text() );
		$this->assertStringNotContainsString( 'Morgan', $this->log_text() );
	}

	public function test_automatic_never_closes_a_lead_the_safety_floor_keeps_for_a_person(): void {
		// The site has NOT opted in to system closes (the default): the review is recorded, the lead stays open.
		$this->seed( $this->cust( 2, 'Morgan', 'Moss', 30 ), $this->cust( 6, 'Robin', 'Maple', 12, array( 'operator_status' => 'needs_attention' ) ) );
		ZSV_Google_Reviews::set_mode( true, self::ADMIN );
		self::page( '', array( self::rv( 'k1', 'Morgan Moss', 5, 0.2 ) ) );
		$r = ZSV_Google_Reviews::run();
		$this->assertSame( 1, $r['recorded'] );
		$this->assertSame( 1, $this->db->rows[2]['review_left'], 'the review is on the customer' );
		$this->assertSame( array(), $this->crm->closed, 'but no system close: the site did not opt in' );
		$this->assertSame( 'Open', $this->db->rows[2]['crm_status'] );
		$this->assertCount( 1, $this->crm->notes[9002] );
		$this->assertStringContainsString( 'the lead is left open for a person', $this->crm->notes[9002][0] );
		$this->assertStringContainsString( 'Stars: 5', $this->crm->notes[9002][0] );

		// Opted in, but this customer's call outcome still needs a person: recorded, never closed.
		$GLOBALS['zdz_test_options']['zsv_allow_system_close_won'] = true;
		self::pages_reset();
		self::page( '', array( self::rv( 'k2', 'Robin Maple', 5, 0.1 ) ) );
		$r = ZSV_Google_Reviews::run();
		$this->assertSame( 1, $r['recorded'] );
		$this->assertSame( 1, $this->db->rows[6]['review_left'] );
		$this->assertSame( array(), $this->crm->closed, 'a customer with an open issue is never closed behind anyone\'s back' );
		$this->assertSame( 'Open', $this->db->rows[6]['crm_status'] );
		$this->assertStringContainsString( 'the lead is left open for a person', $this->crm->notes[9006][0] );
	}

	public function test_turning_automatic_on_does_not_record_what_was_listed_before(): void {
		$this->seed_five();
		$GLOBALS['zdz_test_options']['zsv_allow_system_close_won'] = true;
		self::page( '', self::six_reviews() );
		ZSV_Google_Reviews::run();
		$this->assertSame( 'would', self::status_of( 'g1' ) );

		ZSV_Google_Reviews::set_mode( true, self::ADMIN );
		$r = ZSV_Google_Reviews::run();
		$this->assertSame( 0, $r['recorded'] );
		$this->assertSame( 'would', self::status_of( 'g1' ), 'it stays for a person' );
		$this->assertSame( array( 5 ), $this->reviewed_ids() );
		$this->assertSame( 0, $this->crm->calls );
	}

	public function test_automatic_never_records_a_possible_match(): void {
		$this->seed_five();
		$GLOBALS['zdz_test_options']['zsv_allow_system_close_won'] = true;
		ZSV_Google_Reviews::set_mode( true, self::ADMIN );
		self::page( '', array(
			self::rv( 'p1', 'Morgan M', 5, 1 ),     // two customers
			self::rv( 'p2', 'Jordan', 5, 2 ),       // a first name alone
			self::rv( 'p3', 'Sam Linden', 5, 3 ),   // a surname alone
			self::rv( 'p4', 'A Google user', 5, 4, '', true ),
		) );
		$r = ZSV_Google_Reviews::run();
		$this->assertSame( 0, $r['recorded'] );
		$this->assertSame( 3, $r['possible'] );
		$this->assertSame( array( 5 ), $this->reviewed_ids(), 'nobody new was written' );
		$this->assertSame( 0, $this->crm->calls );
	}

	public function test_a_customer_with_no_crm_lead_is_recorded_and_the_crm_is_left_alone(): void {
		$this->seed( $this->cust( 1, 'Jordan', 'Linden', 20, array( 'crm_lead_id' => '0' ) ) );
		ZSV_Google_Reviews::set_mode( true, self::ADMIN );
		self::page( '', array( self::rv( 'k1', 'Jordan Linden', 5, 1 ) ) );
		$this->assertSame( 1, ZSV_Google_Reviews::run()['recorded'] );
		$this->assertSame( 1, $this->db->rows[1]['review_left'] );
		$this->assertSame( 0, $this->crm->calls );
	}

	/* ── D. Confirm, "Not them", the list, Google not ready ──────────────────────── */

	private function listed_five(): void {
		$this->seed_five();
		self::page( '', self::six_reviews() );
		ZSV_Google_Reviews::run();
	}

	public function test_confirm_takes_only_a_customer_the_site_offered_for_that_review(): void {
		$this->listed_five();
		$res = ZSV_Google_Reviews::confirm( 'g2', 1, 'Sam Example' );
		$this->assertFalse( $res[0] );
		$this->assertSame( 'That customer was not offered for this review.', $res[1] );
		$res = ZSV_Google_Reviews::confirm( 'g3', 1, 'Sam Example' );
		$this->assertFalse( $res[0], 'a review nobody was offered for takes nobody' );
		$res = ZSV_Google_Reviews::confirm( 'nope', 3, 'Sam Example' );
		$this->assertSame( 'That review is no longer in the list. Reload.', $res[1] );
		$res = ZSV_Google_Reviews::confirm( 'g2', 0, 'Sam Example' );
		$this->assertFalse( $res[0] );
		$this->assertSame( array( 5 ), $this->reviewed_ids(), 'nothing was written' );
		$this->assertSame( 0, $this->crm->calls );
		$this->assertFalse( $this->lock_held() );
	}

	public function test_a_person_confirms_one_of_the_customers_offered(): void {
		$this->listed_five();
		$res = ZSV_Google_Reviews::confirm( 'g2', 3, 'Sam Example' );
		$this->assertSame( array( true, '' ), $res );
		$this->assertSame( 1, $this->db->rows[3]['review_left'] );
		$this->assertSame( 'google', $this->db->rows[3]['review_source'] );
		$this->assertSame( 0, $this->db->rows[2]['review_left'], 'the other customer offered is untouched' );
		$this->assertSame( 'confirmed', self::status_of( 'g2' ) );
		$this->assertSame( 3, ZSV_Google_Reviews::seen()['g2']['lead'] );
		$this->assertSame( 'confirmed by Sam Example', ZSV_Google_Reviews::seen()['g2']['by'] );
		// A person looked: the lead is closed as Won like any hand-made close, opted in or not.
		$this->assertTrue( ! empty( $this->crm->closed[9003] ) );
		$this->assertCount( 1, $this->crm->notes[9003] );
		$this->assertStringContainsString( 'Found By: confirmed by Sam Example', $this->crm->notes[9003][0] );
		$this->assertStringNotContainsString( 'Sam Example', $this->log_text(), 'the log does not name the person' );

		$again = ZSV_Google_Reviews::confirm( 'g2', 3, 'Sam Example' );
		$this->assertSame( array( false, 'This review is already recorded.' ), $again );
		$other = ZSV_Google_Reviews::confirm( 'g2', 2, 'Sam Example' );
		$this->assertFalse( $other[0], 'nor on the other customer' );
		$this->assertSame( 0, $this->db->rows[2]['review_left'] );
		$this->assertCount( 1, $this->crm->notes[9003] );
	}

	public function test_not_them_writes_nothing_to_any_customer(): void {
		$this->listed_five();
		$this->assertSame( array( true, '' ), ZSV_Google_Reviews::dismiss( 'g1' ) );
		$this->assertSame( 'dismissed', self::status_of( 'g1' ) );
		$this->assertSame( array( 5 ), $this->reviewed_ids() );
		$this->assertSame( 0, $this->crm->calls );
		$this->assertFalse( ZSV_Google_Reviews::dismiss( 'nope' )[0] );

		ZSV_Google_Reviews::confirm( 'g2', 3, 'Sam Example' );
		$this->assertTrue( ZSV_Google_Reviews::dismiss( 'g2' )[0] );
		$this->assertSame( 'confirmed', self::status_of( 'g2' ), 'a recorded review is not un-recorded by "Not them"' );
	}

	public function test_the_panels_list(): void {
		$this->listed_five();
		ZSV_Google_Reviews::confirm( 'g2', 3, 'Sam Example' );
		$d = ZSV_Google_Reviews::list_payload( self::ADMIN );
		$this->assertTrue( $d['ready'] );
		$this->assertFalse( $d['automatic'] );
		$this->assertTrue( $d['can_admin'] );
		$this->assertSame( 60, $d['lookback'] );
		$this->assertSame( array( 'g4', 'g3', 'g2', 'g1', 'g0' ), array_column( $d['items'], 'id' ), 'newest first' );
		$this->assertTrue( $d['last']['ran'] );
		$this->assertSame( '', $d['last']['why'] );
		$this->assertSame( gmdate( 'M j, g:i A', self::NOW ), $d['last']['at'] );

		$by_id = array_column( $d['items'], null, 'id' );
		$this->assertSame( array( 'id' => 3, 'name' => 'Morgan Mill', 'reviewed' => true, 'added' => gmdate( 'Y-m-d', self::NOW - 25 * 86400 ) ), $by_id['g2']['lead'] );
		$this->assertSame( 'confirmed', $by_id['g2']['status'] );
		$this->assertSame( array( array( 'id' => 1, 'name' => 'Jordan Linden', 'reviewed' => false, 'added' => gmdate( 'Y-m-d', self::NOW - 20 * 86400 ) ) ), $by_id['g1']['cands'], 'a customer is a name and the day their follow-up was opened' );
		$this->assertSame( 'would', $by_id['g1']['status'] );
		$this->assertSame( 5, $by_id['g1']['stars'] );
		$this->assertSame( gmdate( 'M j', self::NOW - 4 * 3600 ), $by_id['g1']['date'] );
		$this->assertNull( $by_id['g3']['lead'] );
		$this->assertSame( array(), $by_id['g3']['cands'] );
		$this->assertStringNotContainsString( 'example.com', (string) json_encode( $d ), 'a name is all the panel is given about a customer' );

		$this->assertFalse( ZSV_Google_Reviews::list_payload( self::OTHER )['can_admin'] );
		$this->assertFalse( ZSV_Google_Reviews::list_payload( 0 )['can_admin'] );
	}

	public function test_the_panel_is_handed_the_newest_fifteen(): void {
		$reviews = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$reviews[] = self::rv( 'r' . $i, 'Alex Rivers', 5, 1 + $i );
		}
		self::page( '', $reviews );
		ZSV_Google_Reviews::run();
		$d = ZSV_Google_Reviews::list_payload( self::ADMIN );
		$this->assertCount( ZSV_Google_Reviews::LISTED, $d['items'] );
		$this->assertSame( 'r0', $d['items'][0]['id'] );
		$this->assertCount( 20, ZSV_Google_Reviews::seen(), 'the rest are still kept' );
	}

	public function test_google_has_not_approved_the_project_yet(): void {
		$this->listed_five();
		$mark = (int) get_option( ZSV_Google_Reviews::OPT_HW );
		self::pages_reset();
		$GLOBALS['zsv210_gr']['pages'][''] = array( 429, array( 'error' => array( 'code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'Quota exceeded' ) ) );
		$r = ZSV_Google_Reviews::run();
		$this->assertFalse( $r['ran'] );
		$this->assertSame( 'not_approved', $r['why'] );
		$this->assertSame( $mark, (int) get_option( ZSV_Google_Reviews::OPT_HW ), 'the mark is unchanged' );
		$this->assertSame( 'not_approved', ZSV_Google_Reviews::list_payload( self::ADMIN )['last']['why'], 'and the panel can say why' );
		$this->assertCount( 5, ZSV_Google_Reviews::seen(), 'what was listed stays listed' );
		$this->assertFalse( ZSV_Budget::is_open() );
		$this->assertFalse( $this->lock_held() );
	}

	/* ── E. two writers ──────────────────────────────────────────────────────────── */

	public function test_a_confirm_during_a_run_is_told_to_wait_and_works_afterwards(): void {
		$this->seed( $this->cust( 1, 'Morgan', 'Moss', 20 ), $this->cust( 2, 'Morgan', 'Mill', 20 ) );
		self::page( '', array( self::rv( 'm1', 'Morgan M', 5, 2 ) ) );
		ZSV_Google_Reviews::run();
		$this->assertSame( 'possible', self::status_of( 'm1' ) );

		self::pages_reset();
		self::page( '', array( self::rv( 'm2', 'Alex Rivers', 5, 1 ), self::rv( 'm1', 'Morgan M', 5, 2 ) ) );
		$mid                            = null;
		$GLOBALS['zsv210_gr']['during'] = static function () use ( &$mid ) {
			$mid = ZSV_Google_Reviews::confirm( 'm1', 1, 'Sam Example' );
		};
		ZSV_Google_Reviews::run();
		$this->assertIsArray( $mid, 'the Confirm arrived while the page was on its way' );
		$this->assertFalse( $mid[0] );
		$this->assertStringContainsString( 'running right now', $mid[1] );
		$this->assertSame( array(), $this->reviewed_ids(), 'nothing was recorded' );
		$this->assertFalse( $this->lock_held(), 'the run let the lock go' );

		$this->assertSame( array( true, '' ), ZSV_Google_Reviews::confirm( 'm1', 1, 'Sam Example' ), 'afterwards the same Confirm works' );
		$this->assertSame( 'confirmed', self::status_of( 'm1' ) );

		// A later run that reads the same review again never resets it.
		self::pages_reset();
		self::page( '', array( self::rv( 'm3', 'Alex Vale', 5, 0.5 ), self::rv( 'm1', 'Morgan M', 5, 2 ) ) );
		ZSV_Google_Reviews::run();
		$this->assertSame( 'confirmed', self::status_of( 'm1' ) );
		$this->assertSame( 1, ZSV_Google_Reviews::seen()['m1']['lead'] );
		$second = ZSV_Google_Reviews::confirm( 'm1', 2, 'Sam Example' );
		$this->assertFalse( $second[0], 'and a second Confirm, on the other customer, is refused' );
		$this->assertSame( 0, $this->db->rows[2]['review_left'] );
	}

	public function test_a_run_only_adds_to_the_list_and_never_rewrites_an_entry(): void {
		// Whatever reached the stored list while the run was reading (it should be nobody, under the
		// lock) is kept as it is: the run adds what it found and touches nothing else.
		$this->seed( $this->cust( 1, 'Jordan', 'Linden', 20 ) );
		self::page( '', array( self::rv( 'w1', 'Jordan Linden', 5, 1 ) ) );
		$GLOBALS['zsv210_gr']['during'] = static function () {
			$GLOBALS['zdz_test_options'][ ZSV_Google_Reviews::OPT_SEEN ]['w1'] = array(
				'id' => 'w1', 'reviewer' => 'Jordan Linden', 'stars' => 5, 'create' => self::NOW - 3600, 'snippet' => '', 'status' => 'confirmed', 'ids' => array( 1 ), 'at' => self::NOW, 'lead' => 1, 'by' => 'confirmed by Sam Example',
			);
		};
		ZSV_Google_Reviews::run();
		$this->assertSame( 'confirmed', self::status_of( 'w1' ) );
		$this->assertSame( 'confirmed by Sam Example', ZSV_Google_Reviews::seen()['w1']['by'] );
	}

	public function test_a_run_while_the_list_is_being_written_does_nothing(): void {
		$this->seed_five();
		self::page( '', self::six_reviews() );
		$this->db->lock_rows[ 'zdz_guard_lock_' . ZSV_Google_Reviews::LOCK ] = time() . ':another-request';
		$r = ZSV_Google_Reviews::run();
		$this->assertFalse( $r['ran'] );
		$this->assertSame( 'busy', $r['why'] );
		$this->assertSame( array(), self::asked(), 'Google was not asked' );
		$this->assertSame( array(), ZSV_Google_Reviews::seen() );
		$this->assertFalse( get_option( ZSV_Google_Reviews::OPT_LAST, false ), 'and the last-run line is the other request\'s to write' );
		$this->assertSame( array( false, 'The Google check is running right now. Try again in a minute.' ), ZSV_Google_Reviews::dismiss( 'g1' ) );
		$this->assertTrue( $this->lock_held(), 'the other request\'s lock is not taken from it' );
	}

	public function test_a_lock_left_by_a_request_that_died_is_taken_after_three_minutes(): void {
		$this->seed_five();
		self::page( '', self::six_reviews() );
		$this->db->lock_rows[ 'zdz_guard_lock_' . ZSV_Google_Reviews::LOCK ] = ( time() - ZSV_Google_Reviews::LOCK_STALE - 5 ) . ':gone';
		$r = ZSV_Google_Reviews::run();
		$this->assertTrue( $r['ran'] );
		$this->assertSame( 5, $r['new'] );
		$this->assertFalse( $this->lock_held() );
	}

	public function test_a_customer_who_already_has_a_review_is_not_recorded_again(): void {
		$this->seed( $this->cust( 1, 'Jordan', 'Linden', 20 ) );
		$this->assertTrue( ZSV_Google_Reviews::record( 1, array( 'create' => self::NOW - 3600, 'stars' => 5 ), 'The first one.', 'confirmed by Sam Example', true ) );
		$notes = count( $this->crm->notes[9001] );
		$calls = $this->crm->calls;
		$this->assertFalse( ZSV_Google_Reviews::record( 1, array( 'create' => self::NOW, 'stars' => 1 ), 'A second one.', 'confirmed by Sam Example', true ) );
		$this->assertSame( 'The first one.', $this->db->rows[1]['review_snippet'], 'the first review stays as it was' );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::NOW - 3600 ), $this->db->rows[1]['review_date'] );
		$this->assertCount( $notes, $this->crm->notes[9001], 'no new CRM note' );
		$this->assertSame( $calls, $this->crm->calls, 'the CRM is not asked anything' );
		$this->assertFalse( ZSV_Google_Reviews::record( 404, array( 'create' => self::NOW, 'stars' => 5 ), 'Nobody.', 'confirmed by Sam Example', true ), 'a customer who is not there' );
	}

	public function test_the_statement_that_records_only_writes_a_customer_with_no_review(): void {
		// Another writer recorded a review between this request's read and its write: the write must find nothing to do.
		$this->seed( $this->cust( 1, 'Jordan', 'Linden', 20 ) );
		$db      = $this->db;
		$real_db = $GLOBALS['wpdb'];
		$racing  = new class( $db ) extends ZSV210_Reviews_DB {
			private $inner;
			public function __construct( ZSV210_Reviews_DB $inner ) { $this->inner = $inner; }
			public function prepare( $q, ...$a ) { return $this->inner->prepare( $q, ...$a ); }
			public function get_var( $p ) { return $this->inner->get_var( $p ); }
			public function get_results( $p, $out = null ) { return $this->inner->get_results( $p, $out ); }
			public function update( $t, $d, $w, $f = null, $wf = null ) { return $this->inner->update( $t, $d, $w, $f, $wf ); }
			public function get_row( $p, $out = null ) {
				$row = $this->inner->get_row( $p, $out );                    // this request reads "no review yet"…
				$this->inner->rows[1]['review_left']    = 1;                 // …and another writer records one
				$this->inner->rows[1]['review_snippet'] = 'The other writer.';
				return $row;
			}
			public function query( $p ) { return $this->inner->query( $p ); }
		};
		$GLOBALS['wpdb'] = $racing;
		try {
			$ok = ZSV_Google_Reviews::record( 1, array( 'create' => self::NOW, 'stars' => 5 ), 'This request.', 'confirmed by Sam Example', true );
		} finally {
			$GLOBALS['wpdb'] = $real_db;
		}
		$this->assertFalse( $ok );
		$this->assertSame( 'The other writer.', $this->db->rows[1]['review_snippet'] );
		$this->assertSame( 0, $this->crm->calls, 'and the CRM lead is not closed a second time' );
	}

	public function test_when_the_customer_cannot_be_written_the_crm_is_not_touched(): void {
		$this->seed( $this->cust( 8, 'Robin', 'Maple', 10 ) );
		$this->db->fail_record = true;
		$this->assertFalse( ZSV_Google_Reviews::record( 8, array( 'create' => self::NOW, 'stars' => 5 ), 'Fine.', 'confirmed by Sam Example', true ) );
		$this->assertSame( array(), $this->crm->closed );
		$this->assertSame( 0, $this->crm->calls );

		// The same through Confirm: the review stays listed for another try, and says so.
		self::page( '', array( self::rv( 'f1', 'Robin', 5, 1 ) ) );
		ZSV_Google_Reviews::run();
		$this->assertSame( 'possible', self::status_of( 'f1' ) );
		$res = ZSV_Google_Reviews::confirm( 'f1', 8, 'Sam Example' );
		$this->assertFalse( $res[0] );
		$this->assertSame( 'possible', self::status_of( 'f1' ) );
		$this->assertSame( 0, $this->crm->calls );

		// And through the automatic check: it stays "would record", for a person.
		ZSV_Google_Reviews::set_mode( true, self::ADMIN );
		self::pages_reset();
		self::page( '', array( self::rv( 'f2', 'Robin Maple', 5, 0.5 ) ) );
		$r = ZSV_Google_Reviews::run();
		$this->assertSame( 1, $r['sure'] );
		$this->assertSame( 0, $r['recorded'] );
		$this->assertSame( 'would', self::status_of( 'f2' ) );
		$this->assertSame( 0, $this->crm->calls );
	}

	public function test_a_page_that_failed_is_read_again_next_hour(): void {
		$this->seed( $this->cust( 7, 'Casey', 'Bramble', 10 ) );
		self::page( '', array( self::rv( 'n2', 'Alex Rivers', 5, 1 ) ), 'p2' );
		$GLOBALS['zsv210_gr']['pages']['p2'] = array( 0, '' );   // no answer
		$r = ZSV_Google_Reviews::run();
		$this->assertTrue( $r['ran'] );
		$this->assertSame( 'unreachable', $r['why'] );
		$this->assertSame( 0, (int) get_option( ZSV_Google_Reviews::OPT_HW, 0 ), 'the mark does not move past what was not read' );
		$this->assertSame( 'none', self::status_of( 'n2' ), 'what page 1 held is listed all the same' );

		self::page( 'p2', array( self::rv( 'q1', 'Casey Bramble', 5, 72 ) ) );
		$GLOBALS['zsv210_gr']['asked'] = array();
		ZSV_Google_Reviews::run();
		$this->assertSame( array( '', 'p2' ), self::asked() );
		$this->assertSame( 'would', self::status_of( 'q1' ), 'the review from three days ago is found' );
		$this->assertSame( self::NOW - 3600, (int) get_option( ZSV_Google_Reviews::OPT_HW ) );
	}

	/* ── F. who may use the panel ────────────────────────────────────────────────── */

	public function test_who_may_use_the_panel_and_who_may_change_how_it_works(): void {
		$this->assertSame( '', ZSV_Google_Reviews::refusal( self::ADMIN ) );
		$this->assertSame( '', ZSV_Google_Reviews::refusal( self::ADMIN, true ) );
		$this->assertSame( 'Survey data access restricted.', ZSV_Google_Reviews::refusal( 0 ), 'nobody signed in' );
		$this->assertSame( 'Unauthorized', ZSV_Google_Reviews::refusal( 0, true ) );
		$this->assertSame( 'Unauthorized', ZSV_Google_Reviews::refusal( self::OTHER, true ), 'the mode and "check now" are an administrator\'s' );
		if ( ! class_exists( 'ZDZ_Data_Permissions' ) ) {
			$this->assertSame( 'Survey data access restricted.', ZSV_Google_Reviews::refusal( self::OTHER ), 'no permission to see other people\'s customers' );
		} else {
			// Somebody the site lets see other people's customers may read and confirm, never switch the mode.
			$GLOBALS['zdz_test_user_roles'][9109] = array( 'zdz_operator' );
			$this->assertSame( '', ZSV_Google_Reviews::refusal( 9109 ) );
			$this->assertSame( 'Unauthorized', ZSV_Google_Reviews::refusal( 9109, true ) );
		}
		// The capability decides, not the role's name.
		$GLOBALS['zdz_test_user_caps'][ self::OTHER ] = array( 'manage_options' );
		$this->assertSame( '', ZSV_Google_Reviews::refusal( self::OTHER, true ) );
	}

	public function test_nothing_of_surveys_is_open_to_the_privacy_view_account(): void {
		require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-privacy-view.php';
		$actions = array();
		foreach ( (array) glob( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/surveys/includes/*.php' ) as $file ) {
			if ( preg_match_all( "/'wp_ajax_(zsv_[a-z_]+)'/", (string) file_get_contents( $file ), $m ) ) {
				$actions = array_merge( $actions, $m[1] );
			}
		}
		$actions = array_values( array_unique( $actions ) );
		foreach ( array( 'zsv_greviews_list', 'zsv_greviews_confirm', 'zsv_greviews_dismiss', 'zsv_greviews_check_now', 'zsv_greviews_mode', 'zsv_send_invite', 'zsv_check_reviews', 'zsv_recent' ) as $a ) {
			$this->assertContains( $a, $actions, 'the app answers ' . $a );
		}
		foreach ( $actions as $a ) {
			$this->assertFalse( ZDZ_Privacy_View::ajax_allowed( $a ), $a . ' is refused for the account in Privacy View' );
		}
		$this->assertNotContains( 'surveys', ZDZ_Privacy_View::ALLOWED_APPS, 'the widget is not listed for that account' );
		$this->assertFalse( ZDZ_Privacy_View::screen_allowed( 'options-general.php', 'zsv-settings', array( 'page' ) ), 'nor is the settings screen with the "send again" list' );
	}

	/**
	 * In a process of its own, so this file's reply helpers are the ones in force whatever loaded first.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_every_request_checks_the_nonce_and_then_the_person(): void {
		if ( ! defined( 'ZSV210_REPLY_STUBS' ) ) {
			$this->markTestSkipped( 'the reply helpers are another test\'s' );
		}
		$this->listed_five();
		$admin_only = array( 'ajax_check_now' => array(), 'ajax_mode' => array( 'automatic' => '1' ) );
		$any        = array( 'ajax_list' => array(), 'ajax_confirm' => array( 'review_id' => 'g2', 'lead_id' => '3' ), 'ajax_dismiss' => array( 'review_id' => 'g1' ) );

		// A request without the nonce gets nowhere, whoever sends it.
		foreach ( array_keys( $admin_only + $any ) as $h ) {
			$_POST = array( 'nonce' => 'wrong' );
			try {
				call_user_func( array( 'ZSV_Google_Reviews', $h ) );
				$this->fail( $h . ' ran without a nonce' );
			} catch ( ZSV210_Reply $r ) {
				$this->assertSame( 'bad nonce', $r->data, $h );
			}
		}
		$this->assertSame( array( ZSV_NONCE, 'nonce' ), end( $GLOBALS['zsv210_referer'] ) );

		// Somebody who is not an administrator and may not see other people's customers.
		$GLOBALS['zdz_test_current_user'] = self::OTHER;
		foreach ( $admin_only as $h => $post ) {
			$this->assertSame( array( false, 'Unauthorized' ), $this->ajax( $h, $post ), $h );
		}
		if ( ! class_exists( 'ZDZ_Data_Permissions' ) ) {
			foreach ( $any as $h => $post ) {
				$this->assertSame( array( false, 'Survey data access restricted.' ), $this->ajax( $h, $post ), $h );
			}
		}
		$this->assertFalse( ZSV_Google_Reviews::automatic(), 'the mode did not change' );
		$this->assertSame( array( 5 ), $this->reviewed_ids(), 'and nothing was recorded' );
		$this->assertSame( 'would', self::status_of( 'g1' ) );

		// Nobody signed in.
		$GLOBALS['zdz_test_current_user'] = 0;
		foreach ( $admin_only + $any as $h => $post ) {
			$this->assertFalse( $this->ajax( $h, $post )[0], $h );
		}

		// An administrator.
		$GLOBALS['zdz_test_current_user'] = self::ADMIN;
		list( $ok, $d ) = $this->ajax( 'ajax_list' );
		$this->assertTrue( $ok );
		$this->assertCount( 5, $d['items'] );
		list( $ok, $d ) = $this->ajax( 'ajax_confirm', array( 'review_id' => 'g2', 'lead_id' => '3' ) );
		$this->assertTrue( $ok );
		$this->assertSame( 'confirmed by Sam Example', ZSV_Google_Reviews::seen()['g2']['by'] );
		$this->assertSame( array( false, 'That customer was not offered for this review.' ), $this->ajax( 'ajax_confirm', array( 'review_id' => 'g1', 'lead_id' => '2' ) ) );
		list( $ok ) = $this->ajax( 'ajax_dismiss', array( 'review_id' => 'g1' ) );
		$this->assertTrue( $ok );
		$this->assertSame( 'dismissed', self::status_of( 'g1' ) );
		list( $ok, $d ) = $this->ajax( 'ajax_mode', array( 'automatic' => '1' ) );
		$this->assertTrue( $ok );
		$this->assertTrue( $d['automatic'] );
		list( $ok, $d ) = $this->ajax( 'ajax_mode', array( 'automatic' => 'yes' ) );
		$this->assertFalse( $d['automatic'], 'anything but the one value turns it off' );
		list( $ok, $d ) = $this->ajax( 'ajax_check_now' );
		$this->assertTrue( $ok );
		$this->assertTrue( $d['run']['ran'] );
		$this->assertFalse( $this->lock_held(), 'no answer is sent while the lock is held' );
	}
}
