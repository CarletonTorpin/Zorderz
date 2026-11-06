<?php
/**
 * ZAGR_Install - the tables (dbDelta on activation and on a schema change), the rewrite rule's flush, the hourly
 * window sweep. [CORE]
 *
 * Tables (prefix wp_):
 *   zagr_agreements     one row per estimate: the link (hash + sealed words), the state, the frozen document (sealed)
 *                       and schedule JSON, the template and plan versions, the sealed buyer email, the business's signer
 *                       (user id), the signing times, cancel_by, the signed PDF's SHA-256, and the record's head hash
 *                       and length.
 *   zagr_events         append-only, one row per event, a keyed hash chain per agreement: UTC time, sequence, IP and
 *                       user agent sealed, the previous hash. Only ZAGR_Events writes it; nothing updates it.
 *   zagr_change_orders  a change after the customer signed, signed the same way, no new window.
 *   zagr_sessions       the customer's signing session (a cookie's hash): PDF opened, consent, code verified.
 *   zagr_codes          the emailed 6-digit codes, kept only as a MAC under the key; tries and expiry.
 *   zagr_locks          wrong answers to codes per agreement, purpose and source (one visitor's address, or its
 *                       session without one); 20 pause that source for 24 hours or until an administrator turns codes
 *                       back on. Nobody else is paused.
 *
 * The marker is the option zagr_schema = 1. Without it, the tables decide: tables with every column of schema 1 are
 * this app's own (the marker was lost: an options clean-up, a partial restore), so the marker is written again;
 * tables of any other shape are never upgraded or written to, the app takes no agreements, and the settings screen
 * says what they hold (state() 'foreign'). No tables at all is a fresh install. The hourly sweep removes sessions two
 * days, and codes thirty days, after their use.
 *
 * Nothing is removed on deactivation or uninstall: the tables, the record, the PDFs and the key are the business's
 * record of what customers signed, kept for as long as its own retention rules say.
 *
 * @package Zorderz\Agreements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAGR_Install {

	const SCHEMA     = 1;
	const SCHEMA_OPT = 'zagr_schema';
	const CRON       = 'zagr_hourly';

	/** @var string|null this request's state() */
	private static $state = null;

	public static function boot(): void {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ) );
		add_action( self::CRON, array( 'ZAGR_Store', 'sweep_windows' ) );
	}

	public static function tables(): array {
		global $wpdb;
		$p = $wpdb->prefix;
		return array(
			'agreements' => $p . 'zagr_agreements',
			'events'     => $p . 'zagr_events',
			'changes'    => $p . 'zagr_change_orders',
			'sessions'   => $p . 'zagr_sessions',
			'codes'      => $p . 'zagr_codes',
			'locks'      => $p . 'zagr_locks',
		);
	}

	public static function t( string $name ): string {
		return self::tables()[ $name ];
	}

	/** The CREATE TABLE statements, as dbDelta wants them (two spaces after PRIMARY KEY). */
	public static function schema(): array {
		global $wpdb;
		$c = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
		$t = self::tables();
		return array(
			"CREATE TABLE {$t['agreements']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  estimate_id bigint(20) unsigned NOT NULL,
  client_id bigint(20) unsigned NOT NULL DEFAULT 0,
  is_test tinyint(1) NOT NULL DEFAULT 0,
  link_hash char(64) NOT NULL,
  link_sealed text NOT NULL,
  state varchar(20) NOT NULL DEFAULT 'draft',
  language_ok tinyint(1) DEFAULT NULL,
  doc_hash char(64) NOT NULL,
  document_sealed longtext NOT NULL,
  schedule_json text NOT NULL,
  template_version varchar(40) NOT NULL DEFAULT '',
  plan_version varchar(40) NOT NULL DEFAULT '',
  email_sealed text NOT NULL,
  contractor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  contractor_signed_at datetime DEFAULT NULL,
  render_hash char(64) NOT NULL DEFAULT '',
  age_sealed text DEFAULT NULL,
  signed_at datetime DEFAULT NULL,
  copy_received_at datetime DEFAULT NULL,
  copy_via varchar(10) NOT NULL DEFAULT '',
  cancel_by date DEFAULT NULL,
  pdf_cancel_by date DEFAULT NULL,
  window_closed_at datetime DEFAULT NULL,
  cancelled_at datetime DEFAULT NULL,
  cancel_how varchar(20) NOT NULL DEFAULT '',
  pdf_sha256 char(64) NOT NULL DEFAULT '',
  pdf_file varchar(80) NOT NULL DEFAULT '',
  vault_doc_id bigint(20) unsigned NOT NULL DEFAULT 0,
  head_hash char(64) NOT NULL DEFAULT '',
  event_count int(10) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY estimate_id (estimate_id),
  UNIQUE KEY link_hash (link_hash),
  KEY state (state)
) $c;",
			"CREATE TABLE {$t['events']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  agreement_id bigint(20) unsigned NOT NULL,
  seq int(10) unsigned NOT NULL,
  type varchar(40) NOT NULL,
  at_utc varchar(26) NOT NULL,
  actor varchar(40) NOT NULL DEFAULT '',
  ip_sealed text NOT NULL,
  ua_sealed text NOT NULL,
  detail longtext NOT NULL,
  prev_hash char(64) NOT NULL,
  hash char(64) NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY agreement_seq (agreement_id,seq),
  KEY type (type)
) $c;",
			"CREATE TABLE {$t['changes']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  agreement_id bigint(20) unsigned NOT NULL,
  seq int(10) unsigned NOT NULL,
  state varchar(20) NOT NULL DEFAULT 'draft',
  doc_hash char(64) NOT NULL,
  document_sealed longtext NOT NULL,
  schedule_json text NOT NULL,
  template_version varchar(40) NOT NULL DEFAULT '',
  plan_version varchar(40) NOT NULL DEFAULT '',
  contractor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  contractor_signed_at datetime DEFAULT NULL,
  render_hash char(64) NOT NULL DEFAULT '',
  signed_at datetime DEFAULT NULL,
  pdf_sha256 char(64) NOT NULL DEFAULT '',
  pdf_file varchar(80) NOT NULL DEFAULT '',
  vault_doc_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY agreement_seq (agreement_id,seq)
) $c;",
			"CREATE TABLE {$t['sessions']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  agreement_id bigint(20) unsigned NOT NULL,
  sid_hash char(64) NOT NULL,
  created_at datetime NOT NULL,
  last_seen_at datetime NOT NULL,
  viewed_at datetime DEFAULT NULL,
  consent_shown_for varchar(100) NOT NULL DEFAULT '',
  pdf_for varchar(100) NOT NULL DEFAULT '',
  pdf_sha256 char(64) NOT NULL DEFAULT '',
  pdf_opened_at datetime DEFAULT NULL,
  consent_for varchar(100) NOT NULL DEFAULT '',
  consent_at datetime DEFAULT NULL,
  verified_purpose varchar(12) NOT NULL DEFAULT '',
  verified_for varchar(100) NOT NULL DEFAULT '',
  verified_at datetime DEFAULT NULL,
  copy_until datetime DEFAULT NULL,
  flash varchar(40) NOT NULL DEFAULT '',
  age_sealed text DEFAULT NULL,
  ip_tag char(16) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY sid_hash (sid_hash),
  KEY agreement_id (agreement_id),
  KEY agreement_ip (agreement_id,ip_tag)
) $c;",
			"CREATE TABLE {$t['codes']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  agreement_id bigint(20) unsigned NOT NULL,
  session_id bigint(20) unsigned NOT NULL,
  purpose varchar(12) NOT NULL,
  bound_for varchar(100) NOT NULL DEFAULT '',
  code_mac char(64) NOT NULL,
  created_at datetime NOT NULL,
  expires_at datetime NOT NULL,
  tries tinyint(3) unsigned NOT NULL DEFAULT 0,
  used_at datetime DEFAULT NULL,
  ip_tag char(16) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY agreement_id (agreement_id),
  KEY session_id (session_id),
  KEY ip_tag (ip_tag)
) $c;",
			"CREATE TABLE {$t['locks']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  agreement_id bigint(20) unsigned NOT NULL,
  purpose varchar(12) NOT NULL,
  source varchar(24) NOT NULL DEFAULT '',
  wrong int(10) unsigned NOT NULL DEFAULT 0,
  locked_until datetime DEFAULT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY agreement_purpose_source (agreement_id,purpose,source)
) $c;",
		);
	}

	public static function install(): void {
		if ( ! function_exists( 'dbDelta' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		foreach ( self::schema() as $sql ) {
			dbDelta( $sql );
		}
		update_option( self::SCHEMA_OPT, self::SCHEMA, true );
		self::$state = 'ok';
	}

	/**
	 * 'ok' (schema 1, made by this app), 'fresh' (no tables yet), or 'foreign' (tables of another shape: never
	 * upgraded, never written; the app takes no agreements while they are there). Without the marker the tables' own
	 * columns decide; tables with every column of schema 1 are this app's, and the marker is written again.
	 */
	public static function state(): string {
		if ( null !== self::$state ) {
			return self::$state;
		}
		if ( self::SCHEMA === (int) get_option( self::SCHEMA_OPT, 0 ) ) {
			return self::$state = 'ok';
		}
		$present = self::present();
		if ( ! $present ) {
			return self::$state = 'fresh';
		}
		$want = self::columns();
		foreach ( $present as $name => $have ) {
			if ( array_diff( $want[ $name ], $have ) ) {
				return self::$state = 'foreign';
			}
		}
		// this schema's own tables: the marker was lost, so it is written again (and any missing table made)
		error_log( 'Zorderz agreements: the schema marker was missing; its tables have every column of schema 1, so the marker was written again.' ); // phpcs:ignore
		self::install();
		return self::$state = 'ok';
	}

	/** The app's tables that exist now: name => their columns. */
	private static function present(): array {
		global $wpdb;
		$out = array();
		foreach ( self::tables() as $name => $t ) {
			if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
				$out[ $name ] = array_map( function ( $r ) { return (string) ( $r['Field'] ?? '' ); }, (array) $wpdb->get_results( 'SHOW COLUMNS FROM ' . $t, ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (tables()); the query takes no values
			}
		}
		return $out;
	}

	/** Schema 1's columns, table by table, read from the CREATE TABLE statements themselves. */
	public static function columns(): array {
		$out   = array();
		$names = array_keys( self::tables() );
		foreach ( array_values( self::schema() ) as $i => $sql ) {
			preg_match_all( '/^  ([a-z][a-z0-9_]*) [a-z]/m', $sql, $m );
			$out[ $names[ $i ] ] = array_values( array_diff( $m[1], array( 'id' ) ) );
			$out[ $names[ $i ] ][] = 'id';
		}
		return $out;
	}

	/**
	 * What the refused tables hold, for the notice: { agreements, real } (real: rows not marked as a test agreement),
	 * or null when it can't be told (no agreements table, or no is_test column).
	 */
	public static function foreign_rows(): ?array {
		global $wpdb;
		$p = self::present();
		if ( ! isset( $p['agreements'] ) || ! in_array( 'is_test', $p['agreements'], true ) ) {
			return null;
		}
		$t = self::t( 'agreements' );
		return array(
			'agreements' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $t ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (tables()); the query takes no values
			'real'       => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $t . ' WHERE is_test <> 1' ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (tables()); the query takes no values
		);
	}

	/** May the app read and write its tables? (schema 1, made by this app) */
	public static function ready(): bool {
		return 'ok' === self::state();
	}

	public static function forget(): void {
		self::$state = null;
	}

	public static function maybe_upgrade(): void {
		if ( 'fresh' === self::state() ) {
			self::install();
		}
		// every site of a network runs its own sweep, not only the one that was activated
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON );
		}
	}

	public static function activate(): void {
		self::forget();
		if ( 'fresh' === self::state() ) {
			self::install();
		}
		if ( function_exists( 'add_option' ) ) {
			add_option( ZAGR_Settings::OPT_MODE, 'test' ); // switched off for customers until an administrator turns it on
		}
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON );
		}
		ZAGR_Public::add_rewrite();
		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules();
		}
	}

	public static function deactivate(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::CRON );
		}
		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules();
		}
	}
}
