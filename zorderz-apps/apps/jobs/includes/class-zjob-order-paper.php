<?php
/**
 * ZJOB_Order_Paper — a material order printed on the supplier's own order form.
 *
 * The order is the record; the supplier's form is printed FROM it, so the two can never disagree:
 *
 *   ORDER (units, parts, notes, PO, day)  --layout()-->  positions on the blank PDF   --the browser draws (pdf-lib)-->
 *                                                     \-> cells of the blank .xlsx   --xlsx_bytes()--> a download
 *
 * A FORM'S PAPER is a map that travels with the form (its `paper` key): where each value goes on the supplier's blank
 * PDF (boxes in points, top-left frame) and in its spreadsheet (cell references), plus the blank files themselves,
 * each pinned by its SHA-256. A blank that no longer matches its pin is refused until the map is measured again.
 *
 *   paper.pdf   { file, sha256, w, h, size, layout, po, date, ship[5], bill[5], notes, draft, date_format, ... }
 *     layout 'rows'            one row per unit (unit_rows, unit_cols) and per part (part_rows, part_cols); `center`
 *                              names the fields drawn centred
 *     layout 'grid'            a tally: quantities summed into the cell where a row value meets a column value
 *                              (grid {row, col, qty, part_row, part_col}, size_rows, part_rows (keyed), grid_cols) and
 *                              one header line (header)
 *     layout 'sheet_per_unit'  one copy of the blank per unit: text fields (fields {k: box}), a mark in the box of the
 *                              chosen option (marks {k: {option: box}}), a note line per page (page_note)
 *   paper.xlsx  { file, sha256, sheet, po, date, ship[], bill[], unit_first_row, unit_cols, part_first_row, part_cols,
 *                 notes, numeric[] (fields written as numbers), numeric_codes[] (digit-only values written as numbers,
 *                 for forms that look a code up), recalc (ask the spreadsheet to recalculate on open) }
 *
 * WHERE BLANKS LIVE. Core ships one neutral sample (assets/forms/materials-sample.*) for its "Materials list" form.
 * A business's supplier forms are uploaded in the Jobs settings (or carried by its Identity Pack's mappings file plus
 * an upload of the blanks) into wp-content/uploads/zorderz-forms/, which is never served directly: every blank leaves
 * through an endpoint that checks the person may act on the order.
 *
 * THE DRAFT RULE. Until the operator side has confirmed the order as it stands (or it is ordered), every copy says
 * DRAFT on the page and in the spreadsheet's notes, and the file name starts with DRAFT-.
 *
 * Never on a form: an access code (the vault cuts it out of the notes first).
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZJOB_Order_Paper {

	/** Our ship-to / bill-to as printed on supplier forms: { ship: [5 lines], bill: [5 lines], same: bool }. */
	const OPT_COMPANY = 'zjob_order_company';

	/** The folder (under uploads) a business's blanks are kept in. */
	const SITE_DIR = 'zorderz-forms';

	const LAYOUTS = array( 'rows', 'grid', 'sheet_per_unit' );

	const SAMPLE_PDF_SHA  = '960edf5da92c94cbd024372fd59f04bcd7e46b865ed91ae492c09eea84f4545f';
	const SAMPLE_XLSX_SHA = '503c595ee582a69b31cc8dfd81e7cacaea67764d3e81ba26078880e47ca44ff0';

	public static function init(): void {
		add_action( 'wp_ajax_zjob_order_paper', array( __CLASS__, 'ajax' ) );
		// No _nopriv variant.
	}

	/* =====================================================================================================================
	 * THE SAMPLE PAPER (Core) and checking a map
	 * ===================================================================================================================== */

	/** The paper of Core's "Materials list" form: the neutral sample blank, both kinds. */
	public static function sample_paper(): array {
		$ship = array();
		$bill = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$ship[] = array( 36, 98 + $i * 14, 290, 112 + $i * 14 );
			$bill[] = array( 320, 98 + $i * 14, 576, 112 + $i * 14 );
		}
		$rows = array();
		for ( $i = 0; $i < 15; $i++ ) {
			$rows[] = array( 180 + $i * 18, 198 + $i * 18 );
		}
		return array(
			'name' => 'Materials',
			'pdf'  => array(
				'where'  => 'core',
				'file'   => 'materials-sample.pdf',
				'sha256' => self::SAMPLE_PDF_SHA,
				'w'      => 612,
				'h'      => 792,
				'size'   => 9,
				'layout' => 'rows',
				'po'     => array( 430, 30, 576, 46 ),
				'date'   => array( 430, 50, 576, 66 ),
				'ship'   => $ship,
				'bill'   => $bill,
				'unit_rows' => $rows,
				'unit_cols' => array(
					'item'   => array( 36, 250 ),
					'qty'    => array( 250, 300 ),
					'size'   => array( 300, 400 ),
					'colour' => array( 400, 480 ),
					'note'   => array( 480, 576 ),
				),
				'center' => array( 'qty' ),
				'notes'  => array( 36, 476, 576, 546 ),
				'draft'  => array( 36, 556, 576, 572 ),
			),
			'xlsx' => array(
				'where'          => 'core',
				'file'           => 'materials-sample.xlsx',
				'sha256'         => self::SAMPLE_XLSX_SHA,
				'sheet'          => 'xl/worksheets/sheet1.xml',
				'po'             => 'H1',
				'date'           => 'H2',
				'ship'           => array( 'A5', 'A6', 'A7', 'A8', 'A9' ),
				'bill'           => array( 'E5', 'E6', 'E7', 'E8', 'E9' ),
				'unit_first_row' => 12,
				'unit_cols'      => array( 'item' => 'A', 'qty' => 'B', 'size' => 'C', 'colour' => 'D', 'note' => 'E' ),
				'notes'          => 'A29',
				'numeric'        => array( 'qty' ),
			),
		);
	}

	private static function box( $v ): ?array {
		if ( ! is_array( $v ) || 4 !== count( $v ) ) {
			return null;
		}
		$v = array_map( 'floatval', array_values( $v ) );
		return ( $v[2] > $v[0] && $v[3] > $v[1] && $v[0] >= 0 && $v[1] >= 0 ) ? $v : null;
	}

	private static function span( $v ): ?array {
		if ( ! is_array( $v ) || 2 !== count( $v ) ) {
			return null;
		}
		$v = array_map( 'floatval', array_values( $v ) );
		return $v[1] > $v[0] && $v[0] >= 0 ? $v : null;
	}

	private static function key( $k ): string {
		return strtolower( (string) preg_replace( '/[^A-Za-z0-9_]/', '', (string) $k ) );
	}

	private static function cell_ref( $v ): string {
		$v = strtoupper( trim( (string) $v ) );
		return preg_match( '/^[A-Z]{1,3}[1-9]\d{0,5}$/', $v ) ? $v : '';
	}

	private static function col( $v ): string {
		$v = strtoupper( trim( (string) $v ) );
		return preg_match( '/^[A-Z]{1,3}$/', $v ) ? $v : '';
	}

	/** A blank's file name: letters, digits, dot, dash, underscore; .pdf or .xlsx. */
	public static function file_name( $v, string $kind ): string {
		$v = basename( (string) $v );
		return preg_match( '/^[A-Za-z0-9][A-Za-z0-9._\-]{0,80}\.' . ( 'pdf' === $kind ? 'pdf' : 'xlsx' ) . '$/', $v ) ? $v : '';
	}

	/**
	 * A paper map as data, or null when it is not one. Every number, box and cell is checked; anything that is not what
	 * it should be is dropped (a blank without a file and a pin is no blank). PURE.
	 */
	public static function clean_paper( $p ): ?array {
		if ( ! is_array( $p ) ) {
			return null;
		}
		$out = array( 'name' => substr( (string) preg_replace( '/[^A-Za-z0-9 \-]/', '', (string) ( $p['name'] ?? '' ) ), 0, 40 ) );
		$pdf = is_array( $p['pdf'] ?? null ) ? $p['pdf'] : null;
		if ( $pdf ) {
			$lay = (string) ( $pdf['layout'] ?? 'rows' );
			$c   = array(
				'where'  => 'core' === ( $pdf['where'] ?? '' ) ? 'core' : 'site',
				'file'   => self::file_name( $pdf['file'] ?? '', 'pdf' ),
				'sha256' => preg_match( '/^[a-f0-9]{64}$/', (string) ( $pdf['sha256'] ?? '' ) ) ? (string) $pdf['sha256'] : '',
				'w'      => max( 0, (int) ( $pdf['w'] ?? 0 ) ),
				'h'      => max( 0, (int) ( $pdf['h'] ?? 0 ) ),
				'size'   => min( 14.0, max( 5.0, (float) ( $pdf['size'] ?? 9 ) ) ),
				'layout' => in_array( $lay, self::LAYOUTS, true ) ? $lay : 'rows',
				'date_format' => self::date_format( $pdf['date_format'] ?? '' ),
			);
			foreach ( array( 'po', 'date', 'notes', 'draft', 'header', 'page_note' ) as $k ) {
				$b = self::box( $pdf[ $k ] ?? null );
				if ( $b ) {
					$c[ $k ] = $b;
				}
			}
			foreach ( array( 'ship', 'bill' ) as $k ) {
				$c[ $k ] = array_values( array_filter( array_map( array( __CLASS__, 'box' ), array_slice( (array) ( $pdf[ $k ] ?? array() ), 0, 5 ) ) ) );
			}
			foreach ( array( 'unit_rows', 'part_rows' ) as $k ) {
				$list = (array) ( $pdf[ $k ] ?? array() );
				$rows = array();
				foreach ( array_slice( $list, 0, 200, true ) as $rk => $r ) {
					$s = self::span( $r );
					if ( $s ) {
						$rows[ is_int( $rk ) ? $rk : self::row_key( $rk ) ] = $s;
					}
				}
				$c[ $k ] = $rows;
			}
			foreach ( array( 'unit_cols', 'part_cols', 'grid_cols', 'size_rows' ) as $k ) {
				$map = array();
				foreach ( (array) ( $pdf[ $k ] ?? array() ) as $fk => $s ) {
					$s  = self::span( $s );
					$fk = 'grid_cols' === $k || 'size_rows' === $k ? self::row_key( $fk ) : self::key( $fk );
					if ( $s && '' !== $fk ) {
						$map[ $fk ] = $s;
					}
				}
				$c[ $k ] = $map;
			}
			$c['center'] = array_values( array_filter( array_map( array( __CLASS__, 'key' ), (array) ( $pdf['center'] ?? array() ) ) ) );
			$g           = is_array( $pdf['grid'] ?? null ) ? $pdf['grid'] : array();
			$c['grid']   = array();
			foreach ( array( 'row', 'col', 'qty', 'part_row', 'part_col' ) as $k ) {
				$c['grid'][ $k ] = self::key( $g[ $k ] ?? '' );
			}
			$c['fields'] = array();
			foreach ( (array) ( $pdf['fields'] ?? array() ) as $fk => $b ) {
				$b = self::box( $b );
				if ( $b && '' !== self::key( $fk ) ) {
					$c['fields'][ self::key( $fk ) ] = $b;
				}
			}
			$c['marks'] = array();
			foreach ( (array) ( $pdf['marks'] ?? array() ) as $fk => $opts ) {
				foreach ( (array) $opts as $ov => $b ) {
					$b = self::box( $b );
					if ( $b && '' !== self::key( $fk ) ) {
						$c['marks'][ self::key( $fk ) ][ self::row_key( $ov ) ] = $b;
					}
				}
			}
			$c['customer_field'] = self::key( $pdf['customer_field'] ?? '' );
			if ( '' !== $c['file'] && '' !== $c['sha256'] && $c['w'] > 0 && $c['h'] > 0 ) {
				$out['pdf'] = $c;
			}
		}
		$x = is_array( $p['xlsx'] ?? null ) ? $p['xlsx'] : null;
		if ( $x ) {
			$c = array(
				'where'          => 'core' === ( $x['where'] ?? '' ) ? 'core' : 'site',
				'file'           => self::file_name( $x['file'] ?? '', 'xlsx' ),
				'sha256'         => preg_match( '/^[a-f0-9]{64}$/', (string) ( $x['sha256'] ?? '' ) ) ? (string) $x['sha256'] : '',
				'sheet'          => preg_match( '#^xl/worksheets/sheet\d{1,3}\.xml$#', (string) ( $x['sheet'] ?? '' ) ) ? (string) $x['sheet'] : 'xl/worksheets/sheet1.xml',
				'po'             => self::cell_ref( $x['po'] ?? '' ),
				'date'           => self::cell_ref( $x['date'] ?? '' ),
				'notes'          => self::cell_ref( $x['notes'] ?? '' ),
				'unit_first_row' => max( 0, (int) ( $x['unit_first_row'] ?? 0 ) ),
				'part_first_row' => max( 0, (int) ( $x['part_first_row'] ?? 0 ) ),
				'numeric'        => array_values( array_filter( array_map( array( __CLASS__, 'key' ), (array) ( $x['numeric'] ?? array( 'qty' ) ) ) ) ),
				'numeric_codes'  => array_values( array_filter( array_map( array( __CLASS__, 'key' ), (array) ( $x['numeric_codes'] ?? array() ) ) ) ),
				'recalc'         => ! empty( $x['recalc'] ),
				'date_format'    => self::date_format( $x['date_format'] ?? '' ),
			);
			foreach ( array( 'ship', 'bill' ) as $k ) {
				$c[ $k ] = array_values( array_filter( array_map( array( __CLASS__, 'cell_ref' ), array_slice( (array) ( $x[ $k ] ?? array() ), 0, 5 ) ) ) );
			}
			foreach ( array( 'unit_cols', 'part_cols' ) as $k ) {
				$c[ $k ] = array();
				foreach ( (array) ( $x[ $k ] ?? array() ) as $fk => $col ) {
					if ( '' !== self::key( $fk ) && '' !== self::col( $col ) ) {
						$c[ $k ][ self::key( $fk ) ] = self::col( $col );
					}
				}
			}
			if ( '' !== $c['file'] && '' !== $c['sha256'] ) {
				$out['xlsx'] = $c;
			}
		}
		return ( isset( $out['pdf'] ) || isset( $out['xlsx'] ) ) ? $out : null;
	}

	/** A value as a row or column key of a grid ("83x45", "White"): letters, digits, dot, x, dash, space. */
	private static function row_key( $v ): string {
		return substr( trim( (string) preg_replace( '/[^A-Za-z0-9 .x\-_]/', '', (string) $v ) ), 0, 40 );
	}

	/** A date format for the form's date box: PHP date letters, separators only. Core: ISO (Y-m-d). */
	public static function date_format( $v ): string {
		$v = (string) $v;
		return preg_match( '#^[dDjlNSwzWFmMntLoYy /.\-,]{1,20}$#', $v ) ? $v : 'Y-m-d';
	}

	/** A 'Y-m-d' day written the way a form wants it. PURE. */
	public static function day_as( string $ymd, string $format ): string {
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d', substr( $ymd, 0, 10 ), new DateTimeZone( 'UTC' ) );
		return $d ? $d->format( self::date_format( $format ) ) : '';
	}

	/* =====================================================================================================================
	 * BLANKS ON DISK
	 * ===================================================================================================================== */

	/** The folder a business's blanks are kept in ('' when uploads are not available). */
	public static function site_dir(): string {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return '';
		}
		$u = wp_upload_dir( null, false );
		return empty( $u['basedir'] ) ? '' : trailingslashit( (string) $u['basedir'] ) . self::SITE_DIR . '/';
	}

	/** Make the folder and shut it to direct requests (blanks leave only through the endpoint). */
	public static function ensure_site_dir(): string {
		$dir = self::site_dir();
		if ( '' === $dir ) {
			return '';
		}
		if ( ! is_dir( $dir ) && function_exists( 'wp_mkdir_p' ) ) {
			wp_mkdir_p( $dir );
		}
		if ( is_dir( $dir ) ) {
			if ( ! file_exists( $dir . 'index.php' ) ) {
				@file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			if ( ! file_exists( $dir . '.htaccess' ) ) {
				@file_put_contents( $dir . '.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		return $dir;
	}

	/** Where a blank of this paper is on disk ('' when it cannot be). */
	public static function blank_path( array $paper, string $kind ): string {
		$b = $paper[ $kind ] ?? null;
		if ( ! is_array( $b ) || '' === (string) ( $b['file'] ?? '' ) ) {
			return '';
		}
		$dir = 'core' === ( $b['where'] ?? '' ) ? ( defined( 'ZJOB_DIR' ) ? ZJOB_DIR . 'assets/forms/' : dirname( __DIR__ ) . '/assets/forms/' ) : self::site_dir();
		return '' === $dir ? '' : $dir . basename( (string) $b['file'] );
	}

	/** Does the blank on disk still match its pin? */
	public static function blank_ok( array $paper, string $kind ): bool {
		$path = self::blank_path( $paper, $kind );
		$sha  = (string) ( $paper[ $kind ]['sha256'] ?? '' );
		return '' !== $path && '' !== $sha && is_readable( $path ) && hash_equals( $sha, (string) hash_file( 'sha256', $path ) );
	}

	/* =====================================================================================================================
	 * OUR DETAILS (ship-to, bill-to)
	 * ===================================================================================================================== */

	/** Our ship-to / bill-to. Until someone sets them, the business profile's name and address. */
	public static function company(): array {
		$o = function_exists( 'get_option' ) ? get_option( self::OPT_COMPANY, null ) : null;
		if ( is_array( $o ) && isset( $o['ship'] ) ) {
			$ship = array_pad( array_slice( array_map( 'strval', (array) $o['ship'] ), 0, 5 ), 5, '' );
			$same = ! empty( $o['same'] );
			$bill = $same ? $ship : array_pad( array_slice( array_map( 'strval', (array) ( $o['bill'] ?? array() ) ), 0, 5 ), 5, '' );
			return array( 'ship' => $ship, 'bill' => $bill, 'same' => $same, 'set' => true );
		}
		$lines = array();
		if ( class_exists( 'ZDZ_Business_Profile' ) ) {
			$lines[] = (string) ZDZ_Business_Profile::name();
			if ( method_exists( 'ZDZ_Business_Profile', 'address_line' ) ) {
				foreach ( explode( ',', (string) ZDZ_Business_Profile::address_line() ) as $part ) {
					$lines[] = trim( $part );
				}
			}
		}
		$lines = array_pad( array_slice( array_values( array_filter( $lines, 'strlen' ) ), 0, 5 ), 5, '' );
		return array( 'ship' => $lines, 'bill' => $lines, 'same' => true, 'set' => false );
	}

	/** Save our details (lines cleaned to one line of text each). */
	public static function save_company( array $raw, int $uid ): array {
		$clean = static function ( $list ): array {
			$out = array();
			foreach ( array_slice( is_array( $list ) ? array_values( $list ) : array(), 0, 5 ) as $v ) {
				$out[] = class_exists( 'ZJOB_Project_Orders' ) ? ZJOB_Project_Orders::clean_text( $v, 60 ) : substr( trim( (string) $v ), 0, 60 );
			}
			return array_pad( $out, 5, '' );
		};
		$same = ! empty( $raw['same'] );
		update_option( self::OPT_COMPANY, array(
			'ship' => $clean( $raw['ship'] ?? array() ),
			'bill' => $same ? array() : $clean( $raw['bill'] ?? array() ),
			'same' => $same,
			'by'   => $uid,
			'at'   => gmdate( 'Y-m-d H:i:s' ),
		), false );
		return self::company();
	}

	/* =====================================================================================================================
	 * LAYOUT — one function decides what goes where, on the PDF (boxes; the browser draws) and in the spreadsheet (cells)
	 * ===================================================================================================================== */

	/**
	 * Is this order a draft (not yet confirmed by the operator side as it stands, and not ordered)? PURE apart from the
	 * order class's own "current" test.
	 */
	public static function is_draft( array $order ): bool {
		if ( 'ordered' === (string) ( $order['status'] ?? '' ) ) {
			return false;
		}
		$op = is_array( $order['operator_ok'] ?? null ) ? $order['operator_ok'] : null;
		if ( ! $op ) {
			return true;
		}
		return ! ( class_exists( 'ZJOB_Project_Orders' ) && ZJOB_Project_Orders::current( $order, $op ) );
	}

	/**
	 * What goes where. PURE: no database, no files.
	 *
	 * @param array  $order   { form, body {units, parts, notes}, po, status, operator_ok, ordered_at }
	 * @param array  $form    the form (unit_fields, part_fields, paper)
	 * @param array  $company { ship: [5], bill: [5] }
	 * @param string $today   'Y-m-d'
	 * @param bool   $draft   whether the copy is a draft (is_draft())
	 * @return array{pages:array,cells:array,problems:string[],draft:bool,file:string,sha256:string,w:int,h:int,size:float,name:string,pdf:bool,xlsx:bool}
	 */
	public static function layout( array $order, array $form, array $company, string $today, bool $draft ): array {
		$paper = is_array( $form['paper'] ?? null ) ? $form['paper'] : array();
		$pdf   = is_array( $paper['pdf'] ?? null ) ? $paper['pdf'] : array();
		$x     = is_array( $paper['xlsx'] ?? null ) ? $paper['xlsx'] : array();
		$body  = is_array( $order['body'] ?? null ) ? $order['body'] : array();
		if ( isset( $body['notes'] ) && class_exists( 'ZJOB_Sealed' ) && method_exists( 'ZJOB_Sealed', 'cut' ) ) {
			$body['notes'] = (string) ZJOB_Sealed::cut( (string) $body['notes'] ); // never an access code on a form
		}
		$out = array(
			'pages' => array(), 'cells' => array(), 'problems' => array(), 'draft' => $draft,
			'file' => (string) ( $pdf['file'] ?? '' ), 'sha256' => (string) ( $pdf['sha256'] ?? '' ),
			'w' => (int) ( $pdf['w'] ?? 0 ), 'h' => (int) ( $pdf['h'] ?? 0 ), 'size' => (float) ( $pdf['size'] ?? 9 ),
			'name' => '', 'pdf' => ! empty( $pdf ), 'xlsx' => ! empty( $x ),
		);
		if ( ! $pdf && ! $x ) {
			$out['problems'][] = __( 'This form has no printable paper.', 'zorderz' );
			return $out;
		}
		$day   = '' !== (string) ( $body['date'] ?? '' ) ? (string) $body['date'] : ( '' !== (string) ( $order['ordered_at'] ?? '' ) ? substr( (string) $order['ordered_at'], 0, 10 ) : $today );
		$po    = (string) ( $order['po'] ?? '' );
		$short = (string) ( '' !== (string) ( $paper['name'] ?? '' ) ? $paper['name'] : ( $form['label'] ?? 'Order' ) );
		$out['name'] = trim( (string) preg_replace( '/[^A-Za-z0-9-]+/', '-', $short ), '-' ) . '-order'
			. ( '' !== $po ? '-PO-' . preg_replace( '/[^A-Za-z0-9-]+/', '', $po ) : '' ) . '-' . preg_replace( '/[^0-9-]/', '', $day );
		$units  = array_values( (array) ( $body['units'] ?? array() ) );
		$parts  = array_values( (array) ( $body['parts'] ?? array() ) );
		$notes  = (string) ( $body['notes'] ?? '' );
		$fields = array();
		foreach ( (array) ( $form['unit_fields'] ?? array() ) as $fd ) {
			$fields[ (string) $fd['k'] ] = $fd;
		}
		$t = static function ( array $box, $text, string $align = 'left', array $extra = array() ): array {
			return array( 'box' => array_map( 'floatval', $box ), 'text' => (string) $text, 'align' => $align ) + $extra;
		};

		// What the person still has to fill in: a required field left blank on any unit.
		foreach ( $units as $i => $u ) {
			foreach ( $fields as $k => $fd ) {
				if ( ! empty( $fd['required'] ) && '' === (string) ( $u[ $k ] ?? '' ) ) {
					/* translators: 1: unit number, 2: field label. */
					$out['problems'][] = sprintf( __( 'Line %1$d has no %2$s.', 'zorderz' ), $i + 1, strtolower( (string) $fd['label'] ) );
				}
			}
		}
		if ( ! $units && ! $parts ) {
			$out['problems'][] = __( 'Nothing is on this order yet.', 'zorderz' );
		}
		if ( '' === implode( '', array_map( 'strval', (array) ( $company['ship'] ?? array() ) ) ) ) {
			$out['problems'][] = __( 'Our ship-to and bill-to are blank. Set them once under "Our details on supplier forms".', 'zorderz' );
		}

		/* ---- the spreadsheet ---- */
		if ( $x ) {
			$xd = (string) ( $x['date_format'] ?? 'Y-m-d' );
			if ( '' !== (string) ( $x['po'] ?? '' ) ) {
				$out['cells'][ $x['po'] ] = $po;
			}
			if ( '' !== (string) ( $x['date'] ?? '' ) ) {
				$out['cells'][ $x['date'] ] = self::day_as( $day, $xd );
			}
			foreach ( array( 'ship', 'bill' ) as $side ) {
				foreach ( (array) ( $company[ $side ] ?? array() ) as $i => $line ) {
					if ( '' !== (string) $line && isset( $x[ $side ][ $i ] ) ) {
						$out['cells'][ $x[ $side ][ $i ] ] = (string) $line;
					}
				}
			}
			$num   = (array) ( $x['numeric'] ?? array() );
			$codes = (array) ( $x['numeric_codes'] ?? array() );
			foreach ( array( array( $units, 'unit_cols', 'unit_first_row' ), array( $parts, 'part_cols', 'part_first_row' ) ) as $spec ) {
				list( $list, $cols, $first ) = $spec;
				if ( (int) ( $x[ $first ] ?? 0 ) <= 0 ) {
					continue;
				}
				foreach ( $list as $i => $r ) {
					foreach ( (array) ( $x[ $cols ] ?? array() ) as $k => $col ) {
						$v = (string) ( $r[ $k ] ?? '' );
						if ( '' === $v ) {
							continue;
						}
						$ref = $col . ( (int) $x[ $first ] + $i );
						if ( in_array( $k, $num, true ) && is_numeric( $v ) ) {
							$out['cells'][ $ref ] = 0 + $v;
						} elseif ( in_array( $k, $codes, true ) && preg_match( '/^[1-9]\d{0,8}$/', $v ) ) {
							$out['cells'][ $ref ] = (int) $v; // a form that looks a code up needs it as a number
						} else {
							$out['cells'][ $ref ] = $v; // a choice goes on as its stored value: the supplier's own code
						}
					}
				}
			}
			if ( '' !== $notes && '' !== (string) ( $x['notes'] ?? '' ) ) {
				$out['cells'][ $x['notes'] ] = $notes;
			}
			if ( $draft && '' !== (string) ( $x['notes'] ?? '' ) ) {
				$out['cells'][ $x['notes'] ] = trim( __( 'DRAFT - NOT CONFIRMED BY THE OPERATOR.', 'zorderz' ) . ' ' . (string) ( $out['cells'][ $x['notes'] ] ?? '' ) );
			}
		}

		/* ---- the PDF ---- */
		if ( $pdf ) {
			$pd     = (string) ( $pdf['date_format'] ?? 'Y-m-d' );
			$layout = (string) ( $pdf['layout'] ?? 'rows' );
			if ( 'sheet_per_unit' === $layout ) {
				$n = count( $units );
				foreach ( $units as $i => $u ) {
					$page = array();
					$cf   = (string) ( $pdf['customer_field'] ?? '' );
					foreach ( (array) ( $pdf['fields'] ?? array() ) as $k => $box ) {
						if ( '' !== (string) ( $u[ $k ] ?? '' ) ) {
							$page[] = $t( $box, (string) $u[ $k ], in_array( $k, (array) ( $pdf['center'] ?? array() ), true ) ? 'center' : 'left' );
						}
					}
					if ( isset( $pdf['date'] ) ) {
						$page[] = $t( $pdf['date'], self::day_as( $day, $pd ) );
					}
					if ( isset( $pdf['po'] ) && '' !== $po ) {
						$page[] = $t( $pdf['po'], $po );
					}
					foreach ( (array) ( $pdf['marks'] ?? array() ) as $k => $opts ) {
						$v = (string) ( $u[ $k ] ?? '' );
						if ( '' !== $v && isset( $opts[ $v ] ) ) {
							$page[] = $t( $opts[ $v ], 'X', 'center' );
						}
					}
					if ( isset( $pdf['page_note'] ) ) {
						$bits = array_filter( array(
							(string) ( $company['ship'][0] ?? '' ),
							'' !== $po ? 'PO ' . $po : '',
							'' !== $cf && '' !== (string) ( $u[ $cf ] ?? '' ) ? (string) $u[ $cf ] : '',
							/* translators: 1: unit number, 2: units. */
							$n > 1 ? sprintf( __( 'Unit %1$d of %2$d', 'zorderz' ), $i + 1, $n ) : '',
						) );
						$page[] = $t( $pdf['page_note'], implode( '   -   ', $bits ) );
					}
					$out['pages'][] = $page;
				}
			} elseif ( 'grid' === $layout ) {
				$g    = (array) ( $pdf['grid'] ?? array() );
				$page = array();
				$sum  = array();
				foreach ( array( array( $units, 's', 'row', 'col', 'unit' ), array( $parts, 'p', 'part_row', 'part_col', 'part' ) ) as $spec ) {
					list( $list, $kind, $rk, $ck, $what ) = $spec;
					$rowf = (string) ( $g[ $rk ] ?? '' );
					$colf = (string) ( $g[ $ck ] ?? '' );
					$qtyf = '' !== (string) ( $g['qty'] ?? '' ) ? (string) $g['qty'] : 'qty';
					if ( '' === $rowf || '' === $colf ) {
						continue;
					}
					foreach ( $list as $i => $r ) {
						$rv = (string) ( $r[ $rowf ] ?? '' );
						$cv = (string) ( $r[ $colf ] ?? '' );
						if ( '' === $rv || '' === $cv || ! is_numeric( $r[ $qtyf ] ?? null ) ) {
							/* translators: 1: line kind, 2: number. */
							$out['problems'][] = sprintf( __( 'The %1$s on line %2$d needs both of its grid values and a quantity.', 'zorderz' ), $what, $i + 1 );
							continue;
						}
						$key         = $kind . '|' . $rv . '|' . $cv;
						$sum[ $key ] = ( $sum[ $key ] ?? 0 ) + (int) $r[ $qtyf ];
					}
				}
				foreach ( $sum as $key => $qty ) {
					list( $kind, $rv, $cv ) = explode( '|', $key, 3 );
					$rows = 's' === $kind ? (array) ( $pdf['size_rows'] ?? array() ) : (array) ( $pdf['part_rows'] ?? array() );
					$cols = (array) ( $pdf['grid_cols'] ?? array() );
					if ( ! isset( $rows[ $rv ], $cols[ $cv ] ) ) {
						/* translators: 1: row value, 2: column value. */
						$out['problems'][] = sprintf( __( '"%1$s" in "%2$s" has no place on this form.', 'zorderz' ), $rv, $cv );
						continue;
					}
					$page[] = $t( array( $cols[ $cv ][0], $rows[ $rv ][0], $cols[ $cv ][1], $rows[ $rv ][1] ), (string) $qty, 'center' );
				}
				if ( isset( $pdf['header'] ) ) {
					$head   = array_filter( array( (string) ( $company['ship'][0] ?? '' ), '' !== $po ? 'PO ' . $po : '', self::day_as( $day, $pd ) ) );
					$page[] = $t( $pdf['header'], implode( '   -   ', $head ) );
				}
				foreach ( array( 'po' => $po, 'date' => self::day_as( $day, $pd ) ) as $k => $v ) {
					if ( isset( $pdf[ $k ] ) && '' !== $v ) {
						$page[] = $t( $pdf[ $k ], $v );
					}
				}
				if ( '' !== $notes && isset( $pdf['notes'] ) ) {
					$page[] = $t( $pdf['notes'], $notes, 'left', array( 'wrap' => true ) );
				}
				$out['pages'][] = $page;
			} else {
				$page = array();
				foreach ( array( 'po' => $po, 'date' => self::day_as( $day, $pd ) ) as $k => $v ) {
					if ( isset( $pdf[ $k ] ) && '' !== $v ) {
						$page[] = $t( $pdf[ $k ], $v );
					}
				}
				foreach ( array( 'ship', 'bill' ) as $side ) {
					foreach ( (array) ( $company[ $side ] ?? array() ) as $i => $line ) {
						if ( '' !== (string) $line && isset( $pdf[ $side ][ $i ] ) ) {
							$page[] = $t( $pdf[ $side ][ $i ], (string) $line );
						}
					}
				}
				$center = (array) ( $pdf['center'] ?? array() );
				foreach ( array( array( $units, 'unit_rows', 'unit_cols', __( 'line', 'zorderz' ) ), array( $parts, 'part_rows', 'part_cols', __( 'part', 'zorderz' ) ) ) as $spec ) {
					list( $list, $rows_k, $cols_k, $what ) = $spec;
					$rows = array_values( (array) ( $pdf[ $rows_k ] ?? array() ) );
					if ( count( $list ) > count( $rows ) && ( $rows || $list ) && ! empty( $pdf[ $cols_k ] ) ) {
						/* translators: 1: rows on the form, 2: line kind. */
						$out['problems'][] = sprintf( __( 'The form has room for %1$d of these; the rest of the %2$ss are left off the PDF.', 'zorderz' ), count( $rows ), $what );
					}
					foreach ( $list as $i => $r ) {
						if ( ! isset( $rows[ $i ] ) ) {
							break;
						}
						foreach ( (array) ( $pdf[ $cols_k ] ?? array() ) as $k => $xs ) {
							$v = (string) ( $r[ $k ] ?? '' );
							if ( '' === $v ) {
								continue;
							}
							$page[] = $t( array( $xs[0], $rows[ $i ][0], $xs[1], $rows[ $i ][1] ), $v, in_array( $k, $center, true ) ? 'center' : 'left' );
						}
					}
				}
				if ( '' !== $notes && isset( $pdf['notes'] ) ) {
					$page[] = $t( $pdf['notes'], $notes, 'left', array( 'wrap' => true ) );
				}
				$out['pages'][] = $page;
			}
			if ( $draft && isset( $pdf['draft'] ) ) {
				foreach ( $out['pages'] as $i => $p ) {
					$out['pages'][ $i ][] = $t( $pdf['draft'], __( 'DRAFT - not confirmed by the operator', 'zorderz' ), 'left', array( 'mark' => 'draft' ) );
				}
			}
		}
		if ( $draft ) {
			$out['name'] = 'DRAFT-' . $out['name'];
		}
		$out['problems'] = array_values( array_unique( $out['problems'] ) );
		return $out;
	}

	/* =====================================================================================================================
	 * THE SPREADSHEET — the supplier's own .xlsx with the order's cells written in
	 * ===================================================================================================================== */

	/** Column letters -> number (A = 1). PURE. */
	public static function col_num( string $letters ): int {
		$n = 0;
		foreach ( str_split( strtoupper( $letters ) ) as $ch ) {
			$n = $n * 26 + ( ord( $ch ) - 64 );
		}
		return $n;
	}

	/**
	 * Write cells into one worksheet's XML: each target cell keeps its style and gets an inline string (or a number);
	 * a missing cell or row is added in column / row order. Returns '' when the XML cannot be read. PURE.
	 */
	public static function write_cells( string $xml, array $cells ): string {
		if ( empty( $cells ) ) {
			return $xml;
		}
		$doc                     = new DOMDocument();
		$doc->preserveWhiteSpace = true;
		$prev                    = libxml_use_internal_errors( true );
		$ok                      = $doc->loadXML( $xml, LIBXML_NONET );
		libxml_use_internal_errors( $prev );
		if ( ! $ok ) {
			return '';
		}
		$ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
		$xp = new DOMXPath( $doc );
		$xp->registerNamespace( 'm', $ns );
		$data = $xp->query( '//m:sheetData' )->item( 0 );
		if ( ! $data ) {
			return '';
		}
		foreach ( $cells as $ref => $text ) {
			if ( ! preg_match( '/^([A-Z]{1,3})(\d{1,6})$/', (string) $ref, $m ) ) {
				continue;
			}
			$rnum = (int) $m[2];
			$row  = $xp->query( 'm:row[@r="' . $rnum . '"]', $data )->item( 0 );
			if ( ! $row ) {
				$row = $doc->createElementNS( $ns, 'row' );
				$row->setAttribute( 'r', (string) $rnum );
				$after = null;
				foreach ( $xp->query( 'm:row', $data ) as $r ) {
					if ( (int) $r->getAttribute( 'r' ) > $rnum ) {
						$after = $r;
						break;
					}
				}
				$after ? $data->insertBefore( $row, $after ) : $data->appendChild( $row );
			}
			$c = $xp->query( 'm:c[@r="' . $ref . '"]', $row )->item( 0 );
			if ( ! $c ) {
				$c = $doc->createElementNS( $ns, 'c' );
				$c->setAttribute( 'r', (string) $ref );
				$after = null;
				foreach ( $xp->query( 'm:c', $row ) as $cc ) {
					if ( preg_match( '/^([A-Z]+)/', $cc->getAttribute( 'r' ), $cm ) && self::col_num( $cm[1] ) > self::col_num( $m[1] ) ) {
						$after = $cc;
						break;
					}
				}
				$after ? $row->insertBefore( $c, $after ) : $row->appendChild( $c );
			}
			while ( $c->firstChild ) {
				$c->removeChild( $c->firstChild );
			}
			if ( is_int( $text ) || is_float( $text ) ) { // quantities; a PO, a code or a size stays text exactly as typed
				$c->removeAttribute( 't' );
				$c->appendChild( $doc->createElementNS( $ns, 'v', (string) $text ) );
			} else {
				$c->setAttribute( 't', 'inlineStr' );
				$is = $doc->createElementNS( $ns, 'is' );
				$tn = $doc->createElementNS( $ns, 't' );
				$tn->setAttributeNS( 'http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve' );
				$tn->appendChild( $doc->createTextNode( (string) $text ) );
				$is->appendChild( $tn );
				$c->appendChild( $is );
			}
		}
		return (string) $doc->saveXML();
	}

	/** The filled spreadsheet's bytes, or '' when it cannot be made (no blank, a changed blank, no zip support). */
	public static function xlsx_bytes( array $cells, array $paper ): string {
		$x = is_array( $paper['xlsx'] ?? null ) ? $paper['xlsx'] : null;
		if ( ! $x || ! class_exists( 'ZipArchive' ) || ! self::blank_ok( $paper, 'xlsx' ) ) {
			return '';
		}
		$tmp = tempnam( sys_get_temp_dir(), 'zjobord' );
		if ( ! $tmp || ! copy( self::blank_path( $paper, 'xlsx' ), $tmp ) ) {
			return '';
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return '';
		}
		$sheet = (string) $x['sheet'];
		$xml   = $zip->getFromName( $sheet );
		$new   = false === $xml ? '' : self::write_cells( (string) $xml, $cells );
		if ( '' === $new ) {
			$zip->close();
			@unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return '';
		}
		$zip->addFromString( $sheet, $new );
		if ( ! empty( $x['recalc'] ) ) {
			// The form looks values up from what was written: ask the spreadsheet to recalculate when it opens.
			$wb = $zip->getFromName( 'xl/workbook.xml' );
			if ( false !== $wb && preg_match( '/<calcPr\b[^>]*\/>/', $wb ) ) {
				$wb2 = preg_replace_callback( '/<calcPr\b([^>]*)\/>/', static function ( $m ) {
					return '<calcPr' . preg_replace( '/\s(?:fullCalcOnLoad|calcMode)="[^"]*"/', '', $m[1] ) . ' fullCalcOnLoad="1"/>';
				}, $wb, 1 );
				if ( is_string( $wb2 ) && '' !== $wb2 ) {
					$zip->addFromString( 'xl/workbook.xml', $wb2 );
				}
			}
			// A formula cell now holding typed text would leave the calculation chain naming a cell with no formula; the
			// chain is only a cache, so it goes (with its relationship and content type) and is rebuilt on open.
			if ( false !== $zip->locateName( 'xl/calcChain.xml' ) ) {
				$zip->deleteName( 'xl/calcChain.xml' );
				foreach ( array( 'xl/_rels/workbook.xml.rels' => '/<Relationship\b[^>]*Target="calcChain\.xml"[^>]*\/>/', '[Content_Types].xml' => '/<Override\b[^>]*PartName="\/xl\/calcChain\.xml"[^>]*\/>/' ) as $part => $re ) {
					$p = $zip->getFromName( $part );
					if ( false !== $p ) {
						$zip->addFromString( $part, (string) preg_replace( $re, '', $p ) );
					}
				}
			}
		}
		$zip->close();
		$bytes = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		@unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $bytes;
	}

	/* =====================================================================================================================
	 * THE ENDPOINT — fill (what to draw), blank (the PDF blank's bytes), xlsx (a download), company (our details)
	 * ===================================================================================================================== */

	/** The order, its Project and the person's sides, or an error. Every check is the order class's own. */
	private static function gate_order( int $uid, int $order_id ) {
		if ( ! class_exists( 'ZJOB_Project_Orders' ) || ! ZJOB_Project_Orders::ready() ) {
			return new WP_Error( 'zjob_paper', __( 'Material orders are not set up yet.', 'zorderz' ) );
		}
		$order = ZJOB_Project_Orders::get( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'zjob_paper', __( 'That order could not be found.', 'zorderz' ) );
		}
		$pid     = (string) $order['project_id'];
		$project = ZJOB_Project_Orders::STOCK_PROJECT === $pid ? ZJOB_Project_Orders::stock_project() : ( class_exists( 'Zjob_Project' ) ? Zjob_Project::get( $pid ) : null );
		if ( ! $project ) {
			return new WP_Error( 'zjob_paper', __( 'That order could not be found.', 'zorderz' ) );
		}
		if ( ZJOB_Project_Orders::STOCK_PROJECT === $pid ) {
			if ( ! ZJOB_Project_Orders::desk_ok( $uid ) ) {
				return new WP_Error( 'zjob_paper', __( 'That order could not be found.', 'zorderz' ) );
			}
			$jobs = array();
		} else {
			if ( ! class_exists( 'Zjob_Project_Visibility' ) || ! Zjob_Project_Visibility::actor_may_use_project( $uid, $project ) ) {
				return new WP_Error( 'zjob_paper', __( 'That order could not be found.', 'zorderz' ) );
			}
			$jobs = Zjob_Project::jobs_for( $pid );
		}
		$sides = ZJOB_Project_Orders::sides( $uid, $project );
		if ( ! $sides ) {
			return new WP_Error( 'zjob_paper', __( 'You can see this order but not print it.', 'zorderz' ) );
		}
		$order = ZJOB_Project_Orders::sync_po( $order, $project );
		$form  = ZJOB_Project_Orders::form( (string) $order['form'] );
		if ( ! $form || empty( $form['paper'] ) ) {
			return new WP_Error( 'zjob_paper', __( 'This form has no printable paper.', 'zorderz' ) );
		}
		// How an order leaves the app: the go sign and the PO come first.
		$why = ZJOB_Project_Orders::agreement_refusal( $order, $project, $jobs );
		$why = '' !== $why ? $why : ZJOB_Project_Orders::po_refusal( $order );
		if ( '' !== $why ) {
			return new WP_Error( 'zjob_paper', $why );
		}
		return array( $order, $project, $form );
	}

	private static function today(): string {
		return function_exists( 'current_time' ) ? (string) current_time( 'Y-m-d' ) : gmdate( 'Y-m-d' );
	}

	public static function ajax(): void {
		$get = isset( $_GET['op'] ) && in_array( $_GET['op'], array( 'blank', 'xlsx' ), true ); // phpcs:ignore WordPress.Security.NonceVerification -- checked next.
		check_ajax_referer( ZJOB_NONCE, 'nonce' );
		$uid = (int) get_current_user_id();
		$pv  = class_exists( 'ZDZ_Privacy_View' ) && ZDZ_Privacy_View::is_on( $uid );
		if ( $uid <= 0 || ! ( class_exists( 'ZJOB_My_Next' ) ? ZJOB_My_Next::is_person( $uid ) : true ) || $pv ) {
			self::refuse( __( 'Not available on this account.', 'zorderz' ), 403, $get );
		}
		$in = $get ? $_GET : $_POST; // phpcs:ignore WordPress.Security.NonceVerification -- checked above.
		$op = sanitize_key( wp_unslash( (string) ( $in['op'] ?? '' ) ) );
		if ( 'company' === $op ) {
			if ( ! class_exists( 'ZJOB_Project_Orders' ) || ! ZJOB_Project_Orders::desk_ok( $uid ) ) {
				self::refuse( __( 'Only the people who check and order materials can change our details.', 'zorderz' ), 403, false );
			}
			$raw = json_decode( wp_unslash( (string) ( $in['company'] ?? '' ) ), true );
			if ( ! is_array( $raw ) ) {
				self::refuse( __( 'Nothing arrived. Try again.', 'zorderz' ), 400, false );
			}
			wp_send_json_success( array( 'company' => self::save_company( $raw, $uid ) ) );
		}
		$g = self::gate_order( $uid, (int) ( $in['order_id'] ?? 0 ) );
		if ( is_wp_error( $g ) ) {
			self::refuse( $g->get_error_message(), 409, $get );
		}
		list( $order, $project, $form ) = $g;
		$paper = (array) $form['paper'];
		$l     = self::layout( $order, $form, self::company(), self::today(), self::is_draft( $order ) );
		switch ( $op ) {
			case 'fill':
				if ( empty( $paper['pdf'] ) ) {
					self::refuse( __( 'This form is a spreadsheet only. Use the Excel file.', 'zorderz' ), 400, false );
				}
				if ( ! self::blank_ok( $paper, 'pdf' ) ) {
					self::refuse( __( 'The blank form on the site does not match its map. It needs measuring again before it can be filled.', 'zorderz' ), 500, false );
				}
				unset( $l['cells'] );
				wp_send_json_success( $l );
				break;
			case 'blank':
				if ( empty( $paper['pdf'] ) || ! self::blank_ok( $paper, 'pdf' ) ) {
					self::refuse( __( 'The blank form could not be read.', 'zorderz' ), 404, true );
				}
				self::send_file( (string) file_get_contents( self::blank_path( $paper, 'pdf' ) ), 'application/pdf', 'blank.pdf', false ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				break;
			case 'xlsx':
				$bytes = self::xlsx_bytes( $l['cells'], $paper );
				if ( '' === $bytes ) {
					self::refuse( __( 'This form has no spreadsheet, or it could not be made.', 'zorderz' ), 400, true );
				}
				if ( class_exists( 'ZDZ_Usage' ) && method_exists( 'ZDZ_Usage', 'record' ) ) {
					ZDZ_Usage::record( 'jobs', 'order_form_made', (int) $order['id'] );
				}
				self::send_file( $bytes, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $l['name'] . '.xlsx', true );
				break;
			default:
				self::refuse( __( 'That is not something an order form can do.', 'zorderz' ), 400, $get );
		}
	}

	/** A refusal: JSON for the app, a short page with a way back for a plain link. */
	private static function refuse( string $why, int $code, bool $page ): void {
		if ( $page && function_exists( 'wp_die' ) ) {
			wp_die( esc_html( $why ), esc_html__( 'Order form not made', 'zorderz' ), array( 'response' => (int) $code, 'back_link' => true ) );
		}
		wp_send_json_error( array( 'message' => $why ), $code );
	}

	private static function send_file( string $bytes, string $type, string $name, bool $download ): void {
		nocache_headers();
		header( 'Content-Type: ' . $type );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: ' . ( $download ? 'attachment' : 'inline' ) . '; filename="' . preg_replace( '/[^A-Za-z0-9._-]+/', '-', $name ) . '"' );
		header( 'Content-Length: ' . strlen( $bytes ) );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a file download.
		exit;
	}
}
