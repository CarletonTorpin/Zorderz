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
 * INTERNAL ORDERS. An internal order is a row of this same table with kind = 'internal': the
 * shop cutting for itself, with no customer and no original job, so nothing is written to the
 * CRM or to billing. It keeps everything a remake has (its own sizes, a cut plan, the cut
 * ledger under 'remake:<id>', an assignee) and adds what the year-end page counts:
 *   - a purpose and who it is for, and a reference code that says both at a glance
 *     (INT-<purpose code>-<yy>-<nnn>); the purposes and their codes are the business's
 *     (ZPREP_Settings::internal_purposes());
 *   - the stock it is cut from: a roll, or a sheet of a stated size;
 *   - hours spent, typed by a person (the app never works out a time by itself), with who
 *     typed them;
 *   - what each piece would sell for, typed by a person;
 *   - what the material cost per square inch when the order was made
 *     (ZPREP_Settings::material_cost_rate()), kept on the order.
 * MONEY NEVER RIDES ON A CARD: card() goes to whoever is assigned the cut, so the rate, the
 * cost and the sell prices are read only by figures(), for the administrator's year-end page.
 *
 * @package Zorderz\Prep
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZPREP_Remakes {

	const TABLE = 'zprep_remakes';

	const STATUSES = array( 'open', 'cut', 'done', 'void' );

	const KIND_REMAKE   = 'remake';
	const KIND_INTERNAL = 'internal';

	/** The table's shape. Raised when migrate() changes; maybe_upgrade() brings an older table up to it. */
	const DB_SCHEMA  = 2;
	const SCHEMA_OPT = 'zprep_remakes_schema';

	/** How an internal order's reference code begins. */
	const REF_PREFIX = 'INT';
	/** Hours on one order: 0 to this. */
	const LABOR_MAX_HOURS = 1000;
	/** What one piece would sell for: 0 to this. */
	const SELL_MAX = 100000;

	/** Injected in tests: what makes or widens the table from schema_sql() (on a site, dbDelta()). */
	public static $delta = null;

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
		$sql = self::schema_sql();
		if ( is_callable( self::$delta ) ) {
			call_user_func( self::$delta, $sql );
		} else {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );
		}
		update_option( self::SCHEMA_OPT, self::DB_SCHEMA, false );
	}

	/** The table, as dbDelta() is handed it: a new one is made whole, an older one gains what it lacks. */
	public static function schema_sql(): string {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		return "CREATE TABLE {$table} (
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
			kind VARCHAR(16) NOT NULL DEFAULT 'remake',
			purpose_code VARCHAR(40) NOT NULL DEFAULT '',
			for_whom VARCHAR(191) NOT NULL DEFAULT '',
			ref_code VARCHAR(40) NOT NULL DEFAULT '',
			sheet_w DECIMAL(6,2) NOT NULL DEFAULT 0,
			sheet_l DECIMAL(6,2) NOT NULL DEFAULT 0,
			sheets_used SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			labor_minutes INT UNSIGNED NOT NULL DEFAULT 0,
			labor_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			labor_at DATETIME NULL,
			cost_rate DECIMAL(10,6) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY idx_created (created_at),
			KEY idx_parent (parent_job_key),
			KEY idx_status (status),
			KEY idx_kind (kind)
		) {$charset};";
	}

	/**
	 * Bring a table made before internal orders up to date (dbDelta adds the columns and the index; every
	 * existing row reads kind = 'remake'). Runs once per schema, on the first request after an update.
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::SCHEMA_OPT, 0 ) >= self::DB_SCHEMA ) {
			return;
		}
		self::migrate();
	}

	public static function is_internal( ?array $row ): bool {
		return is_array( $row ) && self::KIND_INTERNAL === (string) ( $row['kind'] ?? '' );
	}

	/** What an internal order may be for: key => { label, code }. */
	public static function purposes(): array {
		return ZPREP_Settings::internal_purposes();
	}

	/** A purpose's label. A purpose no longer offered keeps a readable name, from its key. */
	public static function purpose_label( string $key ): string {
		if ( '' === $key ) {
			return '';
		}
		$all = self::purposes();
		return isset( $all[ $key ] ) ? (string) $all[ $key ]['label'] : ucwords( str_replace( array( '_', '-' ), ' ', $key ) );
	}

	/**
	 * The stock sheet an order is cut from, or null when it is cut from a roll.
	 *
	 * @return array{w:int,l:float}|null
	 */
	public static function stock_sheet( ?array $row ): ?array {
		return is_array( $row ) ? self::clean_sheet( $row['sheet_w'] ?? 0, $row['sheet_l'] ?? 0 ) : null;
	}

	/**
	 * What a sheet is, is the engine's rule and only the engine's (ZPREP_Engine::clean_stock_sheet()): the shorter
	 * side is the width, whichever box it was typed in.
	 *
	 * @return array{w:int,l:float}|null
	 */
	public static function clean_sheet( $w, $l ): ?array {
		return ZPREP_Settings::clean_sheet( array( 'w' => $w, 'l' => $l ) );
	}

	/**
	 * Does a w x h piece come out of this sheet, either way round? (A cleaned sheet's width is its shorter side, so
	 * this is exactly what the packer can lay: short side across, long side along.) PURE.
	 */
	public static function fits_sheet( float $w, float $h, array $sheet ): bool {
		$sw = (float) $sheet['w'];
		$sl = (float) $sheet['l'];
		return ( $w <= $sw + 1e-6 && $h <= $sl + 1e-6 ) || ( $h <= $sw + 1e-6 && $w <= $sl + 1e-6 );
	}

	/** 24 not 24.00, 22.5 not 22.50. PURE. */
	public static function num( float $n ): string {
		if ( abs( $n - round( $n ) ) < 0.005 ) {
			return (string) (int) round( $n );
		}
		return rtrim( rtrim( number_format( $n, 2, '.', '' ), '0' ), '.' );
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

	/**
	 * Rows of an internal order: a positive quantity, a kind slug, sizes as numbers or '', the shape (anything that
	 * is not a rectangle is refused later, by name), a roll colour or '', and what one piece would sell for: a number
	 * a person typed, or '' (a blank stays blank, never a guessed price). PURE.
	 */
	public static function normalize_internal_pieces( $rows, array $colors ): array {
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $v ) {
			if ( ! is_array( $v ) ) {
				continue;
			}
			$qty = max( 0, (int) ( $v['qty'] ?? 0 ) );
			if ( $qty <= 0 ) {
				continue;
			}
			$kind  = substr( (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) ( $v['kind'] ?? $v['type'] ?? 'custom' ) ) ), 0, 80 );
			$shape = strtolower( trim( (string) ( $v['shape'] ?? 'rectangle' ) ) );
			$c     = strtolower( trim( (string) ( $v['color'] ?? '' ) ) );
			$sell  = $v['sell_each'] ?? '';
			$out[] = array(
				'kind'      => '' !== $kind ? $kind : 'custom',
				'qty'       => min( 999, $qty ),
				'width'     => ( isset( $v['width'] ) && is_numeric( $v['width'] ) && (float) $v['width'] > 0 ) ? round( (float) $v['width'], 2 ) : '',
				'height'    => ( isset( $v['height'] ) && is_numeric( $v['height'] ) && (float) $v['height'] > 0 ) ? round( (float) $v['height'], 2 ) : '',
				'shape'     => ( '' === $shape || 'rectangle' === $shape ) ? 'rectangle' : 'circle',
				'color'     => in_array( $c, $colors, true ) ? $c : '',
				'notes'     => mb_substr( trim( (string) ( $v['notes'] ?? '' ) ), 0, 191 ),
				'sell_each' => ( is_numeric( $sell ) && (float) $sell >= 0 && (float) $sell <= self::SELL_MAX ) ? round( (float) $sell, 2 ) : '',
			);
		}
		return $out;
	}

	/**
	 * Validate and normalize an internal order. Returns [ 'error' => code ] (with 'sizes' when the message names
	 * them) or [ 'row' => fields ]. Sizes are exact here: every row has both, is a rectangle of a kind the engine
	 * cuts as typed, and comes out of the stock named. PURE: what is on offer is handed in.
	 *
	 * @param array    $args     customer_label (the order's name), purpose_code, for_whom, reason_text, color,
	 *                           force_roll, stock ('sheet' when the form says sheets), sheet_w, sheet_l, pieces.
	 * @param string[] $purposes the purpose keys on offer.
	 * @param string[] $kinds    the kinds of piece on offer.
	 * @param string[] $colors   the configured roll colours.
	 * @param int[]    $widths   the configured roll widths.
	 */
	public static function normalize_internal( array $args, array $purposes, array $kinds, array $colors, array $widths ): array {
		$name = trim( (string) ( $args['customer_label'] ?? '' ) );
		if ( '' === $name ) {
			return array( 'error' => 'no_name' );
		}
		$pieces = self::normalize_internal_pieces( $args['pieces'] ?? array(), $colors );
		if ( empty( $pieces ) ) {
			return array( 'error' => 'no_pieces' );
		}
		$purpose = (string) ( $args['purpose_code'] ?? '' );
		if ( '' === $purpose || ! in_array( $purpose, $purposes, true ) ) {
			return array( 'error' => 'no_purpose' );
		}
		// 'sheet' from the form means a sheet, whatever the size boxes hold (so an empty or 0 x 0 size is refused,
		// never saved as a roll order by mistake). Without it, a size given means a sheet.
		$sw    = $args['sheet_w'] ?? 0;
		$sl    = $args['sheet_l'] ?? 0;
		$sheet = null;
		if ( 'sheet' === (string) ( $args['stock'] ?? '' ) || ( is_numeric( $sw ) && (float) $sw > 0 ) || ( is_numeric( $sl ) && (float) $sl > 0 ) ) {
			$sheet = self::clean_sheet( $sw, $sl );
			if ( null === $sheet ) {
				return array( 'error' => 'bad_sheet' );
			}
		}
		foreach ( $pieces as $v ) {
			if ( 'rectangle' !== $v['shape'] ) {
				return array( 'error' => 'round' );
			}
			if ( ! in_array( $v['kind'], $kinds, true ) ) {
				return array( 'error' => 'kind' );
			}
			if ( '' === $v['width'] || '' === $v['height'] ) {
				return array( 'error' => 'no_size' );
			}
			if ( $sheet && ! self::fits_sheet( (float) $v['width'], (float) $v['height'], $sheet ) ) {
				return array( 'error' => 'no_fit', 'sizes' => array( (float) $v['width'], (float) $v['height'], (float) $sheet['w'], (float) $sheet['l'] ) );
			}
		}
		$color = strtolower( trim( (string) ( $args['color'] ?? '' ) ) );
		$roll  = (int) ( $args['force_roll'] ?? 0 );
		return array(
			'row' => array(
				'customer_label' => mb_substr( $name, 0, 191 ),
				'purpose_code'   => $purpose,
				'for_whom'       => mb_substr( trim( (string) ( $args['for_whom'] ?? '' ) ), 0, 191 ),
				'reason_text'    => mb_substr( trim( (string) ( $args['reason_text'] ?? '' ) ), 0, 255 ),
				'color'          => in_array( $color, $colors, true ) ? $color : '',
				'force_roll'     => ( ! $sheet && in_array( $roll, array_map( 'intval', $widths ), true ) ) ? $roll : 0,
				'sheet'          => $sheet,
				'pieces'         => $pieces,
			),
		);
	}

	/** What a refused internal order is told. */
	private static function internal_error_message( array $n ): string {
		switch ( (string) $n['error'] ) {
			case 'no_name':
				return __( 'Give the order a name.', 'zorderz' );
			case 'no_pieces':
				return __( 'Add at least one row (quantity and size).', 'zorderz' );
			case 'no_purpose':
				return __( 'Pick what the order is for.', 'zorderz' );
			case 'bad_sheet':
				/* translators: %d: the largest sheet side, in inches. */
				return sprintf( __( 'The sheet size needs a width and a length, each from 1 to %d inches.', 'zorderz' ), ZPREP_Engine::SHEET_MAX_IN );
			case 'round':
				return __( 'A round piece is not cut as typed. Add it as Custom, with the size of the square to cut.', 'zorderz' );
			case 'kind':
				return __( 'That kind of piece cannot go on an internal order. Use Custom, with its size.', 'zorderz' );
			case 'no_size':
				return __( 'Every row needs a width and a height.', 'zorderz' );
			case 'no_fit':
				$z = array_map( array( __CLASS__, 'num' ), array_map( 'floatval', (array) ( $n['sizes'] ?? array( 0, 0, 0, 0 ) ) ) );
				/* translators: 1: piece width, 2: piece height, 3: sheet width, 4: sheet length (inches). */
				return sprintf( __( '%1$s x %2$s in. does not come out of a %3$s x %4$s in. sheet. Take that row off, or cut this order from a roll.', 'zorderz' ), $z[0], $z[1], $z[2], $z[3] );
		}
		return __( 'The order could not be saved.', 'zorderz' );
	}

	/**
	 * Lay the order out before it is saved, and say what is wrong when every piece does not land on a sheet. The
	 * engine is the only thing that knows what it will cut, so the save asks it rather than keep a second copy of its
	 * rules here. Null means every piece is laid out.
	 *
	 * @param array $measurements engine rows (self::measurements()).
	 * @param array $options      self::plan_options()-shaped.
	 * @param int   $pieces       how many pieces the rows ask for.
	 */
	public static function plan_problem( array $measurements, array $options, int $pieces ): ?string {
		try {
			$plan = ( new ZPREP_Engine() )->compute_plan( $measurements, $options );
		} catch ( \Throwable $e ) {
			return __( 'The cut plan could not be worked out for these pieces.', 'zorderz' );
		}
		if ( ! empty( $plan['dropped'] ) ) {
			$d     = (array) $plan['dropped'][0];
			$label = trim( (string) ( $d['label'] ?? '' ) );
			/* translators: 1: a piece, 2: why it is on no sheet. */
			return trim( sprintf( __( '%1$s cannot be laid out. %2$s', 'zorderz' ), '' !== $label ? $label : __( 'A piece', 'zorderz' ), (string) ( $d['message'] ?? '' ) ) );
		}
		if ( ! empty( $plan['runs'] ) ) {
			return __( 'A piece is too long to cut as one piece. Internal orders hold cut pieces only.', 'zorderz' );
		}
		$placed = 0;
		foreach ( (array) ( $plan['pages'] ?? array() ) as $pg ) {
			if ( ! empty( $pg['compressed'] ) ) {
				return __( 'A piece is too long to cut at the bench. Internal orders hold bench pieces only.', 'zorderz' );
			}
			$placed += count( (array) ( $pg['pieces'] ?? array() ) );
		}
		if ( $placed !== $pieces ) {
			/* translators: 1: pieces laid out, 2: pieces asked for. */
			return sprintf( __( 'The cut plan lays out %1$d of the %2$d pieces. Check the sizes.', 'zorderz' ), $placed, $pieces );
		}
		return null;
	}

	/**
	 * The options a shop job is planned with, read from its own row: the stock an order is cut from is the server's
	 * fact, not the request's. Used whenever the server plans by itself (saving an internal order, marking a job
	 * cut) and for every plan of an internal order.
	 */
	public static function plan_options( array $row ): array {
		return array(
			'use_leftovers' => false,
			'source_job'    => self::job_key( (int) ( $row['id'] ?? 0 ) ),
			'workspace'     => 'flat',
			'force_roll'    => max( 0, (int) ( $row['force_roll'] ?? 0 ) ),
			'stock_sheet'   => self::is_internal( $row ) ? self::stock_sheet( $row ) : null,
		);
	}

	/* ================================================================
	 * STORAGE
	 * ================================================================ */

	/** @return array|WP_Error the stored row. */
	public static function create( array $args, int $actor ) {
		$internal = ( self::KIND_INTERNAL === (string) ( $args['kind'] ?? '' ) );
		if ( ! ZPREP_Dashboard::is_manager( $actor ) ) {
			return new WP_Error( 'zprep_remake_forbidden', $internal ? __( 'Only a manager can add an internal order.', 'zorderz' ) : __( 'Only a manager can add a remake.', 'zorderz' ) );
		}
		if ( $internal ) {
			return self::create_internal( $args, $actor );
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

	/**
	 * Save an internal order: checked, then laid out by the engine (saved only when every piece lands on a
	 * sheet), then given its reference code. It has no original job, so no note is posted anywhere.
	 *
	 * @return array|WP_Error the stored row.
	 */
	private static function create_internal( array $args, int $actor ) {
		self::maybe_upgrade();
		$colors = ZPREP_Settings::roll_colors();
		$widths = array_map( static function ( $r ) { return (int) $r['width_in']; }, ZPREP_Settings::rolls() );
		$n      = self::normalize_internal( $args, array_keys( self::purposes() ), array_keys( ZPREP_Settings::internal_kinds() ), $colors, $widths );
		if ( isset( $n['error'] ) ) {
			return new WP_Error( 'zprep_internal_' . $n['error'], self::internal_error_message( $n ) );
		}
		$r      = $n['row'];
		$sheet  = $r['sheet'];
		$pieces = 0;
		foreach ( $r['pieces'] as $v ) {
			$pieces += (int) $v['qty'];
		}
		$as_row  = array(
			'id'           => 0,
			'kind'         => self::KIND_INTERNAL,
			'color'        => $r['color'],
			'force_roll'   => $r['force_roll'],
			'sheet_w'      => $sheet ? $sheet['w'] : 0,
			'sheet_l'      => $sheet ? $sheet['l'] : 0,
			'measurements' => wp_json_encode( $r['pieces'] ),
		);
		$problem = self::plan_problem( self::measurements( $as_row, ZPREP_Settings::default_color() ), self::plan_options( $as_row ), $pieces );
		if ( null !== $problem ) {
			return new WP_Error( 'zprep_internal_no_plan', $problem );
		}

		global $wpdb;
		$now = current_time( 'mysql' );
		$ok  = $wpdb->insert( self::table_name(), array(
			'created_at'     => $now,
			'created_by'     => $actor,
			'parent_job_key' => '',
			'parent_label'   => '',
			'customer_label' => sanitize_text_field( $r['customer_label'] ),
			'reason_code'    => 'other',
			'reason_text'    => sanitize_text_field( $r['reason_text'] ),
			'standard_note'  => '',
			'color'          => $r['color'],
			'force_roll'     => $r['force_roll'],
			'install_date'   => '',
			'measurements'   => wp_json_encode( $r['pieces'] ),
			'status'         => 'open',
			'kind'           => self::KIND_INTERNAL,
			'purpose_code'   => $r['purpose_code'],
			'for_whom'       => sanitize_text_field( $r['for_whom'] ),
			'sheet_w'        => $sheet ? (float) $sheet['w'] : 0.0,
			'sheet_l'        => $sheet ? (float) $sheet['l'] : 0.0,
			'cost_rate'      => self::current_cost_rate( '' !== $r['color'] ? $r['color'] : ZPREP_Settings::default_color() ),
		), array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f' ) );
		if ( ! $ok ) {
			return new WP_Error( 'zprep_internal_db', __( 'Could not save the order.', 'zorderz' ) );
		}
		$id = (int) $wpdb->insert_id;
		// The reference code is worked out from rows already saved, so two orders saved at once (two ids) can never
		// share one. A removed order keeps its number; codes are not used again.
		$wpdb->update( self::table_name(), array( 'ref_code' => self::ref_code_for( $id, $r['purpose_code'], $now ) ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
		$row = self::get( $id );
		ZPREP_Settings::disposition( 'internal_order_created', array( 'remake_id' => $id, 'job_key' => self::job_key( $id ), 'purpose' => $r['purpose_code'], 'sheet' => $sheet ? ( $sheet['w'] . 'x' . $sheet['l'] ) : '' ) );
		return $row;
	}

	/**
	 * INT-<purpose code>-<yy>-<nnn>: nnn counts that year's internal orders up to and including this one, so it can
	 * be worked out again from the table at any time (a count, not a stored counter). Remakes are not counted.
	 */
	public static function ref_code_for( int $id, string $purpose, string $created_at ): string {
		global $wpdb;
		$year = (int) substr( $created_at, 0, 4 );
		if ( $year < 2000 ) {
			$year = (int) gmdate( 'Y' );
		}
		$n = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM `' . self::table_name() . '` WHERE kind = %s AND id <= %d AND created_at >= %s AND created_at < %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a class constant; values are placeholders.
			self::KIND_INTERNAL,
			$id,
			sprintf( '%04d-01-01 00:00:00', $year ),
			sprintf( '%04d-01-01 00:00:00', $year + 1 )
		) );
		$all  = self::purposes();
		$core = ZPREP_Settings::default_internal_purposes();
		$code = (string) ( $all[ $purpose ]['code'] ?? reset( $core )['code'] );
		return sprintf( '%s-%s-%02d-%03d', self::REF_PREFIX, $code, $year % 100, max( 1, $n ) );
	}

	/**
	 * What the cut material costs per square inch today, in the order's colour: the business's own figure
	 * (ZPREP_Settings::material_cost_rate()). 0.0 when none is on file: the order is still saved and the year-end
	 * page says the cost is not on file. Never a figure made up here.
	 */
	public static function current_cost_rate( string $color ): float {
		return ZPREP_Settings::material_cost_rate( $color );
	}

	/**
	 * Hours spent on an order, typed by a person. The app never works a time out by itself, and the row keeps who
	 * typed it and when, so a correction shows whose it is.
	 *
	 * @return array|WP_Error the updated row.
	 */
	public static function set_labor( int $id, $hours, int $actor ) {
		$row = self::get( $id );
		if ( ! $row || ! self::is_internal( $row ) || 'void' === (string) $row['status'] ) {
			return new WP_Error( 'zprep_internal_missing', __( 'That order was not found.', 'zorderz' ) );
		}
		if ( ! is_numeric( $hours ) || (float) $hours < 0 || (float) $hours > self::LABOR_MAX_HOURS ) {
			/* translators: %d: the most hours one order takes. */
			return new WP_Error( 'zprep_internal_bad_hours', sprintf( __( 'Hours is a number from 0 to %d.', 'zorderz' ), self::LABOR_MAX_HOURS ) );
		}
		global $wpdb;
		$ok = $wpdb->update(
			self::table_name(),
			array( 'labor_minutes' => (int) round( (float) $hours * 60 ), 'labor_by' => $actor, 'labor_at' => current_time( 'mysql' ) ),
			array( 'id' => $id ),
			array( '%d', '%d', '%s' ),
			array( '%d' )
		);
		if ( false === $ok ) {
			return new WP_Error( 'zprep_internal_db', __( 'Could not save the hours.', 'zorderz' ) );
		}
		return self::get( $id );
	}

	/** How many stock sheets the cut took, written when the order is marked cut (0 when cut from a roll). */
	public static function set_sheets_used( int $id, int $n ): bool {
		global $wpdb;
		return false !== $wpdb->update( self::table_name(), array( 'sheets_used' => max( 0, min( 65535, $n ) ) ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
	}

	/** Internal orders still to cut (status open), newest first: the ones the cut list shows. */
	public static function list_open_internal(): array {
		self::maybe_upgrade();
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM `' . self::table_name() . '` WHERE kind = %s AND status = %s ORDER BY id DESC LIMIT 200', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a class constant; values are placeholders.
			self::KIND_INTERNAL,
			'open'
		), ARRAY_A );
		return $rows ?: array();
	}

	/** One calendar year's internal orders (by the day they were made), oldest first. Removed ones are left out. */
	public static function list_internal( int $year ): array {
		self::maybe_upgrade();
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM `' . self::table_name() . "` WHERE kind = %s AND status <> 'void' AND created_at >= %s AND created_at < %s ORDER BY id ASC LIMIT 2000", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a class constant; values are placeholders.
			self::KIND_INTERNAL,
			sprintf( '%04d-01-01 00:00:00', $year ),
			sprintf( '%04d-01-01 00:00:00', $year + 1 )
		), ARRAY_A );
		return $rows ?: array();
	}

	/** The years that have an internal order, newest first (the year-end page's picker). */
	public static function internal_years(): array {
		self::maybe_upgrade();
		global $wpdb;
		$rows  = $wpdb->get_col( $wpdb->prepare(
			'SELECT DISTINCT SUBSTR(created_at, 1, 4) FROM `' . self::table_name() . "` WHERE kind = %s AND status <> 'void'", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a class constant; the value is a placeholder.
			self::KIND_INTERNAL
		) );
		$years = array_values( array_unique( array_filter( array_map( 'intval', (array) $rows ), static function ( $y ) { return $y >= 2000; } ) ) );
		rsort( $years );
		return $years;
	}

	/**
	 * What an order adds up to. Counted from the order's own rows, so it can be worked out again at any time.
	 * MONEY: for the administrator's year-end page only; never put on a card (a card goes to whoever cuts).
	 *
	 *   pieces, sq_in    every row has a size (create() refuses one without)
	 *   sheet_sq_in      sheets used x the sheet's area; 0 for a roll, or before the order is marked cut
	 *   material_cost    sq_in x the rate kept on the order; null when no rate was on file
	 *   sell_total       qty x "would sell for" on the rows that have one; rows_unpriced says how many do not
	 */
	public static function figures( array $row ): array {
		$rows = json_decode( (string) ( $row['measurements'] ?? '[]' ), true );
		$rows = is_array( $rows ) ? $rows : array();
		$pieces   = 0;
		$sq_in    = 0.0;
		$sell     = 0.0;
		$priced   = 0;
		$unpriced = 0;
		foreach ( $rows as $v ) {
			$qty = is_array( $v ) ? max( 0, (int) ( $v['qty'] ?? 0 ) ) : 0;
			if ( $qty <= 0 ) {
				continue;
			}
			$pieces += $qty;
			$w       = ( isset( $v['width'] ) && is_numeric( $v['width'] ) ) ? (float) $v['width'] : 0.0;
			$h       = ( isset( $v['height'] ) && is_numeric( $v['height'] ) ) ? (float) $v['height'] : 0.0;
			$sq_in  += $qty * $w * $h;
			if ( isset( $v['sell_each'] ) && is_numeric( $v['sell_each'] ) ) {
				$sell += $qty * (float) $v['sell_each'];
				++$priced;
			} else {
				++$unpriced;
			}
		}
		$rate  = ( isset( $row['cost_rate'] ) && is_numeric( $row['cost_rate'] ) ) ? (float) $row['cost_rate'] : 0.0;
		$sheet = self::stock_sheet( $row );
		$used  = max( 0, (int) ( $row['sheets_used'] ?? 0 ) );
		return array(
			'pieces'        => $pieces,
			'sq_in'         => round( $sq_in, 2 ),
			'sheets_used'   => $sheet ? $used : 0,
			'sheet_sq_in'   => $sheet ? round( $used * $sheet['w'] * $sheet['l'], 2 ) : 0.0,
			'cost_rate'     => $rate,
			'material_cost' => $rate > 0 ? round( $sq_in * $rate, 2 ) : null,
			'cost_note'     => $rate > 0 ? '' : __( 'no rate on file', 'zorderz' ),
			'sell_total'    => $priced > 0 ? round( $sell, 2 ) : null,
			'rows_priced'   => $priced,
			'rows_unpriced' => $unpriced,
			'labor_minutes' => max( 0, (int) ( $row['labor_minutes'] ?? 0 ) ),
		);
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

	/**
	 * The queue cards of $me's shop jobs: for a manager every open or cut remake (internal orders have their own
	 * list: ZPREP_Dashboard::shop_jobs_for()); for anyone else the remakes and internal orders assigned to them.
	 */
	public static function cards_for( int $me, bool $manager ): array {
		if ( $me <= 0 ) {
			return array();
		}
		if ( $manager ) {
			$rows = array_filter( self::list_open(), static function ( $r ) { return ! self::is_internal( $r ) && in_array( (string) $r['status'], array( 'open', 'cut' ), true ); } );
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
		// array_merge: 'kind' below is the remake's, and an internal order's own 'kind' must win.
		return array_merge( array(
			'kind'           => self::KIND_REMAKE,
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
		), self::internal_card_fields( $row ) );
	}

	/**
	 * What a card says about an internal order. NO MONEY: the rate, the cost and the sell prices stay off it (a card
	 * goes to whoever is assigned the cut). Hours are the cutter's own to enter, so they ride.
	 */
	private static function internal_card_fields( array $row ): array {
		if ( ! self::is_internal( $row ) ) {
			return array();
		}
		$fig = self::figures( $row );
		$by  = (int) ( $row['labor_by'] ?? 0 );
		$u   = $by > 0 ? get_userdata( $by ) : null;
		return array(
			'kind'          => self::KIND_INTERNAL,
			'ref_code'      => (string) ( $row['ref_code'] ?? '' ),
			'purpose_code'  => (string) ( $row['purpose_code'] ?? '' ),
			'purpose_label' => self::purpose_label( (string) ( $row['purpose_code'] ?? '' ) ),
			'for_whom'      => (string) ( $row['for_whom'] ?? '' ),
			'sheet'         => self::stock_sheet( $row ), // { w, l } or null (cut from a roll)
			'sheets_used'   => (int) $fig['sheets_used'],
			'pieces'        => (int) $fig['pieces'],
			'sq_in'         => (float) $fig['sq_in'],
			'labor_hours'   => round( $fig['labor_minutes'] / 60, 2 ),
			'labor_set'     => $by > 0,
			'labor_by_name' => $u ? (string) $u->display_name : '',
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
		$lines[] = __( 'Reason:', 'zorderz' ) . ' ' . $reason . ( '' !== $rt ? ', ' . $rt : '' );
		if ( '' !== $std ) {
			$lines[] = $std;
		}
		$lines[] = __( 'Shop-only fabrication record; no billing change.', 'zorderz' );
		return implode( "\n", $lines );
	}
}
