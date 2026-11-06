<?php
/**
 * ZSM_DB — the monitor's tables.
 *   {prefix}zsm_vulns    the cached vulnerability feed: one row per (advisory, software, affected range)
 *   {prefix}zsm_findings reconciled findings with their open / acknowledged / resolved state
 *   {prefix}zsm_aliases  local folder slug -> feed slug overrides
 * A feed sync fills {prefix}zsm_vulns_next and swaps it in whole, so a failed sync never
 * empties the live cache.
 *
 * @package Zorderz\Security_Monitor
 */

defined( 'ABSPATH' ) || exit;

class ZSM_DB {

	public static function vulns_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zsm_vulns';
	}

	public static function findings_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zsm_findings';
	}

	public static function aliases_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zsm_aliases';
	}

	public static function vulns_next_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zsm_vulns_next';
	}

	public static function vulns_old_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zsm_vulns_old';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$vulns    = self::vulns_table();
		$findings = self::findings_table();
		$aliases  = self::aliases_table();

		// No SQL comments inside these definitions: dbDelta() compares them line by line with
		// the live table and turns a trailing comment into a broken ALTER TABLE. Column notes:
		//   findings.kind     vuln | outdated | drift | supply | code | codescan
		//   findings.plane    third_party | first_party | core
		//   findings.severity critical | high | medium | low | info
		//   findings.status   open | acknowledged | resolved
		dbDelta( "CREATE TABLE {$vulns} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			advisory_id VARCHAR(191) NOT NULL,
			comp_type VARCHAR(20) NOT NULL,
			slug VARCHAR(191) NOT NULL,
			title TEXT NULL,
			cve VARCHAR(64) NULL,
			cvss_score DECIMAL(3,1) NULL,
			cvss_rating VARCHAR(20) NULL,
			from_version VARCHAR(64) NULL,
			from_inclusive TINYINT(1) NOT NULL DEFAULT 1,
			to_version VARCHAR(64) NULL,
			to_inclusive TINYINT(1) NOT NULL DEFAULT 0,
			patched_version TEXT NULL,
			auth_required TINYINT(1) NOT NULL DEFAULT 0,
			informational TINYINT(1) NOT NULL DEFAULT 0,
			ref_url TEXT NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY slug_type (slug(64), comp_type),
			KEY advisory (advisory_id(64))
		) {$charset};" );

		dbDelta( "CREATE TABLE {$findings} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			finding_key CHAR(40) NOT NULL,
			kind VARCHAR(20) NOT NULL,
			plane VARCHAR(12) NOT NULL,
			comp_type VARCHAR(20) NOT NULL,
			slug VARCHAR(191) NOT NULL,
			name VARCHAR(191) NULL,
			installed_version VARCHAR(64) NULL,
			fixed_version VARCHAR(64) NULL,
			cve VARCHAR(64) NULL,
			title TEXT NULL,
			severity VARCHAR(20) NOT NULL DEFAULT 'info',
			cvss_score DECIMAL(3,1) NULL,
			context_note VARCHAR(255) NULL,
			ref_url TEXT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'open',
			first_seen DATETIME NOT NULL,
			last_seen DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY finding_key (finding_key),
			KEY status_sev (status, severity),
			KEY slug (slug(64))
		) {$charset};" );

		dbDelta( "CREATE TABLE {$aliases} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			local_slug VARCHAR(191) NOT NULL,
			feed_slug VARCHAR(191) NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY local_slug (local_slug)
		) {$charset};" );
	}
}
