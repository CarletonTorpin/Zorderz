<?php
/**
 * The hourly invoice feed to the Charts hub.
 *
 * Keeps the hub's data current between the business's own billing exports.
 *
 * What it does:
 *   1. The site's billing ledger (the analytics app's) reads every new or changed invoice,
 *      lines included, each hour, and hands each page to the `zana_ledger_fb_items` action.
 *      on_items() turns each invoice into the row the hub needs (the columns of the billing
 *      system's own invoice export) and queues it in the zch_feed table. No extra billing call.
 *   2. run(), on the hourly Charts job, sends what is queued to the hub as op put_invoices, in
 *      parts of one batch (the hub takes at most 64 KB a request), as the scheduled job (caller
 *      "cron": it can hand data over, never read back). Rows are marked sent only when the hub
 *      took the whole batch.
 *   3. The same table keeps the billing system's own link from an invoice to the estimate it
 *      came from (`estimateid`). ZCH_Counts uses it to find each job's CRM lead, and to name
 *      that lead's source for the hub.
 *
 * What leaves the site: what the business's own billing export already holds (client name,
 * invoice number, dates, status, line text and amounts), sealed. The reference hub keeps it in
 * "shadow" until its own feed-check shows it reads exactly like the export.
 *
 * @package Zorderz\Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCH_Feed {
	const STATE         = 'zch_feed_state';
	const LEDGER_HOOK   = 'zana_ledger_fb_items';
	const LEDGER_STATE  = 'zana_ledger_state';
	const BUDGET_S      = 25;              // inside the host's request wall.
	const PART_BYTES    = 30000;           // about 41 KB sealed: under the hub's 64 KB.
	const MAX_PER_PART  = 400;             // the hub's own cap.
	const MAX_PARTS     = 40;              // per run; the rest goes next hour.
	const INVOICE_BYTES = 36000;           // one invoice must fit in a part.
	const SELECT_LIMIT  = 4000;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'zch_feed';
	}

	public static function init() {
		add_action( self::LEDGER_HOOK, array( __CLASS__, 'on_items' ), 10, 2 );
		// Before the counts run (priority 10), so a new estimate link is already there.
		add_action( ZCH_Counts::HOOK, array( __CLASS__, 'run' ), 5 );
	}

	/** dbDelta for the queue (called from ZCH_Store::install). */
	public static function install_sql( $charset ) {
		$t = self::table();
		return "CREATE TABLE $t (
			invoice_id BIGINT UNSIGNED NOT NULL,
			invoice_number VARCHAR(40) NOT NULL DEFAULT '',
			estimate_id VARCHAR(20) NOT NULL DEFAULT '',
			vis_state TINYINT NOT NULL DEFAULT 0,
			fb_updated VARCHAR(19) NOT NULL DEFAULT '',
			row_json MEDIUMTEXT NOT NULL,
			row_hash CHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
			queued_at INT UNSIGNED NOT NULL DEFAULT 0,
			sent_at INT UNSIGNED NULL DEFAULT NULL,
			PRIMARY KEY  (invoice_id),
			KEY estimate_id (estimate_id),
			KEY sent_at (sent_at)
		) $charset;";
	}

	public static function state() {
		$s = get_option( self::STATE );
		$s = is_array( $s ) ? $s : array();
		return array_merge(
			array(
				'last_run'   => 0,
				'last_sent'  => 0,
				'sent_total' => 0,
				'result'     => '',
				'queued'     => 0,
				'skipped'    => 0,
				'received'   => 0,
				'mode'       => '',
				'last_beat'  => 0,
				'beat'       => '',
			),
			$s
		);
	}

	private static function save( $s ) {
		update_option( self::STATE, $s, false );
		return $s;
	}

	private static function table_ok() {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
	}

	/* ── Mapping ────────────────────────────────────────────────────────── */

	/** A money or number value as a plain decimal string the hub accepts. */
	public static function num( $v ) {
		if ( is_array( $v ) ) {
			$v = $v['amount'] ?? 0;
		}
		$s = trim( (string) $v );
		if ( preg_match( '/^-?\d{1,10}(\.\d{1,8})?$/', $s ) ) {
			return $s;
		}
		if ( ! is_numeric( $s ) ) {
			return '0';
		}
		$f = round( (float) $s, 8 );
		if ( abs( $f ) >= 1e10 ) {
			return '0';
		}
		$out = rtrim( rtrim( sprintf( '%.8F', $f ), '0' ), '.' );
		return ( '' === $out || '-0' === $out ) ? '0' : $out;
	}

	private static function text( $v, $n, $multiline = false ) {
		$v = is_scalar( $v ) ? (string) $v : '';
		$v = str_replace( array( "\r\n", "\r" ), "\n", $v );
		$v = preg_replace( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $v );
		if ( ! $multiline ) {
			$v = preg_replace( '/\s+/', ' ', $v );
		}
		$v = trim( $v );
		if ( function_exists( 'mb_substr' ) ) {
			return trim( mb_substr( $v, 0, $n, 'UTF-8' ) );
		}
		return trim( substr( $v, 0, $n ) );
	}

	private static function date10( $v ) {
		$v = substr( trim( (string) $v ), 0, 10 );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
	}

	/** The billing system's status word, as its own export writes it. */
	public static function status_word( $inv ) {
		$map = array( 'auto-paid' => 'paid', 'resent' => 'sent', 'outstanding' => 'sent' );
		foreach ( array( 'v3_status', 'display_status' ) as $k ) {
			if ( isset( $inv[ $k ] ) && is_string( $inv[ $k ] ) && '' !== trim( $inv[ $k ] ) ) {
				$w = strtolower( trim( $inv[ $k ] ) );
				$w = $map[ $w ] ?? $w;
				return preg_match( '/^[a-z][a-z -]{0,19}$/', $w ) ? $w : 'sent';
			}
		}
		$codes = array( 1 => 'draft', 2 => 'sent', 3 => 'viewed', 4 => 'paid', 5 => 'paid', 8 => 'partial' );
		return $codes[ (int) ( $inv['status'] ?? 2 ) ] ?? 'sent';
	}

	private static function client( $inv ) {
		foreach ( array( 'current_organization', 'organization' ) as $k ) {
			if ( ! empty( $inv[ $k ] ) && is_string( $inv[ $k ] ) ) {
				return trim( $inv[ $k ] );
			}
		}
		return trim( (string) ( $inv['fname'] ?? '' ) . ' ' . (string) ( $inv['lname'] ?? '' ) );
	}

	/**
	 * One billing invoice -> the hub's row, or null (no id, an odd number, or too big to send
	 * even with short descriptions). Every value is cut to what the hub accepts, so one long note
	 * can never get a whole part refused.
	 */
	public static function map_invoice( $inv ) {
		if ( ! is_array( $inv ) ) {
			return null;
		}
		$id     = (int) ( $inv['invoiceid'] ?? $inv['id'] ?? 0 );
		$no     = trim( (string) ( $inv['invoice_number'] ?? '' ) );
		$issued = self::date10( $inv['create_date'] ?? '' );
		if ( $id <= 0 || ! preg_match( '/^[A-Za-z0-9-]{1,20}$/', $no ) || '' === $issued ) {
			return null;
		}
		$vis = (int) ( $inv['vis_state'] ?? 0 );
		$upd = trim( (string) ( $inv['updated'] ?? '' ) );
		$upd = preg_match( '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $upd ) ? str_replace( 'T', ' ', substr( $upd, 0, 19 ) ) : gmdate( 'Y-m-d H:i:s' );
		$est = trim( (string) ( $inv['estimateid'] ?? '' ) );
		$est = preg_match( '/^[1-9]\d{0,14}$/', $est ) ? $est : '';      // what the hub accepts.
		$lines = array();
		foreach ( array_slice( (array) ( $inv['lines'] ?? array() ), 0, 300 ) as $ln ) {
			if ( ! is_array( $ln ) ) {
				continue;
			}
			$lines[] = array(
				'n' => self::text( $ln['name'] ?? '', 1000, true ),
				'd' => self::text( $ln['description'] ?? '', 5000, true ),
				'r' => self::num( $ln['unit_cost'] ?? 0 ),
				'q' => self::num( $ln['qty'] ?? 0 ),
				't' => self::num( $ln['amount'] ?? 0 ),
			);
		}
		$row  = array(
			'n' => $no,
			'id' => $id,
			'c' => self::text( self::client( $inv ), 200 ),
			'i' => $issued,
			'd' => self::date10( $inv['due_date'] ?? '' ),
			'p' => self::date10( $inv['date_paid'] ?? '' ),
			's' => self::status_word( $inv ),
			'v' => in_array( $vis, array( 0, 1, 2 ), true ) ? $vis : 0,
			'u' => $upd,
			'e' => $est,
			'L' => $lines,
		);
		$json = zch_json( $row );
		if ( strlen( $json ) > self::INVOICE_BYTES ) {
			foreach ( $row['L'] as &$l ) {
				$l['d'] = self::text( $l['d'], 300, true );
			}
			unset( $l );
			$json = zch_json( $row );
			if ( strlen( $json ) > self::INVOICE_BYTES ) {
				return null;
			}
		}
		return $row;
	}

	/* ── The queue ──────────────────────────────────────────────────────── */

	/** The ledger's listener: queue changed invoices. Never throws. */
	public static function on_items( $kind, $items ) {
		if ( 'invoices' !== $kind || ! is_array( $items ) || ! $items ) {
			return 0;
		}
		try {
			if ( ! self::table_ok() ) {
				return 0;
			}
			global $wpdb;
			$t       = self::table();
			$now     = time();
			$n       = 0;
			$skipped = 0;
			foreach ( $items as $inv ) {
				$row = self::map_invoice( $inv );
				if ( null === $row ) {
					$skipped++;
					continue;
				}
				$json = zch_json( $row );
				$hash = sha1( $json );
				// A copy identical to what is queued (or was sent) keeps its place and its "sent";
				// anything different is queued again. Compared by a binary hash (the table's
				// collation would call two spellings that differ only in case equal). Assignments
				// run left to right, so sent_at and queued_at see the OLD hash.
				$wpdb->query(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is self::table(): $wpdb->prefix plus a fixed table name
						"INSERT INTO $t (invoice_id, invoice_number, estimate_id, vis_state, fb_updated, row_json, row_hash, queued_at, sent_at)
						 VALUES (%d, %s, %s, %d, %s, %s, %s, %d, NULL)
						 ON DUPLICATE KEY UPDATE
						   sent_at = IF(row_hash = VALUES(row_hash), sent_at, NULL),
						   queued_at = IF(row_hash = VALUES(row_hash), queued_at, VALUES(queued_at)),
						   invoice_number = VALUES(invoice_number), estimate_id = VALUES(estimate_id),
						   vis_state = VALUES(vis_state), fb_updated = VALUES(fb_updated), row_json = VALUES(row_json),
						   row_hash = VALUES(row_hash)", // phpcs:ignore WordPress.DB.PreparedSQL -- fixed table name.
						$row['id'],
						$row['n'],
						$row['e'],
						$row['v'],
						$row['u'],
						$json,
						$hash,
						$now
					)
				);
				$n++;
			}
			$s             = self::state();
			$s['received'] = (int) $s['received'] + $n;
			$s['skipped']  = (int) $s['skipped'] + $skipped;
			self::save( $s );
			return $n;
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/** Split rows into parts the hub accepts: at most PART_BYTES of JSON and MAX_PER_PART rows each. */
	public static function parts( array $rows ) {
		$parts = array();
		$cur   = array();
		$bytes = 2;
		foreach ( $rows as $r ) {
			$len = strlen( zch_json( $r ) ) + 1;
			if ( $cur && ( $bytes + $len > self::PART_BYTES || count( $cur ) >= self::MAX_PER_PART ) ) {
				$parts[] = $cur;
				$cur     = array();
				$bytes   = 2;
			}
			$cur[]  = $r;
			$bytes += $len;
		}
		if ( $cur ) {
			$parts[] = $cur;
		}
		return $parts;
	}

	private static function cron_access( $op ) {
		return array( 'caller' => 'cron', 'ops' => array( 'health', $op ), 'scope' => null, 'kiosk' => false );
	}

	/** One hourly pass: send what is queued, as one batch. $client and $now injectable (tests). */
	public static function run( $client = null, $now = null ) {
		$now           = $now ? $now : time();
		$started       = microtime( true );
		$s             = self::state();
		$s['last_run'] = $now;
		if ( ! self::table_ok() ) {
			$s['result'] = __( 'queue table missing (save Charts settings once to create it)', 'zorderz' );
			return self::save( $s );
		}
		global $wpdb;
		$t           = self::table();
		$s['queued'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE sent_at IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL -- fixed table name.
		if ( ! $s['queued'] ) {
			$s['result'] = __( 'nothing new to send', 'zorderz' );
			return self::save( self::with_beat( $s, $client ? $client : ZCH_Hub_Client::from_config(), $now ) );
		}
		$client = $client ? $client : ZCH_Hub_Client::from_config();
		if ( ! $client ) {
			$s['result'] = __( 'no hub connected', 'zorderz' );
			return self::save( $s );
		}
		$rows    = $wpdb->get_results(
			$wpdb->prepare( "SELECT invoice_id, row_json, row_hash FROM $t WHERE sent_at IS NULL AND queued_at <= %d ORDER BY queued_at, invoice_id LIMIT %d", $now, self::SELECT_LIMIT ), // phpcs:ignore WordPress.DB.PreparedSQL -- fixed table name.
			ARRAY_A
		);
		$decoded = array();
		$ids     = array();
		foreach ( (array) $rows as $r ) {
			$j = json_decode( (string) $r['row_json'], true );
			if ( is_array( $j ) ) {
				$decoded[] = $j;
				$ids[]     = array( (int) $r['invoice_id'], (string) $r['row_hash'] );
			}
		}
		$parts    = array_slice( self::parts( $decoded ), 0, self::MAX_PARTS );
		$sent_ids = array();
		$i        = 0;
		foreach ( $parts as $p ) {
			foreach ( $p as $unused ) {
				$sent_ids[] = $ids[ $i++ ];
			}
		}
		$batch = bin2hex( random_bytes( 8 ) );
		$reply = array( 'ok' => false );
		$today = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		foreach ( $parts as $k => $p ) {
			if ( microtime( true ) - $started > self::BUDGET_S ) {
				$reply = array( 'ok' => false, 'error' => array( 'code' => 'out of time; the rest goes next hour' ) );
				break;
			}
			$reply = $client->call(
				'put_invoices',
				array(
					'batch'    => $batch,
					'part'     => $k + 1,
					'parts'    => count( $parts ),
					'invoices' => $p,
					'access'   => self::cron_access( 'put_invoices' ),
				),
				'wp_cron',
				$today
			);
			if ( empty( $reply['ok'] ) ) {
				break;
			}
		}
		if ( ! empty( $reply['ok'] ) && empty( $reply['data']['waiting'] ) ) {
			foreach ( array_chunk( $sent_ids, 500 ) as $chunk ) {
				// Only the copy that was sent: a row the ledger replaced while sending has a new
				// hash and stays queued.
				$pairs = array();
				foreach ( $chunk as $p ) {
					$pairs[] = $wpdb->prepare( '(%d, %s)', $p[0], preg_match( '/^[0-9a-f]{40}$/', $p[1] ) ? $p[1] : 'x' );
				}
				$wpdb->query( $wpdb->prepare( "UPDATE $t SET sent_at = %d WHERE sent_at IS NULL AND (invoice_id, row_hash) IN (" . implode( ',', $pairs ) . ')', $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- each pair is prepared above.
			}
			$d               = $reply['data'] ?? array();
			$s['last_sent']  = $now;
			$s['sent_total'] = (int) $s['sent_total'] + count( $sent_ids );
			$s['mode']       = (string) ( $d['mode'] ?? '' );
			/* translators: 1: invoices sent, 2: parts, 3: invoices the hub holds, 4: the hub's feed mode */
			$s['result'] = sprintf( __( '%1$d invoice(s) sent in %2$d part(s); the hub holds %3$d (mode: %4$s)', 'zorderz' ), count( $sent_ids ), count( $parts ), (int) ( $d['feed'] ?? 0 ), '' !== $s['mode'] ? $s['mode'] : '?' );
		} else {
			$s['result'] = __( 'not sent:', 'zorderz' ) . ' ' . (string) ( $reply['error']['code'] ?? 'hub unavailable' );
		}
		$s['queued'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE sent_at IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL -- fixed table name.
		return self::save( self::with_beat( $s, $client, $now ) );
	}

	/**
	 * One small call an hour whether or not there was anything to send, so the hub can tell a
	 * quiet day from a stopped job. It carries when the billing ledger last read the billing
	 * system without an error (its own UTC stamp), whether its last pass failed, and how many
	 * invoices are waiting here. No invoice data, no error text.
	 */
	public static function heartbeat() {
		$ledger = get_option( self::LEDGER_STATE );
		$inv    = ( is_array( $ledger ) && isset( $ledger['invoices'] ) && is_array( $ledger['invoices'] ) ) ? $ledger['invoices'] : array();
		$ok     = ( isset( $inv['last_ok'] ) && is_string( $inv['last_ok'] )
			&& preg_match( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $inv['last_ok'] ) ) ? $inv['last_ok'] : '';
		return array( 'ledger_ok' => $ok, 'ledger_failing' => ! empty( $inv['last_error'] ) );
	}

	private static function with_beat( $s, $client, $now ) {
		if ( ! $client ) {
			return $s;
		}
		$today = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		$reply = $client->call(
			'put_invoices',
			array(
				'heartbeat' => self::heartbeat() + array( 'queued' => (int) $s['queued'] ),
				'access'    => self::cron_access( 'put_invoices' ),
			),
			'wp_cron',
			$today
		);
		$s['beat'] = ! empty( $reply['ok'] ) ? 'ok' : __( 'not taken', 'zorderz' ) . ' (' . (string) ( $reply['error']['code'] ?? 'hub unavailable' ) . ')';
		if ( ! empty( $reply['ok'] ) ) {
			$s['last_beat'] = $now;
		}
		return $s;
	}

	/* ── The estimate link ──────────────────────────────────────────────── */

	/** {billing estimate id: invoice number} for the given estimate ids (deleted invoices left out). */
	public static function invoices_for_estimates( array $estimate_ids ) {
		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'strval', $estimate_ids ),
					function ( $v ) {
						return (bool) preg_match( '/^\d{1,15}$/', $v );
					}
				)
			)
		);
		if ( ! $ids || ! self::table_ok() ) {
			return array();
		}
		global $wpdb;
		$t   = self::table();
		$out = array();
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$ph   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT estimate_id, invoice_number FROM $t WHERE vis_state <> 1 AND estimate_id IN ($ph) ORDER BY invoice_id", $chunk ), // phpcs:ignore WordPress.DB.PreparedSQL -- placeholders built above.
				ARRAY_A
			);
			foreach ( (array) $rows as $r ) {
				$out[ (string) $r['estimate_id'] ] = (string) $r['invoice_number'];
			}
		}
		return $out;
	}

	/** Every {invoice number: estimate id} the feed knows (deleted left out). */
	public static function estimate_links() {
		if ( ! self::table_ok() ) {
			return array();
		}
		global $wpdb;
		$t    = self::table();
		$rows = $wpdb->get_results( "SELECT invoice_number, estimate_id FROM $t WHERE vis_state <> 1 AND estimate_id <> '' LIMIT 50000", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- fixed table name.
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ (string) $r['invoice_number'] ] = (string) $r['estimate_id'];
		}
		return $out;
	}

	/**
	 * {invoice number: CRM lead source name}: invoice -> its estimate (the billing system's link)
	 * -> the Estimates app's row -> its CRM lead -> the billing ledger's source for that lead.
	 * Names only; the hub keeps only the group.
	 */
	public static function lead_names() {
		global $wpdb;
		$links = self::estimate_links();
		if ( ! $links ) {
			return array();
		}
		$est_t  = $wpdb->prefix . 'zest_estimates';
		$lead_t = class_exists( 'ZCH_Ledger' ) ? ZCH_Ledger::table( 'lead' ) : null;
		if ( ! $lead_t || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $est_t ) ) !== $est_t ) {
			return array();
		}
		$lead_of = array();
		foreach ( array_chunk( array_values( array_unique( $links ) ), 500 ) as $chunk ) {
			$ph   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT billing_doc_id, crm_lead_id FROM $est_t WHERE billing_doc_id IN ($ph) AND crm_lead_id <> '' AND crm_lead_id <> '0'", $chunk ), // phpcs:ignore WordPress.DB.PreparedSQL -- placeholders built above.
				ARRAY_A
			);
			foreach ( (array) $rows as $r ) {
				$lead = (int) $r['crm_lead_id'];
				if ( $lead > 0 ) {
					$lead_of[ (string) $r['billing_doc_id'] ] = $lead;
				}
			}
		}
		if ( ! $lead_of ) {
			return array();
		}
		$src_of = array();
		foreach ( array_chunk( array_values( array_unique( $lead_of ) ), 500 ) as $chunk ) {
			$in   = implode( ',', array_map( 'intval', $chunk ) );
			$rows = $wpdb->get_results( "SELECT lead_id, source FROM $lead_t WHERE lead_id IN ($in) AND source <> ''", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- integers only.
			foreach ( (array) $rows as $r ) {
				$src_of[ (int) $r['lead_id'] ] = (string) $r['source'];
			}
		}
		$out = array();
		foreach ( $links as $inv => $est ) {
			$lead = $lead_of[ $est ] ?? 0;
			$name = $lead ? trim( preg_replace( '/\s+/', ' ', (string) ( $src_of[ $lead ] ?? '' ) ) ) : '';
			$key  = ltrim( (string) $inv, '0' );
			if ( '' === $name || '' === $key || ! preg_match( '/^[A-Za-z0-9-]{1,20}$/', $key ) ) {
				continue;
			}
			$out[ $key ] = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 120 ) : substr( $name, 0, 120 );
		}
		ksort( $out );
		return $out;
	}

	/* ── The settings page ──────────────────────────────────────────────── */

	public static function status_html() {
		$s     = self::state();
		$when  = function ( $t ) {
			return $t ? esc_html( wp_date( 'M j, g:i a', (int) $t ) ) : esc_html__( 'never', 'zorderz' );
		};
		$links = 0;
		$held  = 0;
		if ( self::table_ok() ) {
			global $wpdb;
			$t     = self::table();
			$held  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" ); // phpcs:ignore WordPress.DB.PreparedSQL -- fixed table name.
			$links = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE estimate_id <> '' AND vis_state <> 1" ); // phpcs:ignore WordPress.DB.PreparedSQL -- fixed table name.
		}
		$ledger = class_exists( 'ZCH_Ledger' ) && ZCH_Ledger::table( 'invoice' );
		return '<p><strong>' . esc_html__( 'Live invoice feed', 'zorderz' ) . '</strong> ' . esc_html__( '(new and changed invoices from the billing ledger\'s hourly pass):', 'zorderz' ) . ' '
			. ( $ledger ? '' : '<strong>' . esc_html__( 'no billing ledger on this site yet', 'zorderz' ) . '</strong>. ' )
			/* translators: 1: invoices seen, 2: waiting to send, 3: linked to an estimate */
			. esc_html( sprintf( __( '%1$d invoices seen, %2$d waiting to send, %3$d linked to the estimate they came from.', 'zorderz' ), $held, (int) $s['queued'], $links ) )
			. ' ' . esc_html__( 'Last sent', 'zorderz' ) . ' ' . $when( $s['last_sent'] )
			. ( '' !== $s['result'] ? ' (' . esc_html( $s['result'] ) . ')' : '' ) . '.'
			/* translators: %d: invoices that could not be read */
			. ( (int) $s['skipped'] ? ' ' . esc_html( sprintf( __( '%d invoice(s) could not be read and were left out.', 'zorderz' ), (int) $s['skipped'] ) ) : '' )
			. ' ' . esc_html__( 'Hourly check-in with the hub:', 'zorderz' ) . ' ' . $when( $s['last_beat'] )
			. ( '' !== $s['beat'] && 'ok' !== $s['beat'] ? ' (' . esc_html( $s['beat'] ) . ')' : '' ) . '.'
			. '</p>';
	}
}
