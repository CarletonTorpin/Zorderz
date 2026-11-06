<?php
/**
 * Zdz_Flow_DB — the Flow substrate schema + a version-gated, idempotent self-boot.
 *
 * Creates the eight `wp_zdz_`-prefixed Flow tables.
 * Self-boots on `after_setup_theme`, gated by the `zdz_flow_db_version` option, running
 * `dbDelta` only when the stored version is behind or a table is physically missing.
 *
 *   SELF-BOOTS SCHEMA ONLY — NEVER SEEDS DATA. There is no tenant data, no default flow
 *   definition, no ref namespace registered here. The substrate ships EMPTY; apps declare
 *   their flow definitions and ref namespaces via filters (see Zdz_Flow / Zdz_Flow_Refs).
 *
 * INV-A (no state without a transition) is enforced IN CODE by Zdz_Flow: the only writers of
 * `wp_zdz_work_items.state` are Zdz_Flow::create() (the genesis insert, paired atomically with
 * its genesis transition) and Zdz_Flow::transition() (the sole ongoing state writer). A DB
 * `BEFORE UPDATE` trigger is the belt-and-suspenders mechanical form of INV-A, but dbDelta
 * cannot portably manage triggers, so it is DEFERRED (documented, optional) — the code
 * invariant + a scan-gate are the shipping enforcement this release.
 *
 * PORTABILITY NOTES (why the physical types differ from the logical ones):
 *   - JSON columns (guards_evaluated, evidence, envelope, detail, spec) are stored as LONGTEXT
 *     holding JSON. MariaDB aliases the JSON type to LONGTEXT and reports it back as `longtext`,
 *     which would make dbDelta attempt an ALTER on every run — breaking idempotency. LONGTEXT is
 *     the portable, dbDelta-stable equivalent; the logical shape is unchanged.
 *   - DATETIME (not DATETIME(3)) is used for occurred_at/recorded_at/processed_at. Sub-second
 *     ordering is carried by the authoritative per-subject `sequence` counter, not the wall clock,
 *     and DATETIME(3) provokes repeated dbDelta ALTERs on several MySQL/MariaDB builds.
 *
 * PROMOTION PATH: move this file (and its sibling flow classes) to `zorderz/inc/` unchanged to
 * promote Flow from a jobs-app-local helper to a first-class Core service — a move, not a redesign.
 *
 * @package Zorderz\Jobs\Flow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Zdz_Flow_DB' ) ) {

	class Zdz_Flow_DB {

		const DB_VERSION_OPTION = 'zdz_flow_db_version';
		/** 1.1.0: a work item may hold many refs per {system, entity} (refs keyed by the external id too). */
		const DB_VERSION        = '1.1.0';

		/* ── table-name helpers (all `wp_zdz_`-prefixed) ─────────────────────── */

		public static function work_items(): string {
			global $wpdb; return $wpdb->prefix . 'zdz_work_items';
		}
		public static function transitions(): string {
			global $wpdb; return $wpdb->prefix . 'zdz_work_item_transitions';
		}
		public static function refs(): string {
			global $wpdb; return $wpdb->prefix . 'zdz_work_item_refs';
		}
		public static function timers(): string {
			global $wpdb; return $wpdb->prefix . 'zdz_work_item_timers';
		}
		public static function outbox(): string {
			global $wpdb; return $wpdb->prefix . 'zdz_flow_outbox';
		}
		public static function inbox(): string {
			global $wpdb; return $wpdb->prefix . 'zdz_flow_inbox';
		}
		public static function dispositions(): string {
			global $wpdb; return $wpdb->prefix . 'zdz_flow_dispositions';
		}
		public static function definitions(): string {
			global $wpdb; return $wpdb->prefix . 'zdz_flow_definitions';
		}

		/** Every Flow table's fully-qualified name (for presence checks / diagnostics). */
		public static function all_tables(): array {
			return array(
				self::work_items(),
				self::transitions(),
				self::refs(),
				self::timers(),
				self::outbox(),
				self::inbox(),
				self::dispositions(),
				self::definitions(),
			);
		}

		/**
		 * Create/upgrade every Flow table (dbDelta — safe to run repeatedly). Schema only.
		 */
		public static function install(): void {
			global $wpdb;
			$charset_collate = $wpdb->get_charset_collate();

			$wi   = self::work_items();
			$tr   = self::transitions();
			$rf   = self::refs();
			$tm   = self::timers();
			$ob   = self::outbox();
			$ib   = self::inbox();
			$dp   = self::dispositions();
			$df   = self::definitions();

			$sql = array();

			// ── work_items ── the unit of work. `state` is a MATERIALIZED VIEW of the
			//    transition log (INV-A): only Zdz_Flow writes it.
			$sql[] = "CREATE TABLE {$wi} (
				id CHAR(26) NOT NULL,
				tenant_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
				work_type VARCHAR(64) NOT NULL DEFAULT '',
				flow_version INT(10) UNSIGNED NOT NULL DEFAULT 0,
				state VARCHAR(64) NOT NULL DEFAULT '',
				state_since DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				version INT(10) UNSIGNED NOT NULL DEFAULT 0,
				assurance_level VARCHAR(32) NULL DEFAULT NULL,
				human_code VARCHAR(64) NOT NULL DEFAULT '',
				natural_key VARCHAR(191) NOT NULL DEFAULT '',
				parent_id CHAR(26) NULL DEFAULT NULL,
				root_id CHAR(26) NOT NULL DEFAULT '',
				created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY uq_natural (tenant_id,work_type,natural_key),
				KEY idx_state (tenant_id,work_type,state,state_since),
				KEY idx_root (root_id)
			) {$charset_collate};";

			// ── work_item_transitions ── the APPEND-ONLY source of truth.
			$sql[] = "CREATE TABLE {$tr} (
				id CHAR(26) NOT NULL,
				tenant_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
				work_item_id CHAR(26) NOT NULL DEFAULT '',
				sequence INT(10) UNSIGNED NOT NULL DEFAULT 0,
				transition_id VARCHAR(64) NOT NULL DEFAULT '',
				from_state VARCHAR(64) NULL DEFAULT NULL,
				to_state VARCHAR(64) NOT NULL DEFAULT '',
				actor_kind VARCHAR(16) NOT NULL DEFAULT '',
				actor_id VARCHAR(64) NULL DEFAULT NULL,
				on_behalf_of VARCHAR(64) NULL DEFAULT NULL,
				reason TEXT NULL,
				guards_evaluated LONGTEXT NULL,
				evidence LONGTEXT NULL,
				assurance_level VARCHAR(32) NULL DEFAULT NULL,
				idempotency_key CHAR(64) NOT NULL DEFAULT '',
				occurred_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				recorded_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY uq_seq (work_item_id,sequence),
				UNIQUE KEY uq_idem (tenant_id,work_item_id,idempotency_key),
				KEY idx_item (work_item_id)
			) {$charset_collate};";

			// ── work_item_refs ── the namespaced reference map. `system` is a MySQL 8.0
			//    reserved word, so it is backticked here and in every query that touches it.
			//    A work item may hold MANY refs in one {system, entity} (two estimates, a deposit
			//    and a balance invoice, every job), so the primary key includes the external id.
			//    UNIQUE(system,entity,external_id) is the double-writer / duplicate-container catch.
			//    `ref_seq` keeps the order refs were recorded in (the first is the "main" one).
			$sql[] = "CREATE TABLE {$rf} (
				work_item_id CHAR(26) NOT NULL DEFAULT '',
				`system` VARCHAR(32) NOT NULL DEFAULT '',
				entity VARCHAR(32) NOT NULL DEFAULT '',
				external_id VARCHAR(191) NOT NULL DEFAULT '',
				ref_seq BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				external_version VARCHAR(191) NULL DEFAULT NULL,
				last_pulled_at DATETIME NULL DEFAULT NULL,
				last_pushed_at DATETIME NULL DEFAULT NULL,
				sync_state VARCHAR(16) NOT NULL DEFAULT 'pending',
				last_error TEXT NULL,
				PRIMARY KEY  (work_item_id,`system`,entity,external_id),
				UNIQUE KEY uq_ext (`system`,entity,external_id),
				KEY idx_seq (ref_seq)
			) {$charset_collate};";

			// ── work_item_timers ── keyed to fire once (UNIQUE fire_key).
			$sql[] = "CREATE TABLE {$tm} (
				work_item_id CHAR(26) NOT NULL DEFAULT '',
				timer_id VARCHAR(64) NOT NULL DEFAULT '',
				fires_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				extended_count INT(10) UNSIGNED NOT NULL DEFAULT 0,
				extended_total_s BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				fired_at DATETIME NULL DEFAULT NULL,
				fire_key CHAR(64) NULL DEFAULT NULL,
				PRIMARY KEY  (work_item_id,timer_id),
				KEY idx_due (fires_at,fired_at),
				UNIQUE KEY uq_fire (fire_key)
			) {$charset_collate};";

			// ── flow_outbox ── written IN the transition's transaction (no event without a
			//    committed transition; no committed transition without an event).
			$sql[] = "CREATE TABLE {$ob} (
				id CHAR(26) NOT NULL,
				tenant_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
				transition_id CHAR(26) NOT NULL DEFAULT '',
				event_type VARCHAR(128) NOT NULL DEFAULT '',
				visibility VARCHAR(16) NOT NULL DEFAULT 'internal',
				envelope LONGTEXT NULL,
				status VARCHAR(16) NOT NULL DEFAULT 'pending',
				attempts INT(10) UNSIGNED NOT NULL DEFAULT 0,
				next_attempt_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				KEY idx_due (status,next_attempt_at),
				KEY idx_transition (transition_id)
			) {$charset_collate};";

			// ── flow_inbox ── consumers process THEN mark handled in one transaction.
			$sql[] = "CREATE TABLE {$ib} (
				consumer VARCHAR(64) NOT NULL DEFAULT '',
				idempotency_key CHAR(64) NOT NULL DEFAULT '',
				event_id CHAR(26) NOT NULL DEFAULT '',
				subject VARCHAR(191) NOT NULL DEFAULT '',
				sequence INT(10) UNSIGNED NOT NULL DEFAULT 0,
				processed_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				result VARCHAR(16) NOT NULL DEFAULT '',
				PRIMARY KEY  (consumer,idempotency_key),
				KEY idx_order (consumer,subject,sequence)
			) {$charset_collate};";

			// ── flow_dispositions ── INV-B: no exit without a disposition (funnel conservation).
			$sql[] = "CREATE TABLE {$dp} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				tenant_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
				funnel VARCHAR(64) NOT NULL DEFAULT '',
				run_id CHAR(26) NOT NULL DEFAULT '',
				subject_kind VARCHAR(32) NOT NULL DEFAULT '',
				subject_key VARCHAR(191) NOT NULL DEFAULT '',
				stage VARCHAR(64) NOT NULL DEFAULT '',
				code VARCHAR(64) NOT NULL DEFAULT '',
				retryable TINYINT(1) NOT NULL DEFAULT 0,
				retry_after DATETIME NULL DEFAULT NULL,
				detail LONGTEXT NULL,
				decided_by VARCHAR(64) NOT NULL DEFAULT '',
				created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY uq_run_subject (run_id,subject_kind,subject_key,stage),
				KEY idx_code_time (tenant_id,funnel,code,created_at),
				KEY idx_retry (retryable,retry_after)
			) {$charset_collate};";

			// ── flow_definitions ── the validated definition per (tenant,work_type,version).
			//    Ships EMPTY — the `project` definition is registered by the Projects code, not the substrate.
			$sql[] = "CREATE TABLE {$df} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				tenant_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
				work_type VARCHAR(64) NOT NULL DEFAULT '',
				version INT(10) UNSIGNED NOT NULL DEFAULT 0,
				spec LONGTEXT NULL,
				published_at DATETIME NULL DEFAULT NULL,
				published_by BIGINT(20) UNSIGNED NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uq_ver (tenant_id,work_type,version)
			) {$charset_collate};";

			// An existing refs table is re-keyed first, in one ALTER that keeps every row: dbDelta
			// cannot change a primary key or add an AUTO_INCREMENT column (it must be keyed in the
			// same statement). The table stays as it was if the ALTER fails, and the version is
			// not advanced, so the next request tries again.
			$migrated = self::migrate_refs_keys();

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			foreach ( $sql as $stmt ) {
				dbDelta( $stmt );
			}

			if ( $migrated ) {
				update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
			}
		}

		/**
		 * Re-key an existing refs table from one ref per {item, system, entity} to many. Keeps every
		 * row: the old key was narrower than the new one, so no two rows can collide. True when the
		 * table is current (already re-keyed, freshly made, or absent so dbDelta will create it).
		 */
		public static function migrate_refs_keys(): bool {
			global $wpdb;
			$rf = self::refs();
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $rf ) ) !== $rf ) {
				return true; // no table yet: dbDelta creates it with the new keys.
			}
			$pk = array();
			foreach ( (array) $wpdb->get_results( "SHOW INDEX FROM {$rf} WHERE Key_name = 'PRIMARY'", ARRAY_A ) as $ix ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a fixed name; no input in the query.
				$pk[ (int) ( $ix['Seq_in_index'] ?? 0 ) ] = (string) ( $ix['Column_name'] ?? '' );
			}
			ksort( $pk );
			$cols    = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$rf}", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a fixed name; no input in the query.
			$has_seq = in_array( 'ref_seq', array_map( 'strval', $cols ), true );
			$alter   = self::refs_upgrade_sql( $rf, array_values( $pk ), $has_seq );
			if ( '' === $alter ) {
				return true;
			}
			return false !== $wpdb->query( $alter ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- schema change built only from the fixed table name and fixed column names in self::refs_upgrade_sql().
		}

		/**
		 * The one ALTER that brings a refs table to the current keys, or '' when it already has them.
		 * PURE.
		 *
		 * @param string   $table   the refs table name.
		 * @param string[] $pk_cols its primary-key columns, in order.
		 * @param bool     $has_seq whether `ref_seq` exists.
		 * @return string
		 */
		public static function refs_upgrade_sql( string $table, array $pk_cols, bool $has_seq ): string {
			$parts = array();
			if ( ! $has_seq ) {
				$parts[] = 'ADD COLUMN ref_seq BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT AFTER external_id';
				$parts[] = 'ADD KEY idx_seq (ref_seq)';
			}
			if ( array( 'work_item_id', 'system', 'entity', 'external_id' ) !== array_values( $pk_cols ) ) {
				$parts[] = 'DROP PRIMARY KEY';
				$parts[] = 'ADD PRIMARY KEY (work_item_id,`system`,entity,external_id)';
			}
			return $parts ? 'ALTER TABLE ' . $table . ' ' . implode( ', ', $parts ) : '';
		}

		/**
		 * The version-gated, idempotent self-boot entry point. Runs install() only when the
		 * stored version is behind OR a Flow table is physically missing (covers zip-replace
		 * upgrades that skip the activation hook, and folder-copy first installs).
		 */
		public static function ensure_schema(): void {
			global $wpdb;

			if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
				// Fast path: version current. Confirm the anchor table still exists (a dropped
				// table with a stale option would otherwise never self-heal).
				$wi = self::work_items();
				if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wi ) ) === $wi ) {
					return;
				}
			}
			self::install();
		}

		/** Alias matching the jobs app's ZJOB_DB::maybe_upgrade() idiom. */
		public static function maybe_upgrade(): void {
			self::ensure_schema();
		}

		/**
		 * The tenant id for this row/call. On a single-tenant Zorderz install this is the
		 * `ZDZ_TENANT` constant when the platform defines it, else 1 (or the blog id under
		 * multisite). The column is present on every table for Flow-shape parity so a later
		 * Core promotion is mechanical.
		 */
		public static function tenant_id(): int {
			if ( defined( 'ZDZ_TENANT' ) ) {
				return (int) ZDZ_TENANT;
			}
			if ( function_exists( 'is_multisite' ) && is_multisite() ) {
				return (int) get_current_blog_id();
			}
			return 1;
		}
	}
}

// ── Self-boot wiring (the only load-time side effect in the Flow substrate) ──────────
// Schema only, version-gated, idempotent, never seeds data. This file is required during
// plugin inclusion (before plugins_loaded), so registering on after_setup_theme fires
// reliably; init is a belt-and-suspenders in case the loader wires the requires later.
// ensure_schema() short-circuits when the option is current, so a double registration is a
// no-op. Priority 5 puts the tables in place before the jobs app registers its apps (20).
if ( function_exists( 'add_action' ) ) {
	add_action( 'after_setup_theme', array( 'Zdz_Flow_DB', 'ensure_schema' ), 5 );
	add_action( 'init', array( 'Zdz_Flow_DB', 'ensure_schema' ), 5 );
}
