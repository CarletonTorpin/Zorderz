<?php
/**
 * Inbox schema migration — v1.3.0 (P6a: ingest-time enrichment — index cards, extracts, tags).
 *
 * Additive + idempotent. Extends the Ballast with a per-message "index card" and a
 * tag layer so the assistant can COMPUTE answers ("how many units of a product have
 * I ordered") from structured, owner-scoped data instead of re-reading raw bodies.
 *
 * New columns on wp_zib_messages (added by guarded ALTER — NOT dbDelta, which would
 * re-diff the whole P1 table definition and risk drift):
 *   body_clean_text  MEDIUMTEXT   — quoted-history/signature-stripped body, PLAINTEXT so
 *                                   it can be FULLTEXT-indexed (D4 hybrid; the raw full
 *                                   body stays ciphertext in body_enc, untouched).
 *   gist             VARCHAR(512) — one-line summary. Deterministic first line in P6a; a
 *                                   quarantined synthesizer can fill it richly in P6b.
 *   card_enc         LONGTEXT     — full structured card JSON (entities, commitments, money
 *                                   refs), ENCRYPTED at rest (ZIB_Crypto), like the body.
 *   enrich_ver       INT          — enrichment version applied (0 = not yet enriched).
 *   enriched_at      DATETIME
 *
 * New tables:
 *   wp_zib_extracts      — normalized, SQL-aggregatable facts (product+qty, money, order/
 *                           PO refs, commitments). Owner-scoped. The SUM-able layer.
 *   wp_zib_tags          — the tag REGISTRY (definitions only). scope global|user. Global
 *                           is a curated, PII-screened, human-authored vocabulary; user tags
 *                           are private to their owner and NEVER shown/suggested cross-user
 *                           (the covert-channel guard — a tag like 'HideFromThisPerson' can
 *                           never reach another user's eyes).
 *   wp_zib_message_tags  — tag ASSIGNMENTS. ALWAYS owner-scoped; never crosses users.
 *
 * A NEW FULLTEXT index ft_zib_all(subject, snippet, parties_text, gist, body_clean_text)
 * is added ALONGSIDE the existing ft_zib_search, so the live owner_chat MATCH (still on
 * ft_zib_search) is UNTOUCHED by P6a; the richer retrieval is wired in a later phase.
 *
 * @since 0.6.0 (P6a — enrichment foundation)
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIB_Migrate_1_3_0 {

	/** Codified global seed taxonomy: [ tag_key, Label, definition ]. PII-free, human-authored. */
	const SEED_TAGS = array(
		array( 'conversation', 'Conversation', 'A back-and-forth human exchange, not a one-way notice or automated message.' ),
		array( 'transaction',  'Transaction',  'A message about a purchase, order, payment, invoice, or receipt.' ),
		array( 'order',        'Order',        'Placing, confirming, or discussing a product or material order.' ),
		array( 'quote',        'Quote',        'A price quote or estimate requested, sent, or discussed.' ),
		array( 'invoice',      'Invoice',      'An invoice or bill sent or received.' ),
		array( 'receipt',      'Receipt',      'A payment receipt or confirmation of payment.' ),
		array( 'shipping',     'Shipping',     'Shipment, delivery, tracking, or logistics status.' ),
		array( 'scheduling',   'Scheduling',   'Arranging or confirming an appointment, install, or visit date.' ),
		array( 'complaint',    'Complaint',    'Dissatisfaction, a problem report, or a request to fix something.' ),
		array( 'follow-up',    'Follow-up',    'Chasing a reply, checking status, or continuing an open thread.' ),
		array( 'lead',         'Lead',         'A prospective-customer inquiry or a lead notification.' ),
		array( 'vendor',       'Vendor',       'Correspondence with a supplier or vendor.' ),
		array( 'support',      'Support',      'A support or service request or response.' ),
		array( 'automated',    'Automated',    'A machine-generated message (no-reply, notification, mailer, digest).' ),
		array( 'marketing',    'Marketing',    'Promotional, newsletter, or marketing content.' ),
		array( 'personal',     'Personal',     'A personal, non-business message.' ),
	);

	public static function run(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;

		self::add_message_columns();

		// The SUM-able facts layer. Owner-scoped; plaintext structured numbers/labels.
		dbDelta( "CREATE TABLE {$p}zib_extracts (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			message_id bigint(20) unsigned NOT NULL,
			owner_user_id bigint(20) unsigned NOT NULL,
			account_id bigint(20) unsigned NOT NULL,
			kind varchar(16) NOT NULL DEFAULT '',
			label varchar(191) NOT NULL DEFAULT '',
			qty decimal(14,2) DEFAULT NULL,
			unit varchar(32) NOT NULL DEFAULT '',
			amount decimal(14,2) DEFAULT NULL,
			currency varchar(8) NOT NULL DEFAULT '',
			ref varchar(64) NOT NULL DEFAULT '',
			raw_span varchar(160) NOT NULL DEFAULT '',
			confidence decimal(3,2) NOT NULL DEFAULT 0.00,
			source varchar(20) NOT NULL DEFAULT 'deterministic',
			received_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_owner_kind (owner_user_id, kind),
			KEY idx_owner_recv (owner_user_id, received_at),
			KEY idx_label (label),
			KEY idx_msg (message_id)
		) {$charset};" );

		// The tag REGISTRY (definitions only). global rows: owner_user_id = 0.
		dbDelta( "CREATE TABLE {$p}zib_tags (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			tag_key varchar(64) NOT NULL DEFAULT '',
			label varchar(64) NOT NULL DEFAULT '',
			definition varchar(255) NOT NULL DEFAULT '',
			scope varchar(8) NOT NULL DEFAULT 'user',
			owner_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			pii_clean tinyint(1) NOT NULL DEFAULT 0,
			status varchar(12) NOT NULL DEFAULT 'active',
			source varchar(20) NOT NULL DEFAULT 'seed',
			usage_count int(11) NOT NULL DEFAULT 0,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_tag (scope, owner_user_id, tag_key),
			KEY idx_scope (scope),
			KEY idx_owner (owner_user_id)
		) {$charset};" );

		// Tag ASSIGNMENTS. ALWAYS owner-scoped; never crosses users.
		dbDelta( "CREATE TABLE {$p}zib_message_tags (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			message_id bigint(20) unsigned NOT NULL,
			owner_user_id bigint(20) unsigned NOT NULL,
			tag_id bigint(20) unsigned NOT NULL,
			source varchar(20) NOT NULL DEFAULT 'deterministic',
			confidence decimal(3,2) NOT NULL DEFAULT 0.00,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_msg_tag (message_id, tag_id),
			KEY idx_owner_tag (owner_user_id, tag_id),
			KEY idx_msg (message_id)
		) {$charset};" );

		self::ensure_enriched_fulltext();
		self::seed_tags();
	}

	/** Guarded, idempotent ADD COLUMN for each enrichment field on wp_zib_messages. */
	private static function add_message_columns(): void {
		global $wpdb;
		$t    = $wpdb->prefix . 'zib_messages';
		$cols = array(
			'body_clean_text' => "ADD COLUMN body_clean_text mediumtext NULL",
			'gist'            => "ADD COLUMN gist varchar(512) NOT NULL DEFAULT ''",
			'card_enc'        => "ADD COLUMN card_enc longtext NULL",
			'enrich_ver'      => "ADD COLUMN enrich_ver int(11) NOT NULL DEFAULT 0",
			'enriched_at'     => "ADD COLUMN enriched_at datetime DEFAULT NULL",
		);
		foreach ( $cols as $name => $ddl ) {
			$have = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(1) FROM information_schema.COLUMNS
				 WHERE table_schema = %s AND table_name = %s AND column_name = %s",
				DB_NAME, $t, $name
			) );
			if ( (int) $have === 0 ) {
				$wpdb->query( "ALTER TABLE {$t} {$ddl}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}
	}

	/**
	 * Add the richer FULLTEXT(subject, snippet, parties_text, gist, body_clean_text) index
	 * ALONGSIDE ft_zib_search (which stays, so the live owner_chat MATCH is untouched here).
	 * Idempotent; requires the new columns to exist first (add_message_columns runs before).
	 */
	public static function ensure_enriched_fulltext(): void {
		global $wpdb;
		$t    = $wpdb->prefix . 'zib_messages';
		$have = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(1) FROM information_schema.STATISTICS
			 WHERE table_schema = %s AND table_name = %s AND index_name = 'ft_zib_all'",
			DB_NAME, $t
		) );
		if ( (int) $have > 0 ) {
			return;
		}
		$wpdb->query( "ALTER TABLE {$t} ADD FULLTEXT KEY ft_zib_all (subject, snippet, parties_text, gist, body_clean_text)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Insert the codified global taxonomy once (idempotent by tag_key). */
	private static function seed_tags(): void {
		global $wpdb;
		$t = $wpdb->prefix . 'zib_tags';
		foreach ( self::SEED_TAGS as $row ) {
			list( $key, $label, $def ) = $row;
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$t} WHERE scope = 'global' AND owner_user_id = 0 AND tag_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$key
			) );
			if ( $exists ) {
				continue;
			}
			$wpdb->insert( $t, array(
				'tag_key'       => $key,
				'label'         => $label,
				'definition'    => $def,
				'scope'         => 'global',
				'owner_user_id' => 0,
				'pii_clean'     => 1,
				'status'        => 'active',
				'source'        => 'seed',
				'created_by'    => 0,
			) );
		}
	}
}
