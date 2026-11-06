<?php
/**
 * ZPREP_Cut_State — what has been cut on a job, piece group by piece group.
 *
 * Cut state is a PARTITION, not a flag. A job's pieces fall in two classes:
 *
 *     bench   pieces cut at the shop table (the plan's batches)
 *     field   long runs measured and cut on site (the plan's compressed pages; a run that
 *             still needs its field measurement has no countable panels yet)
 *
 * All four bench × field combinations are legal; nothing here orders the two tracks (pulling
 * long stock before the bench work is a real workflow, not a mistake).
 *
 * Job state, one string (no booleans to contradict each other):
 *     unknown   Prep never planned the job
 *     uncut     planned; nothing cut
 *     partial   some cut, some remain — not an error
 *     complete  every piece of every class is cut
 * A class adds two states only the field can hold: unmeasured (field work exists, no panels
 * counted yet) and stock_pulled (stock pulled and ripped, not yet cross-cut).
 *
 * Who cut what, and where (shop or site), is recorded and never gated on. Reading and
 * writing share one gate: may this person open this job in Prep (an assignee only their own
 * jobs). A completion here never moves a CRM stage; changes are announced on the
 * `zprep_cut_state_changed` action, which nothing is required to subscribe to.
 *
 * pick() is pure (rows in, state out) and is the unit-tested heart of the class.
 *
 * @package Zorderz\Prep
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZPREP_Cut_State {

	const TABLE = 'zprep_cut_state';

	const STATE_UNKNOWN  = 'unknown';
	const STATE_UNCUT    = 'uncut';
	const STATE_PARTIAL  = 'partial';
	const STATE_COMPLETE = 'complete';

	const CSTATE_UNMEASURED   = 'unmeasured';
	const CSTATE_STOCK_PULLED = 'stock_pulled';

	const CLASS_BENCH = 'bench';
	const CLASS_FIELD = 'field';

	const WHERE_SHOP = 'shop';
	const WHERE_SITE = 'site';

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
			job_key        VARCHAR(80)     NOT NULL DEFAULT '',
			lead_id        BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			source_job     VARCHAR(64)     NOT NULL DEFAULT '',
			work_class     VARCHAR(16)     NOT NULL DEFAULT 'bench',
			item_key       VARCHAR(191)    NOT NULL DEFAULT '',
			item_label     VARCHAR(191)    NOT NULL DEFAULT '',
			qty_total      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			qty_cut        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			stock_pulled   TINYINT(1)      NOT NULL DEFAULT 0,
			cut_by         BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			cut_at         DATETIME        NULL DEFAULT NULL,
			cut_where      VARCHAR(16)     NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY job_item (job_key, work_class, item_key),
			KEY idx_lead (lead_id),
			KEY idx_job (source_job),
			KEY idx_class (work_class)
		) {$charset};" );
	}

	/* ================================================================
	 * IDENTITY
	 * ================================================================ */

	/**
	 * The one place a job key is produced. A job reached first by its CRM lead and later by
	 * its estimate number (or the other way round) keeps the key it was first given, so its
	 * rows are never split across two keys.
	 */
	public static function resolve_job_key( int $lead_id, string $source_job ): string {
		$source_job = trim( $source_job );
		if ( '' !== $source_job && preg_match( '/^(remake|est|lead):/', $source_job ) ) {
			return $source_job;
		}
		if ( $lead_id > 0 || '' !== $source_job ) {
			global $wpdb;
			$ors  = array();
			$args = array();
			if ( $lead_id > 0 ) {
				$ors[]  = 'lead_id = %d';
				$args[] = $lead_id;
			}
			if ( '' !== $source_job ) {
				$ors[]  = 'source_job = %s';
				$args[] = $source_job;
			}
			$found = $wpdb->get_var( $wpdb->prepare( 'SELECT job_key FROM `' . self::table_name() . '` WHERE ( ' . implode( ' OR ', $ors ) . ' ) LIMIT 1', $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a class constant; the WHERE joins fixed fragments whose values are placeholders.
			if ( is_string( $found ) && '' !== $found ) {
				return $found;
			}
		}
		return self::default_job_key( $lead_id, $source_job );
	}

	/** Pure: the key a job gets when it has none yet (estimate number first, else lead). */
	public static function default_job_key( int $lead_id, string $source_job ): string {
		$source_job = trim( $source_job );
		if ( '' !== $source_job ) {
			return preg_match( '/^(remake|est|lead):/', $source_job ) ? $source_job : 'est:' . $source_job;
		}
		return $lead_id > 0 ? 'lead:' . $lead_id : '';
	}

	/* ================================================================
	 * THE DECISION (pure)
	 * ================================================================ */

	public static function empty_result(): array {
		return array(
			'state'      => self::STATE_UNKNOWN,
			'bench'      => array( 'total' => 0, 'cut' => 0, 'state' => self::STATE_UNKNOWN ),
			'field'      => array( 'runs' => 0, 'total' => 0, 'cut' => 0, 'state' => self::STATE_UNKNOWN ),
			'remaining'  => array(),
			'headline'   => '',
			'job_key'    => '',
			'row_count'  => 0,
			'cut_by'     => 0,
			'cut_at'     => '',
			'cut_by_all' => array(),
		);
	}

	/** Rows of one job in, its state out. cut_by / cut_at are the latest cut. */
	public static function pick( array $rows ): array {
		$out = self::empty_result();
		if ( empty( $rows ) ) {
			return $out;
		}
		$out['row_count'] = count( $rows );
		$out['job_key']   = (string) ( $rows[0]['job_key'] ?? '' );

		$b_total = 0; $b_cut = 0; $f_total = 0; $f_cut = 0; $f_runs = 0; $f_pulled = 0;
		$remaining = array();
		foreach ( $rows as $r ) {
			$total = (int) ( $r['qty_total'] ?? 0 );
			$cut   = min( (int) ( $r['qty_cut'] ?? 0 ), $total );
			$class = ( self::CLASS_FIELD === (string) ( $r['work_class'] ?? '' ) ) ? self::CLASS_FIELD : self::CLASS_BENCH;
			$by    = (int) ( $r['cut_by'] ?? 0 );
			$at    = (string) ( $r['cut_at'] ?? '' );
			if ( $by > 0 && $cut > 0 ) {
				$out['cut_by_all'][ $by ] = $by;
				if ( '' !== $at && ( '' === $out['cut_at'] || strcmp( $at, $out['cut_at'] ) > 0 ) ) {
					$out['cut_at'] = $at;
					$out['cut_by'] = $by;
				} elseif ( 0 === $out['cut_by'] ) {
					$out['cut_by'] = $by;
				}
			}
			if ( self::CLASS_FIELD === $class ) {
				$f_runs++;
				if ( $total > 0 ) {
					$f_total += $total;
					$f_cut   += $cut;
				}
				if ( ! empty( $r['stock_pulled'] ) ) {
					$f_pulled++;
				}
			} else {
				$b_total += $total;
				$b_cut   += $cut;
			}
			if ( $cut < $total || ( self::CLASS_FIELD === $class && $total <= 0 ) ) {
				$remaining[] = array(
					'class'        => $class,
					'label'        => (string) ( $r['item_label'] ?? '' ),
					'item_key'     => (string) ( $r['item_key'] ?? '' ),
					'panels'       => $total,
					'panels_cut'   => $cut,
					'stock_pulled' => ! empty( $r['stock_pulled'] ),
				);
			}
		}
		$out['bench']      = array( 'total' => $b_total, 'cut' => $b_cut, 'state' => self::class_state( $b_total, $b_cut, false, false ) );
		$out['field']      = array( 'runs' => $f_runs, 'total' => $f_total, 'cut' => $f_cut, 'state' => self::class_state( $f_total, $f_cut, $f_runs > 0, $f_pulled > 0 ) );
		$out['remaining']  = $remaining;
		$out['cut_by_all'] = array_values( $out['cut_by_all'] );

		$total_all = $b_total + $f_total;
		$cut_all   = $b_cut + $f_cut;
		$open_run  = $f_runs > 0 && 0 === $f_total;
		if ( 0 === $total_all && 0 === $f_runs ) {
			$out['state'] = self::STATE_UNKNOWN;
		} elseif ( 0 === $cut_all && ! $open_run && $total_all > 0 ) {
			$out['state'] = self::STATE_UNCUT;
		} elseif ( $cut_all >= $total_all && ! $open_run && $total_all > 0 ) {
			$out['state'] = self::STATE_COMPLETE;
		} elseif ( 0 === $cut_all && $open_run && 0 === $total_all ) {
			$out['state'] = self::STATE_UNCUT;
		} else {
			$out['state'] = self::STATE_PARTIAL;
		}
		$out['headline'] = self::headline( $out );
		return $out;
	}

	private static function class_state( int $total, int $cut, bool $has_runs, bool $stock_pulled ): string {
		if ( 0 === $total && $has_runs ) {
			return self::CSTATE_UNMEASURED;
		}
		if ( 0 === $total ) {
			return self::STATE_UNKNOWN;
		}
		if ( $cut >= $total ) {
			return self::STATE_COMPLETE;
		}
		if ( $cut > 0 ) {
			return self::STATE_PARTIAL;
		}
		return $stock_pulled ? self::CSTATE_STOCK_PULLED : self::STATE_UNCUT;
	}

	/** One plain sentence for a card or a project panel. */
	private static function headline( array $r ): string {
		$b     = $r['bench'];
		$f     = $r['field'];
		$parts = array();
		if ( $b['total'] > 0 ) {
			if ( $b['cut'] >= $b['total'] ) {
				$parts[] = __( 'Shop done', 'zorderz' );
			} elseif ( $b['cut'] > 0 ) {
				/* translators: 1: pieces cut, 2: pieces in all. */
				$parts[] = sprintf( __( '%1$d of %2$d shop pieces cut', 'zorderz' ), $b['cut'], $b['total'] );
			} else {
				/* translators: %d: pieces. */
				$parts[] = sprintf( _n( '%d shop piece to cut', '%d shop pieces to cut', $b['total'], 'zorderz' ), $b['total'] );
			}
		}
		if ( self::CSTATE_UNMEASURED === $f['state'] ) {
			/* translators: %d: runs. */
			$parts[] = sprintf( _n( '%d run still needs a field measurement', '%d runs still need a field measurement', $f['runs'], 'zorderz' ), $f['runs'] );
		} elseif ( $f['total'] > 0 ) {
			$rem = $f['total'] - $f['cut'];
			/* translators: %d: panels. */
			$parts[] = $rem <= 0 ? __( 'field sections cut', 'zorderz' ) : sprintf( _n( '%d field panel still to cut', '%d field panels still to cut', $rem, 'zorderz' ), $rem );
		}
		if ( empty( $parts ) ) {
			return '';
		}
		return ucfirst( implode( '. ', $parts ) ) . '.';
	}

	/**
	 * Pure: the row a mark makes from the stored row (or null) and the posted item. Returns
	 * null when nothing would change. A cut count only ever stamps who/when/where when it goes
	 * up (or stock is first pulled); lowering a count goes through reopen().
	 */
	public static function mark_row( ?array $existing, array $item, int $actor, string $now, string $where ): ?array {
		$total = max( 0, (int) ( $item['qty_total'] ?? 0 ) );
		$cut   = max( 0, min( (int) ( $item['qty_cut'] ?? 0 ), $total ) );
		$pull  = ! empty( $item['stock_pulled'] ) ? 1 : 0;
		$where = ( self::WHERE_SITE === $where ) ? self::WHERE_SITE : self::WHERE_SHOP;
		$data  = array( 'qty_total' => $total, 'qty_cut' => $cut, 'stock_pulled' => $pull );
		if ( $existing ) {
			if ( (int) $existing['qty_cut'] === $cut && (int) $existing['qty_total'] === $total && (int) $existing['stock_pulled'] === $pull ) {
				return null;
			}
			if ( $cut > (int) $existing['qty_cut'] || ( $pull && ! (int) $existing['stock_pulled'] ) ) {
				$data['cut_by']    = $actor;
				$data['cut_at']    = $now;
				$data['cut_where'] = $where;
			}
			return $data;
		}
		if ( $cut > 0 || $pull ) {
			$data['cut_by']    = $actor;
			$data['cut_at']    = $now;
			$data['cut_where'] = $where;
		}
		return $data;
	}

	/* ================================================================
	 * READS
	 * ================================================================ */

	/** [ lead_id => state ] for the leads $viewer may see in Prep. */
	public static function for_leads( array $lead_ids, int $viewer_id ): array {
		$ids = array();
		foreach ( $lead_ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}
		$out = array();
		foreach ( $ids as $id ) {
			$out[ $id ] = self::empty_result();
		}
		if ( empty( $ids ) || ! self::viewer_may_see( $viewer_id ) ) {
			return $out;
		}
		try {
			global $wpdb;
			$rows    = $wpdb->get_results( 'SELECT * FROM `' . self::table_name() . '` WHERE lead_id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a class constant; the IN list is ints only (intval).
			$buckets = array_fill_keys( array_keys( $ids ), array() );
			foreach ( (array) $rows as $r ) {
				$lid = (int) ( $r['lead_id'] ?? 0 );
				if ( isset( $buckets[ $lid ] ) ) {
					$buckets[ $lid ][] = $r;
				}
			}
			foreach ( $buckets as $lid => $rs ) {
				$out[ $lid ] = self::pick( $rs );
			}
		} catch ( \Throwable $e ) {
			error_log( 'ZPREP cut-state for_leads failed: ' . $e->getMessage() );
		}
		return $out;
	}

	/** [ estimate number => state ] for the estimates $viewer may see in Prep. */
	public static function for_estimate_numbers( array $numbers, int $viewer_id ): array {
		$nums = array();
		foreach ( $numbers as $n ) {
			$n = trim( (string) $n );
			if ( '' !== $n ) {
				$nums[ $n ] = $n;
			}
		}
		$out = array();
		foreach ( $nums as $n ) {
			$out[ $n ] = self::empty_result();
		}
		if ( empty( $nums ) || ! self::viewer_may_see( $viewer_id ) ) {
			return $out;
		}
		try {
			global $wpdb;
			$ph      = implode( ',', array_fill( 0, count( $nums ), '%s' ) );
			$rows    = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . self::table_name() . "` WHERE source_job IN ({$ph})", array_values( $nums ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a class constant; every IN-list item is a placeholder.
			$buckets = array_fill_keys( array_keys( $nums ), array() );
			foreach ( (array) $rows as $r ) {
				$sj = (string) ( $r['source_job'] ?? '' );
				if ( isset( $buckets[ $sj ] ) ) {
					$buckets[ $sj ][] = $r;
				}
			}
			foreach ( $buckets as $sj => $rs ) {
				$out[ $sj ] = self::pick( $rs );
			}
		} catch ( \Throwable $e ) {
			error_log( 'ZPREP cut-state for_estimate_numbers failed: ' . $e->getMessage() );
		}
		return $out;
	}

	public static function rows_for_job( string $job_key ): array {
		if ( '' === $job_key ) {
			return array();
		}
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . self::table_name() . '` WHERE job_key = %s ORDER BY work_class ASC, id ASC', $job_key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a class constant; the key is a placeholder.
	}

	/** The state of one job as $viewer sees it. */
	public static function for_job( int $lead_id, string $source_job ): array {
		return self::pick( self::rows_for_job( self::resolve_job_key( $lead_id, $source_job ) ) );
	}

	/* ================================================================
	 * WRITES
	 * ================================================================ */

	/**
	 * Record cut counts for some items of a job.
	 *
	 * @param array $items [ work_class, item_key, item_label, qty_total, qty_cut, stock_pulled ]
	 * @return array{changed:int,state:array}
	 */
	public static function mark_cut( int $lead_id, string $source_job, array $items, int $actor_id, string $where = self::WHERE_SHOP ): array {
		global $wpdb;
		$job_key = self::resolve_job_key( $lead_id, $source_job );
		if ( '' === $job_key ) {
			return array( 'changed' => 0, 'state' => self::empty_result() );
		}
		$now     = current_time( 'mysql', 1 );
		$table   = self::table_name();
		$changed = 0;
		foreach ( $items as $it ) {
			if ( ! is_array( $it ) ) {
				continue;
			}
			$class = ( self::CLASS_FIELD === (string) ( $it['work_class'] ?? '' ) ) ? self::CLASS_FIELD : self::CLASS_BENCH;
			$key   = mb_substr( (string) ( $it['item_key'] ?? '' ), 0, 191 );
			if ( '' === $key ) {
				continue;
			}
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE job_key = %s AND work_class = %s AND item_key = %s", $job_key, $class, $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a class constant; values are placeholders.
			$data     = self::mark_row( $existing ?: null, $it, $actor_id, $now, $where );
			if ( null === $data ) {
				if ( $existing && '' === (string) ( $existing['source_job'] ?? '' ) && '' !== $source_job ) {
					$wpdb->update( $table, array( 'source_job' => $source_job ), array( 'id' => (int) $existing['id'] ) );
				}
				continue;
			}
			$data += array(
				'job_key'    => $job_key,
				'lead_id'    => $lead_id,
				'source_job' => $source_job,
				'work_class' => $class,
				'item_key'   => $key,
				'item_label' => mb_substr( sanitize_text_field( (string) ( $it['item_label'] ?? '' ) ), 0, 191 ),
				'updated_at' => $now,
			);
			if ( $existing ) {
				$wpdb->update( $table, $data, array( 'id' => (int) $existing['id'] ) );
			} else {
				$data['created_at'] = $now;
				$wpdb->insert( $table, $data );
			}
			$changed++;
		}
		$state = self::pick( self::rows_for_job( $job_key ) );
		if ( $changed > 0 ) {
			do_action( 'zprep_cut_state_changed', $lead_id, $state, $job_key );
		}
		return array( 'changed' => $changed, 'state' => $state );
	}

	/** Undo cuts on some items (counts back to zero, stock no longer pulled). */
	public static function reopen( int $lead_id, string $source_job, array $item_keys, int $actor_id ): array {
		global $wpdb;
		$job_key = self::resolve_job_key( $lead_id, $source_job );
		if ( '' === $job_key ) {
			return array( 'changed' => 0, 'state' => self::empty_result() );
		}
		$now     = current_time( 'mysql', 1 );
		$changed = 0;
		foreach ( $item_keys as $key ) {
			$key = mb_substr( (string) $key, 0, 191 );
			if ( '' === $key ) {
				continue;
			}
			$n        = $wpdb->update(
				self::table_name(),
				array( 'qty_cut' => 0, 'stock_pulled' => 0, 'cut_at' => null, 'cut_where' => '', 'updated_at' => $now ),
				array( 'job_key' => $job_key, 'item_key' => $key )
			);
			$changed += max( 0, (int) $n );
		}
		$state = self::pick( self::rows_for_job( $job_key ) );
		if ( $changed > 0 ) {
			ZPREP_Settings::disposition( 'cut_reopened', array( 'job_key' => $job_key, 'items' => $changed, 'by' => $actor_id ) );
			do_action( 'zprep_cut_state_changed', $lead_id, $state, $job_key );
		}
		return array( 'changed' => $changed, 'state' => $state );
	}

	/** Make sure a planned job has its item rows (totals follow the latest plan; cut counts kept). */
	public static function sync_from_plan( int $lead_id, string $source_job, array $plan ): array {
		$items = self::items_from_plan( $plan );
		if ( empty( $items ) ) {
			return array( 'changed' => 0, 'state' => self::empty_result() );
		}
		global $wpdb;
		$job_key = self::resolve_job_key( $lead_id, $source_job );
		if ( '' === $job_key ) {
			return array( 'changed' => 0, 'state' => self::empty_result() );
		}
		$now   = current_time( 'mysql', 1 );
		$table = self::table_name();
		foreach ( $items as $it ) {
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, qty_total FROM `{$table}` WHERE job_key = %s AND work_class = %s AND item_key = %s", $job_key, $it['work_class'], $it['item_key'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a class constant; values are placeholders.
			if ( $existing ) {
				if ( (int) $existing['qty_total'] !== (int) $it['qty_total'] ) {
					$wpdb->update( $table, array( 'qty_total' => (int) $it['qty_total'], 'updated_at' => $now ), array( 'id' => (int) $existing['id'] ) );
				}
				continue;
			}
			$wpdb->insert( $table, array(
				'job_key'    => $job_key,
				'lead_id'    => $lead_id,
				'source_job' => $source_job,
				'work_class' => $it['work_class'],
				'item_key'   => $it['item_key'],
				'item_label' => $it['item_label'],
				'qty_total'  => (int) $it['qty_total'],
				'qty_cut'    => 0,
				'created_at' => $now,
				'updated_at' => $now,
			) );
		}
		return array( 'changed' => count( $items ), 'state' => self::pick( self::rows_for_job( $job_key ) ) );
	}

	/**
	 * Pure: the cut-state items of a plan. Every batch that is cut at the bench (has sheets,
	 * is not a pre-made piece) is one bench item; every compressed long-run page is one field
	 * item (zero panels while it still needs its field measurement).
	 */
	public static function items_from_plan( array $plan ): array {
		$items = array();
		foreach ( (array) ( $plan['batches'] ?? array() ) as $b ) {
			if ( ! is_array( $b ) || empty( $b['plan']['sheets'] ) || ! empty( $b['plan']['not_cut'] ) ) {
				continue;
			}
			$items[] = array(
				'work_class' => self::CLASS_BENCH,
				'item_key'   => (string) ( $b['key'] ?? '' ),
				'item_label' => trim( self::dims_label( $b ) . ' ' . self::kind_text( (string) ( $b['kind'] ?? '' ) ) ),
				'qty_total'  => (int) ( $b['total_qty'] ?? 0 ),
			);
		}
		foreach ( (array) ( $plan['pages'] ?? array() ) as $i => $pg ) {
			if ( ! is_array( $pg ) || empty( $pg['compressed'] ) ) {
				continue;
			}
			$run    = (string) ( $pg['run_key'] ?? '' );
			$panels = (int) ( $pg['piece_count'] ?? 0 );
			$needs  = ! empty( $pg['needs_sections'] );
			$items[] = array(
				'work_class' => self::CLASS_FIELD,
				'item_key'   => 'run:' . ( '' !== $run ? $run : (string) $i ),
				'item_label' => $needs
					/* translators: %s: run length in inches. */
					? sprintf( __( 'Long run, %s", needs a field measurement', 'zorderz' ), (string) ( $pg['run_length_in'] ?? '?' ) )
					/* translators: 1: panels, 2: sheet length in inches. */
					: sprintf( _n( '%1$d panel, %2$s" pull', '%1$d panels, %2$s" pull', $panels, 'zorderz' ), $panels, (string) ( $pg['sheet_length'] ?? '?' ) ),
				'qty_total'  => $needs ? 0 : $panels,
			);
		}
		return array_values( array_filter( $items, static function ( $i ) { return '' !== $i['item_key']; } ) );
	}

	private static function kind_text( string $kind ): string {
		if ( '' !== $kind && class_exists( 'ZPREP_Settings' ) && method_exists( 'ZPREP_Settings', 'kind_label' ) ) {
			return (string) ZPREP_Settings::kind_label( $kind );
		}
		return $kind;
	}

	private static function dims_label( array $b ): string {
		if ( ! isset( $b['width_in'], $b['height_in'] ) || null === $b['width_in'] || null === $b['height_in'] ) {
			return '';
		}
		$f = static function ( $v ) {
			return ( abs( $v - round( $v ) ) < 0.001 ) ? (string) (int) round( $v ) : rtrim( rtrim( number_format( $v, 2, '.', '' ), '0' ), '.' );
		};
		return $f( (float) $b['width_in'] ) . '×' . $f( (float) $b['height_in'] );
	}

	/* ================================================================
	 * GATES + THE PROJECT PANEL
	 * ================================================================ */

	/** Reads share Prep's door: a person who may open Prep. */
	private static function viewer_may_see( int $viewer_id ): bool {
		return $viewer_id > 0 && class_exists( 'ZPREP_Dashboard' ) && ZPREP_Dashboard::user_can_access( $viewer_id );
	}

	/** Contribute a "Cut state" panel to the jobs app's project page (no-op without it). */
	public static function register_project_source(): void {
		add_filter( 'zdz_project_sources', static function ( $sources ) {
			if ( ! is_array( $sources ) ) {
				return $sources;
			}
			$sources[] = array( 'id' => 'zprep_cut_state', 'class' => 'work', 'gather' => array( __CLASS__, 'gather_for_project' ) );
			return $sources;
		} );
	}

	/**
	 * The project panel: the cut state of each of the project's jobs that has one. The jobs
	 * app has already decided the viewer may see the project; Prep's own door is checked too.
	 */
	public static function gather_for_project( int $viewer, array $project, bool $see_money = false ): array {
		unset( $see_money );
		try {
			if ( ! self::viewer_may_see( $viewer ) ) {
				return array( 'state' => 'withheld', 'data' => null );
			}
			$pid = (string) ( $project['id'] ?? '' );
			if ( '' === $pid || ! class_exists( 'ZJOB_Project' ) || ! method_exists( 'ZJOB_Project', 'jobs_for' ) ) {
				return array( 'state' => 'empty', 'data' => null );
			}
			$leads = array();
			foreach ( (array) ZJOB_Project::jobs_for( $pid ) as $j ) {
				$v = (int) ( is_array( $j ) ? ( $j['parent_lead_id'] ?? 0 ) : 0 );
				if ( $v > 0 ) {
					$leads[ $v ] = true;
				}
			}
			$out = array();
			foreach ( self::for_leads( array_keys( $leads ), $viewer ) as $lead_id => $st ) {
				if ( self::STATE_UNKNOWN !== $st['state'] ) {
					$out[] = array( 'lead_id' => (int) $lead_id, 'state' => $st['state'], 'headline' => $st['headline'] );
				}
			}
			return array( 'state' => $out ? 'ok' : 'empty', 'data' => $out ? array( 'jobs' => $out ) : null );
		} catch ( \Throwable $e ) {
			error_log( 'ZPREP cut-state project panel failed: ' . $e->getMessage() );
			return array( 'state' => 'unavailable', 'data' => null );
		}
	}
}
