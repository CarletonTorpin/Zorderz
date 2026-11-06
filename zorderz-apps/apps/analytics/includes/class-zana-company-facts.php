<?php
/**
 * ZANA_Company_Facts — the business's own facts the chat may state, kept on one admin screen.
 *
 * A fact is { id (a slug), category, label, value, tier_limit, sort_order }. The chat's instructions get the facts the
 * asker's level may see (filter_by_tier() BEFORE the block is built: a fact above someone's level is never even
 * named in their instructions), cached per level and version: every save, delete or reorder bumps the version, so a
 * stale block is never served. Core ships NO facts: a business types its own (Zorderz → Chat facts) or imports a CSV,
 * or its knowledge pack writes them.
 *
 *   categories   business | people | products | conventions | do_not
 *   tier_limit   all | operator+ | admin
 *
 * Admin REST (manage_options): GET / POST {ns}/analytics/facts, DELETE {ns}/analytics/facts/<id>, POST
 * {ns}/analytics/facts/order, GET {ns}/analytics/facts/csv, POST {ns}/analytics/facts/csv. Every change is written to
 * the admin action log when the theme has one.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Company_Facts {

	const CACHE_PREFIX   = 'zana_facts_block_';
	const CACHE_TTL      = 900;
	const VERSION_OPTION = 'zana_facts_version';
	const CATEGORIES     = array( 'business', 'people', 'products', 'conventions', 'do_not' );
	const TIER_LIMITS    = array( 'all', 'operator+', 'admin' );

	/** Tests hand in the facts (array of rows). */
	public static $rows = null;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zana_company_facts';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta( "CREATE TABLE {$t} (
			id VARCHAR(64) NOT NULL,
			category VARCHAR(20) NOT NULL DEFAULT 'business',
			label VARCHAR(200) NOT NULL DEFAULT '',
			value TEXT NOT NULL,
			tier_limit VARCHAR(16) NOT NULL DEFAULT 'all',
			sort_order INT NOT NULL DEFAULT 100,
			updated_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			updated_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY category (category)
		) " . $wpdb->get_charset_collate() . ';' );
	}

	/** Every fact, in display order. */
	public static function get_all(): array {
		if ( is_array( self::$rows ) ) {
			return self::$rows;
		}
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT id, category, label, value, tier_limit, sort_order, updated_at, updated_by FROM ' . self::table() . " ORDER BY FIELD(category, 'business','people','products','conventions','do_not'), sort_order ASC, id ASC", ARRAY_A );   // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	public static function get( string $id ): ?array {
		foreach ( self::get_all() as $f ) {
			if ( (string) $f['id'] === $id ) {
				return $f;
			}
		}
		return null;
	}

	/** A fact cleaned: slug id, known category and level, plain label, text value. Pure. */
	public static function sanitize( array $f ): array {
		return array(
			'id'         => substr( (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) ( $f['id'] ?? '' ) ) ), 0, 64 ),
			'category'   => in_array( $f['category'] ?? '', self::CATEGORIES, true ) ? (string) $f['category'] : 'business',
			'label'      => mb_substr( trim( wp_strip_all_tags( (string) ( $f['label'] ?? '' ) ) ), 0, 200 ),
			'value'      => mb_substr( trim( (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', wp_strip_all_tags( (string) ( $f['value'] ?? '' ) ) ) ), 0, 4000 ),
			'tier_limit' => self::normalize_tier_limit( (string) ( $f['tier_limit'] ?? 'all' ) ),
			'sort_order' => (int) ( $f['sort_order'] ?? 100 ),
		);
	}

	private static function normalize_tier_limit( string $t ): string {
		return in_array( $t, self::TIER_LIMITS, true ) ? $t : 'all';
	}

	/** '' when the fact may be saved, else why not. Pure. */
	public static function validate( array $f ): string {
		if ( '' === $f['id'] ) {
			return __( 'A fact needs an id (letters, digits, - and _).', 'zorderz' );
		}
		if ( '' === $f['label'] || '' === $f['value'] ) {
			return __( 'A fact needs a label and a value.', 'zorderz' );
		}
		return '';
	}

	/** Create or update one fact. { success, fact? , error? } */
	public static function save( array $fact ): array {
		global $wpdb;
		$f   = self::sanitize( $fact );
		$err = self::validate( $f );
		if ( '' !== $err ) {
			return array( 'success' => false, 'error' => $err );
		}
		$before = self::get( $f['id'] );
		$data   = $f + array( 'updated_at' => current_time( 'mysql' ), 'updated_by' => (int) get_current_user_id() );
		$fmt    = array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d' );
		if ( $before ) {
			$wpdb->update( self::table(), $data, array( 'id' => $f['id'] ), $fmt, array( '%s' ) );
		} else {
			$wpdb->insert( self::table(), $data, $fmt );
		}
		self::log( $before ? 'fact_updated' : 'fact_created', $f['id'] );
		self::bump_version();
		return array( 'success' => true, 'fact' => self::get( $f['id'] ) );
	}

	public static function delete( string $id ): bool {
		global $wpdb;
		if ( ! self::get( $id ) ) {
			return false;
		}
		$ok = (bool) $wpdb->delete( self::table(), array( 'id' => $id ), array( '%s' ) );
		if ( $ok ) {
			self::log( 'fact_deleted', $id );
			self::bump_version();
		}
		return $ok;
	}

	/** Reorder by ids in the wanted order (steps of 10, so a later fact can slot between). */
	public static function reorder( array $ids ): int {
		global $wpdb;
		$n     = 0;
		$order = 10;
		foreach ( $ids as $id ) {
			$id = (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $id ) );
			if ( '' !== $id && $wpdb->update( self::table(), array( 'sort_order' => $order ), array( 'id' => $id ), array( '%d' ), array( '%s' ) ) ) {
				$n++;
			}
			$order += 10;
		}
		if ( $n ) {
			self::log( 'facts_reordered', (string) $n );
			self::bump_version();
		}
		return $n;
	}

	public static function bump_version(): void {
		update_option( self::VERSION_OPTION, (int) get_option( self::VERSION_OPTION, 0 ) + 1, false );
	}

	/** The facts the asker's level may see. tier: admin | operator | staff | viewer (the registry's ladder). Pure. */
	public static function filter_by_tier( array $facts, string $tier ): array {
		$rank = array( 'viewer' => 0, 'staff' => 1, 'operator' => 2, 'admin' => 3 );
		$have = $rank[ $tier ] ?? 0;
		return array_values( array_filter( $facts, function ( $f ) use ( $have ) {
			$need = array( 'all' => 0, 'operator+' => 2, 'admin' => 3 )[ self::normalize_tier_limit( (string) ( $f['tier_limit'] ?? 'all' ) ) ];
			return $have >= $need;
		} ) );
	}

	/** The instructions block for a level ('' when there are no facts it may see). Cached per level and version. */
	public static function block( string $tier ): string {
		$key = self::CACHE_PREFIX . $tier . '_v' . (int) get_option( self::VERSION_OPTION, 0 );
		if ( ! is_array( self::$rows ) && function_exists( 'get_transient' ) ) {
			$hit = get_transient( $key );
			if ( is_string( $hit ) ) {
				return $hit;
			}
		}
		$facts = self::filter_by_tier( self::get_all(), $tier );
		$out   = '';
		if ( $facts ) {
			$names = array( 'business' => 'The business', 'people' => 'People', 'products' => 'Products and services', 'conventions' => 'How things are done', 'do_not' => 'Never do' );
			$cat   = '';
			$out   = "THE BUSINESS'S OWN FACTS (written by the business; state them as given, and never contradict them):\n";
			foreach ( $facts as $f ) {
				if ( $f['category'] !== $cat ) {
					$cat  = (string) $f['category'];
					$out .= '── ' . ( $names[ $cat ] ?? $cat ) . " ──\n";
				}
				$out .= '• ' . $f['label'] . ': ' . preg_replace( '/\s*\n\s*/', ' ', (string) $f['value'] ) . "\n";
			}
		}
		if ( ! is_array( self::$rows ) && function_exists( 'set_transient' ) ) {
			set_transient( $key, $out, self::CACHE_TTL );
		}
		return $out;
	}

	/** CSV of every fact (a formula-looking cell is quoted as text). */
	public static function export_csv(): string {
		$out = "id,category,label,value,tier_limit,sort_order\n";
		foreach ( self::get_all() as $f ) {
			$out .= implode( ',', array_map( function ( $c ) {
				$c = (string) $c;
				if ( preg_match( '/^[=+\-@]/', $c ) ) {
					$c = "'" . $c;
				}
				return '"' . str_replace( '"', '""', $c ) . '"';
			}, array( $f['id'], $f['category'], $f['label'], $f['value'], $f['tier_limit'], $f['sort_order'] ) ) ) . "\n";
		}
		return $out;
	}

	/** Import a CSV with the export's header (adds or replaces by id). { saved, errors[] } */
	public static function import_csv( string $csv ): array {
		$lines = preg_split( '/\r\n|\r|\n/', trim( $csv ) );
		$head  = str_getcsv( (string) array_shift( $lines ) );
		$saved = 0;
		$errs  = array();
		if ( array_map( 'strtolower', $head ) !== array( 'id', 'category', 'label', 'value', 'tier_limit', 'sort_order' ) ) {
			return array( 'saved' => 0, 'errors' => array( __( 'The first line must be: id,category,label,value,tier_limit,sort_order', 'zorderz' ) ) );
		}
		foreach ( array_slice( $lines, 0, 500 ) as $i => $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$c = str_getcsv( $line );
			$c = array_map( function ( $x ) { return 0 === strpos( (string) $x, "'" ) && preg_match( '/^\'[=+\-@]/', (string) $x ) ? substr( (string) $x, 1 ) : (string) $x; }, $c );
			$r = self::save( array_combine( array( 'id', 'category', 'label', 'value', 'tier_limit', 'sort_order' ), array_pad( array_slice( $c, 0, 6 ), 6, '' ) ) );
			if ( ! empty( $r['success'] ) ) {
				$saved++;
			} else {
				/* translators: 1: line number, 2: the reason */
				$errs[] = sprintf( __( 'Line %1$d: %2$s', 'zorderz' ), $i + 2, (string) $r['error'] );
			}
		}
		return array( 'saved' => $saved, 'errors' => $errs );
	}

	private static function log( string $what, string $id ): void {
		if ( class_exists( 'ZDZ_Admin_Dashboard' ) && method_exists( 'ZDZ_Admin_Dashboard', 'log_action' ) ) {
			ZDZ_Admin_Dashboard::log_action( (int) get_current_user_id(), $what, $id, ZANA_APP_ID );
		}
	}
}
