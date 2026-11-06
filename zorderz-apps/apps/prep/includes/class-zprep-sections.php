<?php
/**
 * ZPREP_Sections — field-measured panels for an area the estimate sold as a run.
 *
 * An estimate line can be a correct sale and still not be a cut piece: forty feet of coverage sold as an area, whose
 * division into panels is a fact of the site that no note records and no rule can derive. Prep does not pretend to
 * plan it (ZPREP_Engine prints it as a run that needs a field measurement). What Prep does is keep the panels someone
 * standing at the site reads off a tape, and turn them into a real cut sheet: each panel a `section`, all of a run's
 * panels on one continuous pull, in the order they were measured.
 *
 * IDENTITY. A section belongs to a job through Prep's one job key (ZPREP_Cut_State::resolve_job_key), so a job reached
 * by its CRM lead and by its estimate number keeps one set of sections.
 *
 * STATUS NEVER HOLDS "cut". This row says whether the area has been MEASURED. Whether a panel has been cut is the cut
 * ledger's alone (ZPREP_Cut_State): two columns meaning "done" is how a job ends up finished and not at once.
 *
 * What a run is made of (its catalog kind and colour) comes from the job's own line and the catalog's roll colours;
 * the panels are this table. The tolerance for measuring over the quoted run is a setting (Core default 6 inches).
 *
 * @package Zorderz\Prep
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZPREP_Sections {

	const TABLE = 'zprep_sections';

	const STATUS_DRAFT    = 'draft';     // started, not yet a usable set
	const STATUS_MEASURED = 'measured';  // panels recorded; cuttable

	/** A section holds at most this many panels. */
	const MAX_PANELS = 120;

	/** How far over the quoted run a measurement may go before it is a warning rather than a stop (inches). */
	const GRACE_DEFAULT = 6.0;

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function migrate(): void {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE {$table} (
			id             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at     DATETIME        NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at     DATETIME        NOT NULL DEFAULT '0000-00-00 00:00:00',
			created_by     BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			job_key        VARCHAR(80)     NOT NULL DEFAULT '',
			lead_id        BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			source_job     VARCHAR(64)     NOT NULL DEFAULT '',
			name           VARCHAR(128)    NOT NULL DEFAULT '',
			kind           VARCHAR(64)     NOT NULL DEFAULT '',
			status         VARCHAR(16)     NOT NULL DEFAULT 'draft',
			color          VARCHAR(32)     NOT NULL DEFAULT '',
			run_length_in  DECIMAL(8,2)    NULL DEFAULT NULL,
			run_height_in  DECIMAL(6,2)    NULL DEFAULT NULL,
			panels         LONGTEXT        NULL,
			notes          TEXT            NULL,
			PRIMARY KEY  (id),
			KEY idx_key (job_key),
			KEY idx_lead (lead_id),
			KEY idx_job (source_job)
		) {$charset};" );
	}

	/* ================================================================
	 * PANELS (pure)
	 * ================================================================ */

	/**
	 * A submitted panel list, cleaned. Order is kept on purpose: it is the order of the panels along the site, and the
	 * printed crosscut list reads in that order. Each: { h (across the roll), w (along it), qty, label, note }. PURE.
	 */
	public static function clean_panels( $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$h = isset( $p['h'] ) ? (float) $p['h'] : 0.0;
			$w = isset( $p['w'] ) ? (float) $p['w'] : 0.0;
			if ( $h <= 0 || $w <= 0 || $h > 10000 || $w > 100000 ) {
				continue;
			}
			$out[] = array(
				'h'     => round( $h, 2 ),
				'w'     => round( $w, 2 ),
				'qty'   => min( 999, max( 1, (int) ( $p['qty'] ?? 1 ) ) ),
				'label' => substr( trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', ' ', (string) ( $p['label'] ?? '' ) ) ), 0, 64 ),
				'note'  => substr( trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', ' ', (string) ( $p['note'] ?? '' ) ) ), 0, 191 ),
			);
			if ( count( $out ) >= self::MAX_PANELS ) {
				break;
			}
		}
		return $out;
	}

	/** Total length (inches) the panels cover. PURE. */
	public static function measured_in( array $panels ): float {
		$t = 0.0;
		foreach ( $panels as $p ) {
			$t += (float) $p['w'] * max( 1, (int) $p['qty'] );
		}
		return round( $t, 2 );
	}

	/**
	 * How a field measurement compares with the run that was quoted (the material that went out). PURE.
	 *   ok    measured <= quoted                  enough on hand
	 *   warn  quoted < measured <= quoted + grace a few inches over: the ordinary loss of many crosscuts; go ahead
	 *   over  measured > quoted + grace           more than was taken out: check the stock before cutting
	 *   none  nothing quoted to measure against
	 *
	 * @return array{level:string,over_in:float,quoted_in:float,measured_in:float,grace_in:float,remaining_in:float}
	 */
	public static function tolerance( float $measured_in, float $quoted_in, ?float $grace_in = null ): array {
		$grace = null !== $grace_in ? max( 0.0, $grace_in ) : self::grace_in();
		if ( $quoted_in <= 0 ) {
			return array( 'level' => 'none', 'over_in' => 0.0, 'quoted_in' => 0.0, 'measured_in' => round( $measured_in, 2 ), 'grace_in' => $grace, 'remaining_in' => 0.0 );
		}
		$over = round( $measured_in - $quoted_in, 2 );
		if ( $over <= 0.0 ) {
			$level = 'ok';
		} elseif ( $over <= $grace ) {
			$level = 'warn';
		} else {
			$level = 'over';
		}
		return array(
			'level'        => $level,
			'over_in'      => max( 0.0, $over ),
			'quoted_in'    => round( $quoted_in, 2 ),
			'measured_in'  => round( $measured_in, 2 ),
			'grace_in'     => $grace,
			'remaining_in' => round( max( 0.0, $quoted_in - $measured_in ), 2 ),
		);
	}

	/** The site's tolerance (Prep settings `section_overage_grace_in`, filter `zprep_section_grace_in`), else 6". */
	public static function grace_in(): float {
		$g = class_exists( 'ZPREP_Settings' ) ? ZPREP_Settings::get( 'section_overage_grace_in', null ) : null;
		$g = ( null === $g || '' === $g ) ? self::GRACE_DEFAULT : (float) $g;
		return max( 0.0, (float) apply_filters( 'zprep_section_grace_in', $g ) );
	}

	/** A colour Prep has a roll for, or the default colour. */
	private static function color_of( string $c ): string {
		$c = strtolower( trim( $c ) );
		if ( class_exists( 'ZPREP_Settings' ) ) {
			return in_array( $c, ZPREP_Settings::roll_colors(), true ) ? $c : ZPREP_Settings::default_color();
		}
		return $c;
	}

	/**
	 * A saved section as measurements the engine cuts: each panel a `section` of one run (run_key), across the roll
	 * its height, along it its length. PURE apart from the colour list.
	 */
	public static function to_measurements( array $row ): array {
		$panels = self::clean_panels( $row['panels'] ?? array() );
		if ( empty( $panels ) ) {
			return array();
		}
		$color   = self::color_of( (string) ( $row['color'] ?? '' ) );
		$run_key = 'sec:' . (int) ( $row['id'] ?? 0 );
		$name    = '' !== trim( (string) ( $row['name'] ?? '' ) ) ? (string) $row['name'] : __( 'Section', 'zorderz' );
		$out     = array();
		foreach ( $panels as $i => $p ) {
			$out[] = array(
				'kind'        => (string) ( $row['kind'] ?? '' ),
				'measure'     => 'section',
				'run_key'     => $run_key,
				'qty'         => (int) $p['qty'],
				'width_in'    => (float) $p['h'],
				'height_in'   => (float) $p['w'],
				'shape'       => 'rectangle',
				'color'       => $color,
				'side'        => '',
				/* translators: 1: section name, 2: panel number, 3: its label. */
				'source_line' => sprintf( __( '%1$s, panel %2$d%3$s', 'zorderz' ), $name, $i + 1, '' !== $p['label'] ? ' (' . $p['label'] . ')' : '' ),
				'notes'       => (string) $p['note'],
			);
		}
		return $out;
	}

	/** A row with its panels decoded and the numbers the screen reads. PURE apart from the tolerance setting. */
	public static function hydrate( array $row ): array {
		$panels              = self::clean_panels( $row['panels'] ?? array() );
		$row['id']           = (int) ( $row['id'] ?? 0 );
		$row['panels']       = $panels;
		$row['panel_count']  = array_sum( array_map( static function ( $p ) {
			return (int) $p['qty'];
		}, $panels ) );
		$row['measured_in']  = self::measured_in( $panels );
		$quoted              = (float) ( $row['run_length_in'] ?? 0 );
		$row['quoted_in']    = $quoted;
		// Tells the person at the site whether they have covered it before they leave: one trip, not two.
		$row['coverage_pct'] = $quoted > 0 ? min( 999, (int) round( ( $row['measured_in'] / $quoted ) * 100 ) ) : 0;
		$row['delta_in']     = $quoted > 0 ? round( $row['measured_in'] - $quoted, 2 ) : 0.0;
		$row['tolerance']    = self::tolerance( (float) $row['measured_in'], $quoted );
		return $row;
	}

	/* ================================================================
	 * RECORDS
	 * ================================================================ */

	private static function job_key( int $lead_id, string $source_job ): string {
		if ( class_exists( 'ZPREP_Cut_State' ) ) {
			return ZPREP_Cut_State::resolve_job_key( $lead_id, $source_job );
		}
		return '' !== $source_job ? 'est:' . $source_job : ( $lead_id > 0 ? 'lead:' . $lead_id : '' );
	}

	public static function save( array $args, int $actor_id ): array {
		global $wpdb;
		$id         = (int) ( $args['id'] ?? 0 );
		$lead_id    = (int) ( $args['lead_id'] ?? 0 );
		$source_job = substr( trim( (string) ( $args['source_job'] ?? '' ) ), 0, 64 );
		$panels     = self::clean_panels( $args['panels'] ?? array() );
		$job_key    = self::job_key( $lead_id, $source_job );
		if ( '' === $job_key ) {
			return array( 'ok' => false, 'message' => __( 'A section must belong to a job.', 'zorderz' ) );
		}
		if ( $id > 0 ) {
			$have = self::get( $id );
			if ( ! $have || (string) $have['job_key'] !== $job_key ) {
				return array( 'ok' => false, 'message' => __( 'That section belongs to another job.', 'zorderz' ) );
			}
		}
		$num  = static function ( $v ) {
			return ( isset( $v ) && '' !== $v && is_numeric( $v ) && (float) $v > 0 ) ? round( (float) $v, 2 ) : null;
		};
		$now  = current_time( 'mysql', 1 );
		$data = array(
			'job_key'       => $job_key,
			'lead_id'       => $lead_id,
			'source_job'    => $source_job,
			'name'          => substr( trim( sanitize_text_field( (string) ( $args['name'] ?? '' ) ) ), 0, 128 ),
			'kind'          => substr( sanitize_text_field( (string) ( $args['kind'] ?? '' ) ), 0, 64 ),
			'status'        => empty( $panels ) ? self::STATUS_DRAFT : self::STATUS_MEASURED,
			'color'         => self::color_of( (string) ( $args['color'] ?? '' ) ),
			'run_length_in' => $num( $args['run_length_in'] ?? null ),
			'run_height_in' => $num( $args['run_height_in'] ?? null ),
			'panels'        => wp_json_encode( $panels ),
			'notes'         => substr( sanitize_textarea_field( (string) ( $args['notes'] ?? '' ) ), 0, 2000 ),
			'updated_at'    => $now,
		);
		if ( $id > 0 ) {
			$wpdb->update( self::table_name(), $data, array( 'id' => $id ) );
		} else {
			$data['created_at'] = $now;
			$data['created_by'] = $actor_id;
			$wpdb->insert( self::table_name(), $data );
			$id = (int) $wpdb->insert_id;
		}
		return array( 'ok' => $id > 0, 'id' => $id, 'section' => self::get( $id ) );
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		if ( $id <= 0 ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a class constant; the id is a placeholder.
		return $row ? self::hydrate( $row ) : null;
	}

	public static function for_job( int $lead_id, string $source_job ): array {
		global $wpdb;
		$job_key = self::job_key( $lead_id, $source_job );
		if ( '' === $job_key ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE job_key = %s ORDER BY id ASC LIMIT 50', $job_key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a class constant; the key is a placeholder.
		return array_map( array( __CLASS__, 'hydrate' ), (array) $rows );
	}

	/** Delete a section of this job (never another job's, whatever id is sent). */
	public static function delete( int $id, int $lead_id, string $source_job ): bool {
		global $wpdb;
		$job_key = self::job_key( $lead_id, $source_job );
		if ( $id <= 0 || '' === $job_key ) {
			return false;
		}
		return (bool) $wpdb->delete( self::table_name(), array( 'id' => $id, 'job_key' => $job_key ), array( '%d', '%s' ) );
	}
}
