<?php
/**
 * ZANA_Ledger_Store — the ledger's tables in the site's database: the only class that writes them.
 *
 *   zana_ledger_invoices    one row per billing invoice (amounts, dates, status, derived source / salesperson / town / mix /
 *                           brands, the billing system's visibility: 0 active, 1 deleted, 2 archived)
 *   zana_ledger_estimates   one row per billing estimate (accepted, invoiced, status label, the same derived columns)
 *   zana_ledger_payments    one row per payment (date, amount, type, invoice): money received by the day it arrived
 *   zana_ledger_leads       one row per CRM lead (created, closed, status, source, channel, assignee, value)
 *   zana_ledger_lead_notes  one row per CRM note with text (lead, note key, time, author, body; access codes cut)
 *   zana_ledger_note_reads  one row per lead whose notes were read (when, how many, how: 'find' | 'get' | 'miss')
 *
 * Rows are written with REPLACE by id, so reading a document twice never counts it twice and a pass stopped mid-page
 * loses nothing but that page. Nothing here reads a provider or writes anywhere but these tables.
 *
 * The sync (ZANA_Ledger) talks to this class through the methods below, so a test can stand in an in-memory store with
 * the same methods.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Ledger_Store {

	const DB_VERSION = '1';
	const DB_OPTION  = 'zana_ledger_db';
	const LOCK       = 'zana_ledger_lock';

	/** kind => [ table suffix, id column ]. */
	const TABLES = array(
		'invoices'  => array( 'zana_ledger_invoices', 'invoice_id' ),
		'estimates' => array( 'zana_ledger_estimates', 'estimate_id' ),
		'payments'  => array( 'zana_ledger_payments', 'payment_id' ),
		'leads'     => array( 'zana_ledger_leads', 'lead_id' ),
	);

	/** This pass's lock stamp: unlock() removes only its own lock, never a newer pass's. */
	private $stamp = '';

	/** Whether the notes tables exist (null: not checked this request). */
	private $notes_ready = null;

	public static function table( string $kind ): string {
		global $wpdb;
		$t = self::TABLES[ $kind ][0] ?? ( 'notes' === $kind ? 'zana_ledger_lead_notes' : ( 'reads' === $kind ? 'zana_ledger_note_reads' : '' ) );
		return '' !== $t ? $wpdb->prefix . $t : '';
	}

	/* ─────────────────────────── schema ─────────────────────────── */

	public static function maybe_install(): void {
		if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
			return;
		}
		self::install();
	}

	public static function install(): void {
		global $wpdb;
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		$c = $wpdb->get_charset_collate();
		dbDelta( 'CREATE TABLE ' . self::table( 'invoices' ) . " (
			invoice_id bigint(20) unsigned NOT NULL,
			invoice_number varchar(40) NOT NULL DEFAULT '',
			customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
			customer_name varchar(255) NOT NULL DEFAULT '',
			city varchar(120) NOT NULL DEFAULT '',
			zip varchar(12) NOT NULL DEFAULT '',
			inv_date date DEFAULT NULL,
			due_date date DEFAULT NULL,
			paid_date date DEFAULT NULL,
			amount decimal(12,2) NOT NULL DEFAULT 0,
			paid decimal(12,2) NOT NULL DEFAULT 0,
			outstanding decimal(12,2) NOT NULL DEFAULT 0,
			status smallint(6) NOT NULL DEFAULT 0,
			pay_status varchar(30) NOT NULL DEFAULT '',
			lead_source varchar(80) NOT NULL DEFAULT '',
			rep_code varchar(20) NOT NULL DEFAULT '',
			rep_inferred tinyint(1) NOT NULL DEFAULT 0,
			mix text,
			brands text,
			vis_state tinyint(4) NOT NULL DEFAULT 0,
			fb_updated datetime DEFAULT NULL,
			synced_at datetime NOT NULL,
			PRIMARY KEY  (invoice_id),
			KEY customer_id (customer_id),
			KEY inv_date (inv_date),
			KEY invoice_number (invoice_number)
		) {$c};" );
		dbDelta( 'CREATE TABLE ' . self::table( 'estimates' ) . " (
			estimate_id bigint(20) unsigned NOT NULL,
			estimate_number varchar(40) NOT NULL DEFAULT '',
			customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
			customer_name varchar(255) NOT NULL DEFAULT '',
			city varchar(120) NOT NULL DEFAULT '',
			est_date date DEFAULT NULL,
			amount decimal(12,2) NOT NULL DEFAULT 0,
			status_label varchar(30) NOT NULL DEFAULT '',
			accepted tinyint(1) NOT NULL DEFAULT 0,
			invoiced tinyint(1) NOT NULL DEFAULT 0,
			lead_source varchar(80) NOT NULL DEFAULT '',
			rep_code varchar(20) NOT NULL DEFAULT '',
			mix text,
			brands text,
			vis_state tinyint(4) NOT NULL DEFAULT 0,
			fb_updated datetime DEFAULT NULL,
			synced_at datetime NOT NULL,
			PRIMARY KEY  (estimate_id),
			KEY customer_id (customer_id),
			KEY est_date (est_date),
			KEY estimate_number (estimate_number)
		) {$c};" );
		dbDelta( 'CREATE TABLE ' . self::table( 'payments' ) . " (
			payment_id bigint(20) unsigned NOT NULL,
			invoice_id bigint(20) unsigned NOT NULL DEFAULT 0,
			pay_date date DEFAULT NULL,
			amount decimal(12,2) NOT NULL DEFAULT 0,
			pay_type varchar(40) NOT NULL DEFAULT '',
			vis_state tinyint(4) NOT NULL DEFAULT 0,
			fb_updated datetime DEFAULT NULL,
			synced_at datetime NOT NULL,
			PRIMARY KEY  (payment_id),
			KEY pay_date (pay_date),
			KEY invoice_id (invoice_id)
		) {$c};" );
		dbDelta( 'CREATE TABLE ' . self::table( 'leads' ) . " (
			lead_id bigint(20) unsigned NOT NULL,
			lead_number varchar(40) NOT NULL DEFAULT '',
			name varchar(255) NOT NULL DEFAULT '',
			created_at datetime DEFAULT NULL,
			closed_at datetime DEFAULT NULL,
			status tinyint(4) NOT NULL DEFAULT 0,
			source varchar(120) NOT NULL DEFAULT '',
			channel varchar(120) NOT NULL DEFAULT '',
			assignee varchar(120) NOT NULL DEFAULT '',
			value decimal(12,2) NOT NULL DEFAULT 0,
			account_id bigint(20) unsigned NOT NULL DEFAULT 0,
			synced_at datetime NOT NULL,
			PRIMARY KEY  (lead_id),
			KEY created_at (created_at),
			KEY status (status)
		) {$c};" );
		dbDelta( 'CREATE TABLE ' . self::table( 'notes' ) . " (
			lead_id bigint(20) unsigned NOT NULL,
			note_key varchar(40) NOT NULL DEFAULT '',
			created_at datetime DEFAULT NULL,
			author varchar(120) NOT NULL DEFAULT '',
			body mediumtext,
			synced_at datetime NOT NULL,
			PRIMARY KEY  (lead_id,note_key),
			KEY created_at (created_at)
		) {$c};" );
		dbDelta( 'CREATE TABLE ' . self::table( 'reads' ) . " (
			lead_id bigint(20) unsigned NOT NULL,
			read_at datetime NOT NULL,
			notes int(11) NOT NULL DEFAULT 0,
			via varchar(8) NOT NULL DEFAULT '',
			PRIMARY KEY  (lead_id),
			KEY read_at (read_at)
		) {$c};" );
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	/* ─────────────────────────── the sync's writes ─────────────────────────── */

	/** One row of a kind (REPLACE by its id). */
	public function upsert( string $kind, array $row ): bool {
		global $wpdb;
		$t = self::table( $kind );
		if ( '' === $t ) {
			return false;
		}
		if ( false === $wpdb->replace( $t, $row ) ) {
			error_log( 'Zorderz Analytics ledger: a ' . $kind . ' row could not be written (' . (string) $wpdb->last_error . ').' );
			return false;
		}
		return true;
	}

	public function count( string $kind ): int {
		global $wpdb;
		$t = self::table( $kind );
		return '' !== $t ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL -- fixed table name.
	}

	public function max_lead_id(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT MAX(lead_id) FROM ' . self::table( 'leads' ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- fixed table name.
	}

	/**
	 * A lead the ledger has as open that a complete read of every open lead did not return was closed since: status -1
	 * ("closed, outcome not re-read yet") until the rolling re-read reaches it.
	 */
	public function close_vanished_open_leads( string $read_started_utc ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'leads' ) . ' SET status = -1 WHERE status = 0 AND synced_at < %s', $read_started_utc ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- fixed table name.
	}

	/**
	 * The derived columns the ledger holds for these ids: id => [ lead_source, brands, rep_code, city, zip, mix, due_date,
	 * paid_date ] (the last two for invoices only).
	 */
	public function derived( string $kind, array $ids ): array {
		global $wpdb;
		$t   = self::table( $kind );
		$key = self::TABLES[ $kind ][1] ?? '';
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( '' === $t || ! $ids || ! in_array( $kind, array( 'invoices', 'estimates' ), true ) ) {
			return array();
		}
		$cols = 'lead_source, brands, rep_code, city, mix' . ( 'invoices' === $kind ? ', zip, due_date, paid_date' : '' );
		$out  = array();
		foreach ( (array) $wpdb->get_results( "SELECT {$key} AS id, {$cols} FROM {$t} WHERE {$key} IN (" . implode( ',', $ids ) . ')', ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL -- ids are integers, names fixed.
			$id = (int) $r['id'];
			unset( $r['id'] );
			$out[ $id ] = $r;
		}
		return $out;
	}

	/** Rewrite only the derived columns of one row. */
	public function update_derived( string $kind, int $id, array $fields ): bool {
		global $wpdb;
		$allowed = array( 'lead_source', 'brands', 'rep_code', 'city', 'mix', 'zip', 'due_date', 'paid_date' );
		$fields  = array_intersect_key( $fields, array_flip( $allowed ) );
		$key     = self::TABLES[ $kind ][1] ?? '';
		if ( ! $fields || '' === $key || $id <= 0 ) {
			return false;
		}
		return false !== $wpdb->update( self::table( $kind ), $fields, array( $key => $id ) );
	}

	/**
	 * After a whole year of payments was read again (every page written since $read_started_utc): a row the ledger still
	 * holds for that year that the read did not touch was deleted in the billing system, or moved to another year. It goes.
	 */
	public function sweep_payments( int $year, string $read_started_utc ): int {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare(
			'DELETE FROM ' . self::table( 'payments' ) . ' WHERE pay_date >= %s AND pay_date <= %s AND synced_at < %s', // phpcs:ignore WordPress.DB.PreparedSQL -- fixed table name.
			sprintf( '%04d-01-01', $year ), sprintf( '%04d-12-31', $year ), $read_started_utc
		) );
	}

	/** The invoice number for a billing invoice id, '' when the ledger has no live row for it. */
	public function invoice_number_for_id( int $id ): string {
		global $wpdb;
		if ( $id <= 0 ) {
			return '';
		}
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT invoice_number FROM ' . self::table( 'invoices' ) . ' WHERE invoice_id = %d AND vis_state <> 1', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- fixed table name.
	}

	/* ─────────────────────────── the lock ─────────────────────────── */

	/**
	 * One pass at a time: an INSERT IGNORE of one options row (add_option() upserts; a transient's get-then-set leaves a
	 * gap). A lock older than five minutes is a pass the host stopped, and is taken over.
	 */
	public function lock(): bool {
		global $wpdb;
		$table = $wpdb->options ?? ( $wpdb->prefix . 'options' );
		for ( $try = 0; $try < 2; $try++ ) {
			$stamp = time() . '.' . wp_rand( 100000, 999999 );
			$got   = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK, $stamp ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- the options table.
			if ( (int) $got > 0 ) {
				$this->stamp = $stamp;
				return true;
			}
			$held  = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$table} WHERE option_name = %s", self::LOCK ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- the options table.
			$since = (int) $held;
			if ( $since > 0 && $since >= time() - 5 * MINUTE_IN_SECONDS ) {
				return false;
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE option_name = %s AND option_value = %s", self::LOCK, $held ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- the options table.
		}
		return false;
	}

	public function unlock(): void {
		global $wpdb;
		$table = $wpdb->options ?? ( $wpdb->prefix . 'options' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE option_name = %s AND option_value = %s", self::LOCK, $this->stamp ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- the options table.
		$this->stamp = '';
	}

	/* ─────────────────────────── lead notes ─────────────────────────── */

	public function notes_ready(): bool {
		global $wpdb;
		if ( null === $this->notes_ready ) {
			$ok = true;
			foreach ( array( 'notes', 'reads' ) as $k ) {
				$t  = self::table( $k );
				$ok = $ok && ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t );
			}
			$this->notes_ready = $ok;
		}
		return $this->notes_ready;
	}

	/**
	 * Keep one lead's notes. Notes with text are written; with $may_delete (a full read: the CRM's getLead returns every
	 * note) a note no longer in the CRM is removed. The lead is stamped as read. Returns how many notes it now has.
	 *
	 * @param array[] $rows note rows ( note_key, created_at, author, body )
	 */
	public function store_notes( int $lead_id, array $rows, bool $may_delete, string $via, string $now_utc ): int {
		global $wpdb;
		$t    = self::table( 'notes' );
		$keep = array();
		foreach ( $rows as $r ) {
			if ( isset( $keep[ $r['note_key'] ] ) ) {
				continue;
			}
			$keep[ $r['note_key'] ] = true;
			if ( false === $wpdb->replace( $t, $r + array( 'lead_id' => $lead_id, 'synced_at' => $now_utc ) ) ) {
				error_log( 'Zorderz Analytics ledger: a lead note could not be written (' . (string) $wpdb->last_error . ').' );
			}
		}
		if ( $may_delete && $keep ) {
			$in = implode( ',', array_map( function ( $k ) use ( $wpdb ) { return $wpdb->prepare( '%s', $k ); }, array_keys( $keep ) ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE lead_id = %d AND note_key NOT IN ({$in})", $lead_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- each key prepared above.
		} elseif ( $may_delete ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE lead_id = %d", $lead_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- fixed table name.
		}
		$held = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE lead_id = %d", $lead_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- fixed table name.
		$wpdb->replace( self::table( 'reads' ), array( 'lead_id' => $lead_id, 'read_at' => $now_utc, 'notes' => $held, 'via' => substr( $via, 0, 8 ) ) );
		return $held;
	}

	/** A lead the CRM could not read: stamped, so it waits for the next try instead of holding the rest back. */
	public function mark_miss( int $lead_id, string $now_utc ): void {
		global $wpdb;
		$held = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table( 'notes' ) . ' WHERE lead_id = %d', $lead_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- fixed table name.
		$wpdb->replace( self::table( 'reads' ), array( 'lead_id' => $lead_id, 'read_at' => $now_utc, 'notes' => $held, 'via' => 'miss' ) );
	}

	/**
	 * Leads whose notes should be read, most urgent first: never read (newest first); not readable last time, again a day
	 * later; in one-lead-at-a-time mode also open leads read over a day ago, leads closed since their read, and any read
	 * over $all_after seconds ago. $never_only: only leads never read.
	 */
	public function leads_needing_notes( int $limit, bool $never_only, bool $get_mode, int $now, int $open_after, int $all_after ): array {
		global $wpdb;
		$l   = self::table( 'leads' );
		$r   = self::table( 'reads' );
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT l.lead_id FROM {$l} l LEFT JOIN {$r} r ON r.lead_id = l.lead_id WHERE r.lead_id IS NULL ORDER BY l.lead_id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL -- fixed table names.
			$limit
		) ) );
		if ( $never_only || count( $ids ) >= $limit ) {
			return $ids;
		}
		$day  = gmdate( 'Y-m-d H:i:s', $now - $open_after );
		$miss = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT r.lead_id FROM {$r} r JOIN {$l} l ON l.lead_id = r.lead_id WHERE r.via = 'miss' AND r.read_at < %s ORDER BY r.read_at ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL -- fixed table names.
			$day, $limit - count( $ids )
		) ) );
		$ids = array_merge( $ids, $miss );
		if ( ! $get_mode || count( $ids ) >= $limit ) {
			return $ids;
		}
		$stale = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT l.lead_id FROM {$l} l JOIN {$r} r ON r.lead_id = l.lead_id WHERE r.via <> 'miss' AND ( ( l.status = 0 AND r.read_at < %s ) OR ( l.closed_at IS NOT NULL AND l.closed_at > r.read_at ) OR r.read_at < %s ) ORDER BY l.status = 0 DESC, r.read_at ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL -- fixed table names.
			$day, gmdate( 'Y-m-d H:i:s', $now - $all_after ), $limit - count( $ids )
		) ) );
		return array_merge( $ids, $stale );
	}

	/** The notes copy in numbers: leads read, notes held, the last write. */
	public function notes_summary(): array {
		global $wpdb;
		if ( ! $this->notes_ready() ) {
			return array( 'leads_read' => 0, 'notes' => 0, 'last_write' => '' );
		}
		$row = $wpdb->get_row( "SELECT SUM(via <> 'miss') AS n, COALESCE(SUM(notes),0) AS m, MAX(read_at) AS w FROM " . self::table( 'reads' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- fixed table name.
		return array( 'leads_read' => (int) ( $row['n'] ?? 0 ), 'notes' => (int) ( $row['m'] ?? 0 ), 'last_write' => (string) ( $row['w'] ?? '' ) );
	}
}
