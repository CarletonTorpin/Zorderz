<?php
/**
 * ZJOB_Repair_Log — "Log a repair": a field tech's repair becomes their own Project.
 *
 * THE SWITCH. Off until the business turns it on (option zjob_log_repair = 1), then for everyone the tier ladder lets
 * work in the app (viewer and up); the filter `zjob_log_repair_allowed` may narrow it per person. Never the shared
 * device or Privacy View. At most PER_DAY a day per person.
 *
 * WHAT IT MAKES. A Project created by the person (so it is theirs: the creator is a `related` tie), carrying their own
 * initials as its salesperson code, a `repair` reference (the Projects list shows it under the business's "service
 * and repairs" kind when one is named: ZJOB_Project_Types::repair_type), the street and the GPS fix of the photos, and
 * a first line saying what was done (`zjob_repair_logged`, for the Project's conversation).
 * What was repaired and what was done are the business's own lists (options zjob_repair_products, zjob_repair_work:
 * slug => label); Core ships none, so the person types it.
 *
 * JOINING THE HOME. Automatic only when BOTH hold, checked on the server from the person's own photos:
 *   1. the confirmed street's key (addr_key) is a known house's key, and
 *   2. a photo with a KNOWN accuracy no vaguer than the geocoder's usable accuracy is at that house (ZDZ_Geocoder::
 *      same_property 'match'). A photo with no accuracy never joins by itself.
 * And that house belongs to exactly one home. Otherwise the repair stays on its own card and says why (decide_join),
 * for an administrator to join by hand. The houses come from the `zjob_repair_houses` filter (the service that knows
 * where the business has worked); with none, every repair stays on its own — a review item, never a guess.
 *
 * Joining never widens what the person sees: their ties are on the repair Project only.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZJOB_Repair_Log {

	const OPTION     = 'zjob_log_repair';
	const OPT_READY  = 'zjob_repairs_ready';
	const PER_DAY    = 20;
	const MAX_NAME   = 120;
	const MAX_ADDR   = 200;
	const MAX_WHAT   = 600;
	const MAX_NOTE   = 200;
	const MAX_PHOTOS = 6;
	const VISIT_GAP  = 3600;  // photos more than an hour apart are two visits
	const VISIT_M    = 200.0; // ... or more than 200 m apart

	/** English street words, read the same way whichever is written (a key, never shown). */
	const SUFFIX = array(
		'street' => 'st', 'avenue' => 'ave', 'av' => 'ave', 'road' => 'rd', 'drive' => 'dr', 'lane' => 'ln', 'court' => 'ct',
		'place' => 'pl', 'boulevard' => 'blvd', 'circle' => 'cir', 'terrace' => 'ter', 'parkway' => 'pkwy', 'highway' => 'hwy',
		'way' => 'way', 'trail' => 'trl', 'north' => 'n', 'south' => 's', 'east' => 'e', 'west' => 'w',
	);

	public static function init(): void {
		add_action( 'wp_ajax_zjob_log_repair', array( __CLASS__, 'ajax' ) );
		add_filter( 'zdz_project_sp_code', array( __CLASS__, 'sp_code_for_project' ), 10, 2 );
		// No _nopriv variant.
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zjob_project_repairs';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta( "CREATE TABLE {$t} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id VARCHAR(40) NOT NULL DEFAULT '',
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			sp_code VARCHAR(8) NOT NULL DEFAULT '',
			street VARCHAR(200) NOT NULL DEFAULT '',
			addr_key VARCHAR(180) NOT NULL DEFAULT '',
			product VARCHAR(40) NOT NULL DEFAULT '',
			work VARCHAR(255) NOT NULL DEFAULT '',
			media_ids VARCHAR(255) NOT NULL DEFAULT '',
			fix_lat DECIMAL(10,7) NULL DEFAULT NULL,
			fix_lng DECIMAL(10,7) NULL DEFAULT NULL,
			fix_acc FLOAT NULL DEFAULT NULL,
			join_why VARCHAR(24) NOT NULL DEFAULT '',
			home_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY project_id (project_id),
			KEY addr_key (addr_key)
		) " . $wpdb->get_charset_collate() . ';' );
		update_option( self::OPT_READY, 1 );
	}

	public static function ready(): bool {
		return function_exists( 'get_option' ) && 1 === (int) get_option( self::OPT_READY, 0 );
	}

	/* ── Who ─────────────────────────────────────────────────────────────────────────────────────────────── */

	public static function on(): bool {
		return function_exists( 'get_option' ) && 1 === (int) get_option( self::OPTION, 0 );
	}

	/** May this person log a repair? The switch, never the shared device or Privacy View, viewer tier or above. */
	public static function can( int $uid ): bool {
		if ( $uid <= 0 || ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $uid ) )
			|| ( class_exists( 'ZDZ_Privacy_View' ) && ZDZ_Privacy_View::is_on( $uid ) ) ) {
			return false;
		}
		$ok = self::on();
		if ( $ok ) {
			$ok = class_exists( 'ZDZ_Capabilities' ) && in_array( (string) ZDZ_Capabilities::tier_of( $uid ), array( 'viewer', 'staff', 'admin' ), true );
		}
		return (bool) ( function_exists( 'apply_filters' ) ? apply_filters( 'zjob_log_repair_allowed', $ok, $uid ) : $ok ) && $ok;
	}

	/** The business's lists: slug => label (Core: none). */
	public static function products(): array {
		return self::list_option( 'zjob_repair_products' );
	}

	public static function work_kinds(): array {
		return self::list_option( 'zjob_repair_work' );
	}

	private static function list_option( string $name ): array {
		$raw = function_exists( 'get_option' ) ? get_option( $name, array() ) : array();
		$raw = function_exists( 'apply_filters' ) ? apply_filters( $name, $raw ) : $raw;
		$out = array();
		foreach ( (array) $raw as $k => $v ) {
			$k = strtolower( (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $k ) );
			$v = is_array( $v ) ? (string) ( $v['label'] ?? ( $v[0] ?? '' ) ) : (string) $v;
			if ( '' !== $k && '' !== trim( $v ) ) {
				$out[ $k ] = trim( $v );
			}
		}
		return $out;
	}

	/* ── Pure helpers ────────────────────────────────────────────────────────────────────────────────────── */

	/** One typed field: no control characters, one line, at most $max characters. PURE. */
	public static function line( string $v, int $max ): string {
		$v = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $v ) );
		$v = (string) preg_replace( '/\s{2,}/', ' ', $v );
		return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, $max ) : substr( $v, 0, $max );
	}

	/**
	 * A street's key: the first part of the address that starts with a house number, lower case, without a unit,
	 * street words read one way, ending at the street type. '' when there is no house number. PURE; a business in
	 * another jurisdiction may refine it (`zjob_addr_key`).
	 */
	public static function addr_key( string $address ): string {
		$key = '';
		foreach ( preg_split( '/[,\r\n]+/', $address ) as $piece ) {
			$street = strtolower( trim( (string) $piece ) );
			$street = (string) preg_replace( '/\s*#\s*[\w-]+\s*$/u', '', $street );
			$street = (string) preg_replace( '/\s+(?:apt|unit|ste|suite|no)\.?\s*#?\s*(?:[\w-]*\d[\w-]*|[a-z])\s*$/u', '', $street );
			$street = (string) preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $street );
			$words  = array_values( array_filter( (array) preg_split( '/\s+/', trim( $street ) ), 'strlen' ) );
			if ( count( $words ) < 2 || ! preg_match( '/^\d/u', $words[0] ) ) {
				continue;
			}
			$out  = array( $words[0] );
			$ends = array_merge( array_keys( self::SUFFIX ), array_values( self::SUFFIX ) );
			$dirs = array( 'n', 's', 'e', 'w', 'north', 'south', 'east', 'west' );
			$n    = count( $words );
			for ( $i = 1; $i < $n; $i++ ) {
				$w     = $words[ $i ];
				$out[] = self::SUFFIX[ $w ] ?? $w;
				if ( $i >= 2 && in_array( $w, $ends, true ) && ! in_array( $w, $dirs, true ) ) {
					if ( $i + 2 === $n && in_array( $words[ $i + 1 ], $dirs, true ) ) {
						$out[] = self::SUFFIX[ $words[ $i + 1 ] ] ?? $words[ $i + 1 ];
					}
					break; // the street type ends the street; what follows is the town
				}
			}
			$key = function_exists( 'mb_substr' ) ? mb_substr( implode( ' ', $out ), 0, 180 ) : substr( implode( ' ', $out ), 0, 180 );
			break;
		}
		return function_exists( 'apply_filters' ) ? (string) apply_filters( 'zjob_addr_key', $key, $address ) : $key;
	}

	/** Visits: photos (newest first) in runs no more than an hour and 200 m apart. PURE. */
	public static function visits( array $photos ): array {
		$out  = array();
		$cur  = null;
		$prev = null;
		foreach ( $photos as $p ) {
			$t   = strtotime( (string) $p['at'] . ' UTC' );
			$new = null === $prev
				|| abs( $prev['t'] - $t ) > self::VISIT_GAP
				|| ( null !== $prev['lat'] && null !== ( $p['lat'] ?? null )
					&& self::dist_m( (float) $prev['lat'], (float) $prev['lng'], (float) $p['lat'], (float) $p['lng'] ) > self::VISIT_M );
			if ( $new ) {
				if ( $cur ) {
					$out[] = $cur;
				}
				$cur = array( 'from' => (string) $p['at'], 'to' => (string) $p['at'], 'media_ids' => array() );
			}
			$cur['media_ids'][] = (int) $p['media_id'];
			$cur['from']        = (string) $p['at']; // newest first: the last one seen is the earliest
			$prev               = array( 't' => $t, 'lat' => $p['lat'] ?? null, 'lng' => $p['lng'] ?? null );
		}
		if ( $cur ) {
			$out[] = $cur;
		}
		return $out;
	}

	public static function dist_m( float $a_lat, float $a_lng, float $b_lat, float $b_lng ): float {
		if ( class_exists( 'ZDZ_Geocoder' ) && method_exists( 'ZDZ_Geocoder', 'distance_m' ) ) {
			return (float) ZDZ_Geocoder::distance_m( $a_lat, $a_lng, $b_lat, $b_lng );
		}
		$r  = 6371000.0;
		$p1 = deg2rad( $a_lat );
		$p2 = deg2rad( $b_lat );
		$dp = deg2rad( $b_lat - $a_lat );
		$dl = deg2rad( $b_lng - $a_lng );
		$h  = sin( $dp / 2 ) ** 2 + cos( $p1 ) * cos( $p2 ) * sin( $dl / 2 ) ** 2;
		return 2 * $r * asin( min( 1.0, sqrt( $h ) ) );
	}

	/** The geocoder's verdict for one photo point at one house point. */
	private static function verdict( array $p, array $hp ): string {
		if ( class_exists( 'ZDZ_Geocoder' ) && method_exists( 'ZDZ_Geocoder', 'same_property' ) ) {
			$v = ZDZ_Geocoder::same_property( (float) $p['lat'], (float) $p['lng'], (float) $hp[0], (float) $hp[1], null === $p['acc'] ? null : (float) $p['acc'] );
			return (string) $v['verdict'];
		}
		if ( null !== $p['acc'] && $p['acc'] > self::usable_acc() ) {
			return 'too_vague';
		}
		return self::dist_m( (float) $p['lat'], (float) $p['lng'], (float) $hp[0], (float) $hp[1] ) <= 120.0 ? 'match' : 'elsewhere';
	}

	public static function usable_acc(): float {
		return class_exists( 'ZDZ_Geocoder' ) && defined( 'ZDZ_Geocoder::USABLE_ACCURACY_M' ) ? (float) constant( 'ZDZ_Geocoder::USABLE_ACCURACY_M' ) : 75.0;
	}

	/**
	 * The joining rule, on the server's own reading of the photos. PURE over its inputs.
	 *
	 * @param array  $pts     the person's chosen photos' points: [ { lat, lng, acc|null } ]
	 * @param string $address the street the person confirmed
	 * @param array  $houses  addr_key => { points: [[lat, lng], …], home_ids: int[] }
	 * @return array{home_id:int,why:string,fix:?array} why: '' (joined) | no_fix | no_accuracy | vague | no_street |
	 *         no_house | far | no_home | two_homes
	 */
	public static function decide_join( array $pts, string $address, array $houses ): array {
		$no    = static function ( string $why ): array {
			return array( 'home_id' => 0, 'why' => $why, 'fix' => null );
		};
		$key   = self::addr_key( $address );
		$house = ( '' !== $key && isset( $houses[ $key ] ) && is_array( $houses[ $key ] ) ) ? $houses[ $key ] : null;
		if ( ! $pts ) {
			return $no( 'no_fix' );
		}
		$known = array_values( array_filter( $pts, static function ( $p ) {
			return null !== ( $p['acc'] ?? null );
		} ) );
		if ( ! $known ) {
			return $no( 'no_accuracy' );
		}
		$usable = self::usable_acc();
		$good   = array_values( array_filter( $known, static function ( $p ) use ( $usable ) {
			return (float) $p['acc'] <= $usable;
		} ) );
		if ( ! $good ) {
			return $no( 'vague' );
		}
		if ( '' === $key ) {
			return $no( 'no_street' );
		}
		if ( ! $house ) {
			return $no( 'no_house' );
		}
		$fix = null;
		foreach ( $good as $p ) {
			foreach ( (array) ( $house['points'] ?? array() ) as $hp ) {
				if ( 'match' === self::verdict( $p, (array) $hp ) && ( ! $fix || $p['acc'] < $fix['acc'] ) ) {
					$fix = $p;
				}
			}
		}
		if ( ! $fix ) {
			return $no( 'far' );
		}
		$homes = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $house['home_ids'] ?? array() ) ) ) ) );
		if ( ! $homes ) {
			return $no( 'no_home' );
		}
		if ( count( $homes ) > 1 ) {
			return $no( 'two_homes' );
		}
		return array( 'home_id' => $homes[0], 'why' => '', 'fix' => $fix );
	}

	/** Why a repair stayed on its own, in words. */
	public static function why_words( string $why ): string {
		$map = array(
			'no_fix'      => __( 'The photos have no location.', 'zorderz' ),
			'no_accuracy' => __( 'The photos\' location has no accuracy, so it cannot place the house by itself.', 'zorderz' ),
			'vague'       => __( 'The photos\' location is too rough to place the house.', 'zorderz' ),
			'no_street'   => __( 'The street has no house number.', 'zorderz' ),
			'no_house'    => __( 'No earlier work is known at that street.', 'zorderz' ),
			'far'         => __( 'The photos were not taken at that house.', 'zorderz' ),
			'no_home'     => __( 'That house has no customer record yet.', 'zorderz' ),
			'two_homes'   => __( 'More than one customer is recorded at that house.', 'zorderz' ),
		);
		return $map[ $why ] ?? '';
	}

	/** "Repair: <what> - <work>. <note>" from the business's lists, or the typed words. PURE but for the lists. */
	public static function note_text( string $product, array $work, string $note ): string {
		$p      = self::products();
		$w      = self::work_kinds();
		$labels = array();
		foreach ( $work as $k ) {
			if ( isset( $w[ $k ] ) ) {
				$labels[] = $w[ $k ];
			}
		}
		$what = $p[ $product ] ?? __( 'Repair', 'zorderz' );
		$s    = __( 'Repair:', 'zorderz' ) . ' ' . $what . ( $labels ? ' - ' . implode( ', ', $labels ) : '' ) . '.';
		return '' !== trim( $note ) ? $s . ' ' . trim( $note ) : $s;
	}

	/* ── Making the Project ──────────────────────────────────────────────────────────────────────────────── */

	/** The person's initials (their salesperson code), or ''. */
	public static function initials( int $uid ): string {
		$c = class_exists( 'ZJOB_Scope' ) ? ZJOB_Scope::sp_codes_for( $uid ) : array();
		return $c ? (string) $c[0] : '';
	}

	/** zdz_project_sp_code: a logged repair's Project carries its person's initials. */
	public static function sp_code_for_project( $code, $item ) {
		if ( '' !== (string) $code || ! self::ready() || ! is_array( $item ) ) {
			return $code;
		}
		global $wpdb;
		$c = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT sp_code FROM ' . self::table() . ' WHERE project_id = %s', (string) ( $item['id'] ?? '' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name; the id is a placeholder.
		return '' !== $c ? $c : $code;
	}

	/**
	 * Log a repair. $in: customer, street, what (typed), product, work[], note, media_ids[], points (the server's own
	 * reading of the person's photos: [ {lat, lng, acc} ]). Everything is checked here.
	 *
	 * @return array|WP_Error { project_id, joined, why }
	 */
	public static function log( int $uid, array $in ) {
		if ( ! self::can( $uid ) ) {
			return new WP_Error( 'zjob_repair_off', __( 'Logging a repair is not on for your account.', 'zorderz' ) );
		}
		if ( ! self::ready() || ! class_exists( 'Zjob_Project' ) ) {
			return new WP_Error( 'zjob_repair_write', __( 'Repairs cannot be saved yet. Reload in a moment.', 'zorderz' ) );
		}
		$customer = self::line( (string) ( $in['customer'] ?? '' ), self::MAX_NAME );
		$street   = self::line( (string) ( $in['street'] ?? '' ), self::MAX_ADDR );
		$what     = trim( (string) ( $in['what'] ?? '' ) );
		$product  = sanitize_key( (string) ( $in['product'] ?? '' ) );
		$work     = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $in['work'] ?? array() ) ), array_keys( self::work_kinds() ) ) );
		$note     = self::line( (string) ( $in['note'] ?? '' ), self::MAX_NOTE );
		if ( '' === $street || ( '' === $what && '' === $product ) ) {
			return new WP_Error( 'zjob_repair_missing', __( 'Say the street address and what was repaired.', 'zorderz' ) );
		}
		if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $what ) : strlen( $what ) ) > self::MAX_WHAT ) {
			/* translators: %d: a number of characters */
			return new WP_Error( 'zjob_repair_long', sprintf( __( 'Keep what was repaired under %d characters.', 'zorderz' ), self::MAX_WHAT ) );
		}
		$key  = 'zjob_repairs_' . $uid . '_' . gmdate( 'Ymd' );
		$used = (int) get_transient( $key );
		if ( $used >= self::PER_DAY ) {
			/* translators: %d: how many repairs a day */
			return new WP_Error( 'zjob_repair_many', sprintf( __( 'That is %d repairs logged today. Ask an administrator if more are needed.', 'zorderz' ), self::PER_DAY ) );
		}
		$pts = array();
		foreach ( (array) ( $in['points'] ?? array() ) as $p ) {
			if ( is_array( $p ) && is_numeric( $p['lat'] ?? null ) && is_numeric( $p['lng'] ?? null ) ) {
				$pts[] = array( 'lat' => (float) $p['lat'], 'lng' => (float) $p['lng'], 'acc' => is_numeric( $p['acc'] ?? null ) ? (float) $p['acc'] : null );
			}
		}
		$houses = function_exists( 'apply_filters' ) ? (array) apply_filters( 'zjob_repair_houses', array(), $street, $uid ) : array();
		$join   = self::decide_join( $pts, $street, $houses );

		$origin = 'r' . $uid . '-' . gmdate( 'YmdHis' ) . '-' . wp_rand( 100, 999 );
		$pid    = Zjob_Project::ensure( array(
			'origin_system' => 'repair',
			'origin_id'     => $origin,
			'created_by'    => $uid,
			'customer'      => array( 'name' => $customer, 'address' => $street ),
		) );
		if ( '' === $pid ) {
			return new WP_Error( 'zjob_repair_write', __( 'The repair could not be saved. Try again.', 'zorderz' ) );
		}
		set_transient( $key, $used + 1, 86400 );
		global $wpdb;
		$media = array_slice( array_values( array_unique( array_filter( array_map( 'intval', (array) ( $in['media_ids'] ?? array() ) ) ) ) ), 0, self::MAX_PHOTOS );
		$wpdb->insert( self::table(), array(
			'project_id' => $pid,
			'user_id'    => $uid,
			'sp_code'    => self::initials( $uid ),
			'street'     => $street,
			'addr_key'   => self::addr_key( $street ),
			'product'    => $product,
			'work'       => implode( ',', $work ),
			'media_ids'  => implode( ',', $media ),
			'fix_lat'    => $join['fix'] ? $join['fix']['lat'] : null,
			'fix_lng'    => $join['fix'] ? $join['fix']['lng'] : null,
			'fix_acc'    => $join['fix'] ? $join['fix']['acc'] : null,
			'join_why'   => $join['why'],
			'home_id'    => (int) $join['home_id'],
			'created_at' => current_time( 'mysql', true ),
		) );
		if ( class_exists( 'ZDZ_Usage' ) ) {
			ZDZ_Usage::record( 'jobs', 'repair_logged' );
		}
		// The words of the repair, with any access code cut out.
		$words = '' !== $what ? __( 'Repair logged:', 'zorderz' ) . ' ' . $what : self::note_text( $product, $work, $note );
		if ( class_exists( 'ZJOB_Sealed' ) ) {
			$words = (string) ZJOB_Sealed::seal( $words, array( 'job', 'p' . $pid ) );
		}
		do_action( 'zjob_repair_logged', $pid, $uid, $words );
		return array( 'project_id' => $pid, 'joined' => $join['home_id'] > 0, 'why' => self::why_words( $join['why'] ) );
	}

	/** wp_ajax_zjob_log_repair */
	public static function ajax(): void {
		check_ajax_referer( ZJOB_NONCE, 'nonce' );
		$uid = (int) get_current_user_id();
		$p   = static function ( string $k ): string {
			return isset( $_POST[ $k ] ) && ! is_array( $_POST[ $k ] ) ? (string) wp_unslash( $_POST[ $k ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked above; a raw reader whose every caller below sanitizes the result.
		};
		$res = self::log( $uid, array(
			'customer' => sanitize_text_field( $p( 'customer' ) ),
			'street'   => sanitize_text_field( $p( 'street' ) ),
			'what'     => sanitize_textarea_field( $p( 'what' ) ),
			'product'  => sanitize_key( $p( 'product' ) ),
			'work'     => array_filter( explode( ',', sanitize_text_field( $p( 'work' ) ) ) ),
			'note'     => sanitize_text_field( $p( 'note' ) ),
		) );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
		}
		wp_send_json_success( $res );
	}
}
