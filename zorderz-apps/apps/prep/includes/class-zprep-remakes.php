<?php
/**
 * ZPREP_Remakes — manager-entered remake jobs.
 *
 * A remake re-cuts a set of pieces for an existing job, for a stated reason (the customer
 * asked for a different material, a piece was cut wrong, a piece was damaged). It is a
 * SHOP-ONLY fabrication record: it produces cut sheets, is tracked by the cut-state ledger
 * (ZPREP_Cut_State) and can be assigned (ZPREP_Assignments) — but it never creates a billing
 * document, moves a CRM pipeline stage or writes a customer receipt. The only outside write
 * is a read-only note on the parent CRM lead, when the parent is one.
 *
 * A remake carries its own measurements, so tapping it plans directly (no CRM lookup), and
 * the assignee needs no CRM access. Its job key is 'remake:<id>'.
 *
 * Piece kinds come from the Item Engine (any slug the parser uses; 'custom' otherwise);
 * colours are the configured roll colours; the forced roll width is one of the configured
 * widths or 0 (automatic). Reasons are Core defaults a business may relabel or extend
 * through `zprep_remake_reasons`.
 *
 * @package Zorderz\Prep
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZPREP_Remakes {

	const TABLE = 'zprep_remakes';

	const STATUSES = array( 'open', 'cut', 'done', 'void' );

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/** Reason code => label. Codes are Core; labels and extra codes may come from a pack. */
	public static function reasons(): array {
		$base = array(
			'material_change' => __( 'Customer asked for a different material', 'zorderz' ),
			'miscut'          => __( 'Cut in the wrong size or material', 'zorderz' ),
			'damage'          => __( 'Damaged / needs replacement', 'zorderz' ),
			'other'           => __( 'Other', 'zorderz' ),
		);
		$f   = apply_filters( 'zprep_remake_reasons', $base );
		$out = array();
		foreach ( is_array( $f ) ? $f : $base as $k => $v ) {
			$k = sanitize_key( (string) $k );
			if ( '' !== $k && '' !== trim( (string) $v ) ) {
				$out[ $k ] = (string) $v;
			}
		}
		$out['other'] = $out['other'] ?? $base['other'];
		return $out;
	}

	public static function job_key( int $id ): string {
		return 'remake:' . $id;
	}

	public static function id_from_job_key( string $job_key ): int {
		return ( 0 === strpos( $job_key, 'remake:' ) ) ? (int) substr( $job_key, 7 ) : 0;
	}

	public static function migrate(): void {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			parent_job_key VARCHAR(80) NOT NULL DEFAULT '',
			parent_label VARCHAR(191) NOT NULL DEFAULT '',
			customer_label VARCHAR(191) NOT NULL DEFAULT '',
			reason_code VARCHAR(40) NOT NULL DEFAULT 'other',
			reason_text VARCHAR(255) NOT NULL DEFAULT '',
			standard_note TEXT NULL,
			color VARCHAR(32) NOT NULL DEFAULT '',
			force_roll SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			install_date VARCHAR(32) NOT NULL DEFAULT '',
			measurements LONGTEXT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'open',
			PRIMARY KEY  (id),
			KEY idx_created (created_at),
			KEY idx_parent (parent_job_key),
			KEY idx_status (status)
		) {$charset};" );
	}

	/* ================================================================
	 * DECISIONS (pure)
	 * ================================================================ */

	/**
	 * Validate and normalize a remake request. Returns [ 'error' => code ] or [ 'row' => fields ].
	 *
	 * @param array    $args   customer_label, parent_job_key, parent_label, reason_code, reason_text,
	 *                         standard_note, color, force_roll, install_date, pieces (rows).
	 * @param string[] $colors the configured roll colours.
	 * @param int[]    $widths the configured roll widths.
	 * @param string[] $reasons the reason codes.
	 */
	public static function normalize( array $args, array $colors, array $widths, array $reasons ): array {
		$customer = trim( (string) ( $args['customer_label'] ?? '' ) );
		if ( '' === $customer ) {
			return array( 'error' => 'no_customer' );
		}
		$pieces = self::normalize_pieces( $args['pieces'] ?? array(), $colors );
		if ( empty( $pieces ) ) {
			return array( 'error' => 'no_pieces' );
		}
		$reason = (string) ( $args['reason_code'] ?? 'other' );
		if ( ! in_array( $reason, $reasons, true ) ) {
			$reason = 'other';
		}
		$color = strtolower( trim( (string) ( $args['color'] ?? '' ) ) );
		if ( ! in_array( $color, $colors, true ) ) {
			$color = '';
		}
		$roll = (int) ( $args['force_roll'] ?? 0 );
		if ( ! in_array( $roll, array_map( 'intval', $widths ), true ) ) {
			$roll = 0;
		}
		$parent = trim( (string) ( $args['parent_job_key'] ?? '' ) );
		if ( '' !== $parent && ! preg_match( '/^(est|lead):[A-Za-z0-9._-]{1,60}$/', $parent ) ) {
			$parent = '';
		}
		return array(
			'row' => array(
				'parent_job_key' => $parent,
				'parent_label'   => mb_substr( trim( (string) ( $args['parent_label'] ?? '' ) ), 0, 191 ),
				'customer_label' => mb_substr( $customer, 0, 191 ),
				'reason_code'    => $reason,
				'reason_text'    => mb_substr( trim( (string) ( $args['reason_text'] ?? '' ) ), 0, 255 ),
				'standard_note'  => trim( (string) ( $args['standard_note'] ?? '' ) ),
				'color'          => $color,
				'force_roll'     => $roll,
				'install_date'   => mb_substr( trim( (string) ( $args['install_date'] ?? '' ) ), 0, 32 ),
				'pieces'         => $pieces,
			),
		);
	}

	/** Rows with a positive quantity; kind a slug, sizes numbers or '', colour a roll colour or ''. */
	public static function normalize_pieces( $rows, array $colors ): array {
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $v ) {
			if ( ! is_array( $v ) ) {
				continue;
			}
			$qty = max( 0, (int) ( $v['qty'] ?? 0 ) );
			if ( $qty <= 0 ) {
				continue;
			}
			$kind = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) ( $v['kind'] ?? $v['type'] ?? 'custom' ) ) );
			$c    = strtolower( trim( (string) ( $v['color'] ?? '' ) ) );
			$out[] = array(
				'kind'   => '' !== $kind ? $kind : 'custom',
				'qty'    => min( 999, $qty ),
				'width'  => ( isset( $v['width'] ) && is_numeric( $v['width'] ) && (float) $v['width'] > 0 ) ? round( (float) $v['width'], 2 ) : '',
				'height' => ( isset( $v['height'] ) && is_numeric( $v['height'] ) && (float) $v['height'] > 0 ) ? round( (float) $v['height'], 2 ) : '',
				'shape'  => in_array( (string) ( $v['shape'] ?? '' ), array( 'circle', 'rectangle' ), true ) ? (string) $v['shape'] : 'rectangle',
				'color'  => in_array( $c, $colors, true ) ? $c : '',
				'notes'  => mb_substr( trim( (string) ( $v['notes'] ?? '' ) ), 0, 191 ),
			);
		}
		return $out;
	}

	/** Engine measurements for a remake row: each piece gets the row's colour when it has none. */
	public static function measurements( array $row, string $fallback_color ): array {
		$pieces = json_decode( (string) ( $row['measurements'] ?? '[]' ), true );
		if ( ! is_array( $pieces ) ) {
			return array();
		}
		$job_color = strtolower( (string) ( $row['color'] ?? '' ) );
		$out       = array();
		foreach ( $pieces as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$color = strtolower( (string) ( $p['color'] ?? '' ) );
			if ( '' === $color ) {
				$color = '' !== $job_color ? $job_color : $fallback_color;
			}
			$out[] = array(
				'kind'      => (string) ( $p['kind'] ?? 'custom' ),
				'qty'       => max( 1, (int) ( $p['qty'] ?? 1 ) ),
				'width_in'  => ( '' !== ( $p['width'] ?? '' ) ) ? (float) $p['width'] : null,
				'height_in' => ( '' !== ( $p['height'] ?? '' ) ) ? (float) $p['height'] : null,
				'shape'     => (string) ( $p['shape'] ?? 'rectangle' ),
				'color'     => $color,
				'notes'     => (string) ( $p['notes'] ?? '' ),
			);
		}
		return $out;
	}

	/* ================================================================
	 * STORAGE
	 * ================================================================ */

	/** @return array|WP_Error the stored row. */
	public static function create( array $args, int $actor ) {
		if ( ! ZPREP_Dashboard::is_manager( $actor ) ) {
			return new WP_Error( 'zprep_remake_forbidden', __( 'Only a manager can add a remake.', 'zorderz' ) );
		}
		$n = self::normalize(
			$args,
			ZPREP_Settings::roll_colors(),
			array_map( static function ( $r ) { return (int) $r['width_in']; }, ZPREP_Settings::rolls() ),
			array_keys( self::reasons() )
		);
		if ( isset( $n['error'] ) ) {
			$msg = 'no_customer' === $n['error']
				? __( 'A customer or job name is required.', 'zorderz' )
				: __( 'Add at least one row (quantity and size).', 'zorderz' );
			return new WP_Error( 'zprep_remake_' . $n['error'], $msg );
		}
		$r = $n['row'];
		global $wpdb;
		$ok = $wpdb->insert( self::table_name(), array(
			'created_at'     => current_time( 'mysql' ),
			'created_by'     => $actor,
			'parent_job_key' => $r['parent_job_key'],
			'parent_label'   => sanitize_text_field( $r['parent_label'] ),
			'customer_label' => sanitize_text_field( $r['customer_label'] ),
			'reason_code'    => $r['reason_code'],
			'reason_text'    => sanitize_text_field( $r['reason_text'] ),
			'standard_note'  => sanitize_textarea_field( $r['standard_note'] ),
			'color'          => $r['color'],
			'force_roll'     => $r['force_roll'],
			'install_date'   => sanitize_text_field( $r['install_date'] ),
			'measurements'   => wp_json_encode( $r['pieces'] ),
			'status'         => 'open',
		), array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ) );
		if ( ! $ok ) {
			return new WP_Error( 'zprep_remake_db', __( 'Could not save the remake.', 'zorderz' ) );
		}
		$id  = (int) $wpdb->insert_id;
		$row = self::get( $id );
		self::post_parent_note( $row );
		ZPREP_Settings::disposition( 'remake_created', array( 'remake_id' => $id, 'job_key' => self::job_key( $id ), 'parent' => $r['parent_job_key'], 'reason' => $r['reason_code'] ) );
		return $row;
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . self::table_name() . '` WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a class constant; the id is a placeholder.
		return $row ?: null;
	}

	public static function set_status( int $id, string $status ): bool {
		if ( $id <= 0 || ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}
		global $wpdb;
		return false !== $wpdb->update( self::table_name(), array( 'status' => $status ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
	}

	/** Every remake that is not void, newest first. */
	public static function list_open(): array {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM `' . self::table_name() . "` WHERE status <> 'void' ORDER BY id DESC LIMIT 200", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a class constant; no input in the query.
		return $rows ?: array();
	}

	public static function for_job_keys( array $keys ): array {
		$ids = array();
		foreach ( $keys as $k ) {
			$id = self::id_from_job_key( (string) $k );
			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}
		if ( empty( $ids ) ) {
			return array();
		}
		global $wpdb;
		$ph   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . self::table_name() . "` WHERE id IN ({$ph}) AND status <> 'void' ORDER BY id DESC", ...array_values( $ids ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a class constant; every IN-list item is a placeholder.
		return $rows ?: array();
	}

	/** The queue cards for $me's remakes: every open one for a manager, else the ones assigned to them. */
	public static function cards_for( int $me, bool $manager ): array {
		if ( $me <= 0 ) {
			return array();
		}
		if ( $manager ) {
			$rows = array_filter( self::list_open(), static function ( $r ) { return in_array( (string) $r['status'], array( 'open', 'cut' ), true ); } );
		} elseif ( class_exists( 'ZPREP_Assignments' ) ) {
			$rows = self::for_job_keys( ZPREP_Assignments::job_keys_for( $me ) );
		} else {
			$rows = array();
		}
		return array_values( array_map( array( __CLASS__, 'card' ), $rows ) );
	}

	/** A remake as a queue card. */
	public static function card( array $row ): array {
		$id      = (int) $row['id'];
		$reasons = self::reasons();
		$a       = class_exists( 'ZPREP_Assignments' ) ? ZPREP_Assignments::assignee_of( self::job_key( $id ) ) : 0;
		$code    = (string) ( $row['reason_code'] ?? 'other' );
		return array(
			'kind'           => 'remake',
			'remake_id'      => $id,
			'job_key'        => self::job_key( $id ),
			'source_job'     => self::job_key( $id ),
			'title'          => (string) $row['customer_label'],
			'customer'       => (string) $row['customer_label'],
			'parent_label'   => (string) ( $row['parent_label'] ?? '' ),
			'parent_job_key' => (string) ( $row['parent_job_key'] ?? '' ),
			'reason_code'    => $code,
			'reason_label'   => $reasons[ $code ] ?? $reasons['other'],
			'reason_text'    => (string) ( $row['reason_text'] ?? '' ),
			'standard_note'  => (string) ( $row['standard_note'] ?? '' ),
			'color'          => (string) ( $row['color'] ?? '' ),
			'force_roll'     => (int) ( $row['force_roll'] ?? 0 ),
			'install_date'   => (string) ( $row['install_date'] ?? '' ),
			'status'         => (string) ( $row['status'] ?? 'open' ),
			'assignee_id'    => $a,
			'assignee_name'  => $a > 0 ? ZPREP_Assignments::display_name( $a ) : '',
			'measurements'   => self::measurements( $row, ZPREP_Settings::default_color() ),
		);
	}

	/** Post a read-only note on the parent CRM lead (best effort; never blocks the remake). */
	private static function post_parent_note( ?array $row ): void {
		if ( ! $row ) {
			return;
		}
		$parent = (string) ( $row['parent_job_key'] ?? '' );
		if ( 0 !== strpos( $parent, 'lead:' ) || ! class_exists( 'ZPREP_Crm' ) ) {
			return;
		}
		$lead_id = (int) substr( $parent, 5 );
		if ( $lead_id <= 0 ) {
			return;
		}
		try {
			$crm = new ZPREP_Crm();
			if ( $crm->is_ready() ) {
				$crm->add_completion_note( $lead_id, self::note_body( $row ) );
			}
		} catch ( \Throwable $e ) {
			error_log( 'ZPREP remake parent note failed: ' . $e->getMessage() );
		}
	}

	/** The parent-note text. */
	public static function note_body( array $row ): string {
		$reasons = self::reasons();
		$reason  = $reasons[ (string) ( $row['reason_code'] ?? 'other' ) ] ?? $reasons['other'];
		$rt      = trim( (string) ( $row['reason_text'] ?? '' ) );
		$std     = trim( (string) ( $row['standard_note'] ?? '' ) );
		$lines   = array( __( 'REMAKE added in Prep: a new set of pieces to replace an earlier set.', 'zorderz' ) );
		$lines[] = __( 'Reason:', 'zorderz' ) . ' ' . $reason . ( '' !== $rt ? ' — ' . $rt : '' );
		if ( '' !== $std ) {
			$lines[] = $std;
		}
		$lines[] = __( 'Shop-only fabrication record; no billing change.', 'zorderz' );
		return implode( "\n", $lines );
	}
}
