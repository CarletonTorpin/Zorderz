<?php
/**
 * ZPREP_Internal_Report — the year-end page for internal orders (Prep → Internal orders).
 *
 * Adds up what the shop cut for itself in one year, by what it was for: orders, pieces, square inches, sheets used,
 * material cost, what it would sell for, hours, who cut it, and each order's reference code; with a spreadsheet. It
 * reads only Prep's own tables (the orders and the cut ledger) and writes nothing.
 *
 * HOW EACH FIGURE IS WORKED OUT (also printed on the page, so the reader can check it):
 *   pieces, sq in     from the order's own rows (quantity x width x height).
 *   sheets            how many stock sheets the cut plan took, recorded when the order was marked cut.
 *   material cost     sq in x what the material cost per square inch when the order was made (kept on the order, so
 *                     a later change does not move a past year). Blank, with the reason, when no rate was on file.
 *   would sell for    quantity x the price a person typed on each row; rows with no price are counted, not guessed.
 *   hours             typed by a person on the order.
 * Nothing here is an estimate made by the app. A figure that is missing is said to be missing and counted beside its
 * total: hours nobody typed are "not entered", never 0, and a total with nothing in it is blank, never 0.00.
 *
 * WHO: manage_options only (the page, the menu item and the spreadsheet each check). Money appears here and nowhere
 * else in Prep: an order's card, which goes to whoever cuts it, carries none.
 *
 * @package Zorderz\Prep
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZPREP_Internal_Report {

	const SLUG       = 'zprep-internal-orders';
	const CSV_ACTION = 'zprep_internal_orders_csv';
	const CAP        = 'manage_options';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 20 ); // after Prep's own menu
		add_action( 'admin_post_' . self::CSV_ACTION, array( __CLASS__, 'handle_csv' ) );
	}

	public static function register_page(): void {
		add_submenu_page( ZPREP_Admin::PAGE, __( 'Internal orders', 'zorderz' ), __( 'Internal orders', 'zorderz' ), self::CAP, self::SLUG, array( __CLASS__, 'render_page' ) );
	}

	/* ================================================================
	 * THE FIGURES — reads only.
	 * ================================================================ */

	/** One line per internal order made in $year (removed ones left out), oldest first. */
	public static function rows( int $year ): array {
		$out = array();
		foreach ( ZPREP_Remakes::list_internal( $year ) as $row ) {
			$out[] = self::line( $row );
		}
		return $out;
	}

	/** One order as the page shows it. */
	public static function line( array $row ): array {
		$fig   = ZPREP_Remakes::figures( $row );
		$sheet = ZPREP_Remakes::stock_sheet( $row );
		$id    = (int) $row['id'];

		$cut_by    = '';
		$cut_on    = '';
		$cut_state = '';
		if ( class_exists( 'ZPREP_Cut_State' ) ) {
			$cs        = ZPREP_Cut_State::pick( ZPREP_Cut_State::rows_for_job( ZPREP_Remakes::job_key( $id ) ) );
			$cut_state = (string) ( $cs['state'] ?? '' );
			$names     = array();
			foreach ( (array) ( $cs['cut_by_all'] ?? array() ) as $uid ) {
				$n = self::user_name( (int) $uid );
				if ( '' !== $n ) {
					$names[] = $n;
				}
			}
			$cut_by = implode( ', ', $names );
			if ( ! empty( $cs['cut_at'] ) ) {
				$cut_on = self::local_day( (string) $cs['cut_at'] );
			}
		}
		if ( $sheet ) {
			/* translators: 1: sheet width, 2: sheet length (inches). */
			$stock = sprintf( __( '%1$s x %2$s in. sheets', 'zorderz' ), ZPREP_Remakes::num( (float) $sheet['w'] ), ZPREP_Remakes::num( (float) $sheet['l'] ) );
		} elseif ( (int) ( $row['force_roll'] ?? 0 ) > 0 ) {
			/* translators: %d: roll width in inches. */
			$stock = sprintf( __( '%d in. roll', 'zorderz' ), (int) $row['force_roll'] );
		} else {
			$stock = __( 'roll', 'zorderz' );
		}
		$purpose = (string) ( $row['purpose_code'] ?? '' );
		$color   = (string) ( $row['color'] ?? '' );
		return array(
			'id'            => $id,
			'ref_code'      => (string) ( $row['ref_code'] ?? '' ),
			'made_on'       => substr( (string) ( $row['created_at'] ?? '' ), 0, 10 ),
			'name'          => (string) ( $row['customer_label'] ?? '' ),
			'purpose_code'  => $purpose,
			'purpose'       => ZPREP_Remakes::purpose_label( $purpose ),
			'for_whom'      => (string) ( $row['for_whom'] ?? '' ),
			'why'           => (string) ( $row['reason_text'] ?? '' ),
			'color'         => '' !== $color ? $color : ZPREP_Settings::default_color(),
			'stock'         => $stock,
			'pieces'        => (int) $fig['pieces'],
			'sq_in'         => (float) $fig['sq_in'],
			'sheets_used'   => (int) $fig['sheets_used'],
			'sheet_sq_in'   => (float) $fig['sheet_sq_in'],
			'cost_rate'     => (float) $fig['cost_rate'],
			'material_cost' => $fig['material_cost'], // float, or null: not on file
			'cost_note'     => (string) $fig['cost_note'],
			'sell_total'    => $fig['sell_total'],    // float, or null: no row has a price
			'rows_unpriced' => (int) $fig['rows_unpriced'],
			// Hours nobody has typed are not zero hours: labor_set says which this is.
			'labor_set'     => (int) ( $row['labor_by'] ?? 0 ) > 0,
			'labor_minutes' => (int) $fig['labor_minutes'],
			'labor_hours'   => round( $fig['labor_minutes'] / 60, 2 ),
			'labor_by'      => self::user_name( (int) ( $row['labor_by'] ?? 0 ) ),
			'cut'           => ( 'complete' === $cut_state ),
			'cut_by'        => $cut_by,
			'cut_on'        => $cut_on,
			'made_by'       => self::user_name( (int) ( $row['created_by'] ?? 0 ) ),
		);
	}

	/**
	 * Totals for the year: one line per purpose that has an order, then 'all'. A sum never hides a gap: beside each
	 * total is how many orders (or rows) it could not include. PURE apart from the purposes' order.
	 */
	public static function totals( array $lines ): array {
		$blank = array(
			'orders' => 0, 'cut' => 0, 'pieces' => 0, 'sq_in' => 0.0, 'sheets_used' => 0, 'sheet_sq_in' => 0.0,
			'material_cost' => 0.0, 'orders_no_cost' => 0, 'sell_total' => 0.0, 'orders_priced' => 0, 'rows_unpriced' => 0,
			'labor_minutes' => 0, 'labor_hours' => 0.0, 'orders_no_hours' => 0,
		);
		$by           = array();
		$all          = $blank;
		$all['label'] = __( 'All internal orders', 'zorderz' );
		foreach ( $lines as $l ) {
			$k = (string) $l['purpose_code'];
			if ( ! isset( $by[ $k ] ) ) {
				$by[ $k ]          = $blank;
				$by[ $k ]['label'] = (string) $l['purpose'];
			}
			$by[ $k ] = self::add_line( $by[ $k ], $l );
			$all      = self::add_line( $all, $l );
		}
		// The purposes in the order the form lists them, then any that are no longer offered.
		$ordered = array();
		foreach ( array_keys( ZPREP_Remakes::purposes() ) as $k ) {
			if ( isset( $by[ $k ] ) ) {
				$ordered[ $k ] = self::round_totals( $by[ $k ] );
				unset( $by[ $k ] );
			}
		}
		foreach ( $by as $k => $t ) {
			$ordered[ $k ] = self::round_totals( $t );
		}
		return array( 'by_purpose' => $ordered, 'all' => self::round_totals( $all ) );
	}

	/** One order added to a running total. PURE. */
	private static function add_line( array $t, array $l ): array {
		$t['orders']++;
		if ( ! empty( $l['cut'] ) ) {
			$t['cut']++;
		}
		$t['pieces']      += (int) $l['pieces'];
		$t['sq_in']       += (float) $l['sq_in'];
		$t['sheets_used'] += (int) $l['sheets_used'];
		$t['sheet_sq_in'] += (float) $l['sheet_sq_in'];
		if ( null === $l['material_cost'] ) {
			$t['orders_no_cost']++;
		} else {
			$t['material_cost'] += (float) $l['material_cost'];
		}
		if ( null !== $l['sell_total'] ) {
			$t['sell_total'] += (float) $l['sell_total'];
			$t['orders_priced']++;
		}
		$t['rows_unpriced'] += (int) $l['rows_unpriced'];
		// Minutes are added, then turned into hours once (three 20-minute orders are 1 h, not 0.99).
		if ( ! empty( $l['labor_set'] ) ) {
			$t['labor_minutes'] += (int) $l['labor_minutes'];
		} else {
			$t['orders_no_hours']++;
		}
		return $t;
	}

	/**
	 * Round for the page. A total with nothing in it is null, not 0: "no order had a cost on file" must not read as
	 * 0.00 (and likewise a price, and hours). PURE.
	 */
	private static function round_totals( array $t ): array {
		$t['labor_hours'] = $t['labor_minutes'] / 60;
		foreach ( array( 'sq_in', 'sheet_sq_in', 'material_cost', 'sell_total', 'labor_hours' ) as $k ) {
			$t[ $k ] = round( (float) $t[ $k ], 2 );
		}
		if ( $t['orders'] > 0 && $t['orders_no_cost'] >= $t['orders'] ) {
			$t['material_cost'] = null;
		}
		if ( $t['orders'] > 0 && 0 === $t['orders_priced'] ) {
			$t['sell_total'] = null;
		}
		if ( $t['orders'] > 0 && $t['orders_no_hours'] >= $t['orders'] ) {
			$t['labor_hours'] = null;
		}
		return $t;
	}

	/** The spreadsheet: a header row, then one row per order. Plain values (no currency sign), so it adds up. */
	public static function csv_rows( array $lines ): array {
		$out   = array();
		$out[] = array(
			__( 'Reference', 'zorderz' ), __( 'Made on', 'zorderz' ), __( 'Order', 'zorderz' ), __( 'What for', 'zorderz' ), __( 'Who for', 'zorderz' ),
			__( 'Why', 'zorderz' ), __( 'Colour', 'zorderz' ), __( 'Cut from', 'zorderz' ), __( 'Pieces', 'zorderz' ), __( 'Sq in (pieces)', 'zorderz' ),
			__( 'Sheets used', 'zorderz' ), __( 'Sq in (whole sheets)', 'zorderz' ), __( 'Cost rate per sq in', 'zorderz' ), __( 'Material cost', 'zorderz' ),
			__( 'Cost note', 'zorderz' ), __( 'Would sell for', 'zorderz' ), __( 'Rows with no price', 'zorderz' ), __( 'Hours', 'zorderz' ),
			__( 'Hours entered by', 'zorderz' ), __( 'Cut', 'zorderz' ), __( 'Cut by', 'zorderz' ), __( 'Cut on', 'zorderz' ), __( 'Made by', 'zorderz' ),
		);
		foreach ( $lines as $l ) {
			$out[] = array(
				self::csv_text( (string) $l['ref_code'] ),
				$l['made_on'],
				self::csv_text( (string) $l['name'] ),
				self::csv_text( (string) $l['purpose'] ),
				self::csv_text( (string) $l['for_whom'] ),
				self::csv_text( (string) $l['why'] ),
				self::csv_text( (string) $l['color'] ),
				self::csv_text( (string) $l['stock'] ),
				$l['pieces'],
				self::plain( (float) $l['sq_in'] ),
				$l['sheets_used'],
				self::plain( (float) $l['sheet_sq_in'] ),
				$l['cost_rate'] > 0 ? rtrim( rtrim( number_format( (float) $l['cost_rate'], 6, '.', '' ), '0' ), '.' ) : '',
				null === $l['material_cost'] ? '' : number_format( (float) $l['material_cost'], 2, '.', '' ),
				self::csv_text( (string) $l['cost_note'] ),
				null === $l['sell_total'] ? '' : number_format( (float) $l['sell_total'], 2, '.', '' ),
				$l['rows_unpriced'],
				$l['labor_set'] ? self::plain( (float) $l['labor_hours'] ) : '',
				self::csv_text( (string) $l['labor_by'] ),
				$l['cut'] ? __( 'yes', 'zorderz' ) : __( 'no', 'zorderz' ),
				self::csv_text( (string) $l['cut_by'] ),
				$l['cut_on'],
				self::csv_text( (string) $l['made_by'] ),
			);
		}
		return $out;
	}

	/** A typed text cell must never be read by a spreadsheet as a formula. PURE. */
	public static function csv_text( string $s ): string {
		$s = trim( (string) preg_replace( '/[\r\n\t]+/', ' ', $s ) );
		return ( '' !== $s && false !== strpos( '=+-@', $s[0] ) ) ? "'" . $s : $s;
	}

	private static function plain( float $n ): string {
		$s = rtrim( rtrim( number_format( $n, 2, '.', '' ), '0' ), '.' );
		return '' !== $s ? $s : '0';
	}

	private static function user_name( int $uid ): string {
		$u = $uid > 0 ? get_userdata( $uid ) : null;
		return $u ? (string) $u->display_name : '';
	}

	/** A UTC 'Y-m-d H:i:s' as the site's local day. */
	private static function local_day( string $utc ): string {
		if ( function_exists( 'get_date_from_gmt' ) ) {
			$d = get_date_from_gmt( $utc, 'Y-m-d' );
			if ( is_string( $d ) && '' !== $d ) {
				return $d;
			}
		}
		return substr( $utc, 0, 10 );
	}

	/** The year asked for (?year=), kept to a sensible four digits; this year by default. */
	public static function asked_year(): int {
		$now = (int) current_time( 'Y' );
		$y   = isset( $_GET['year'] ) ? (int) $_GET['year'] : $now; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read: the value only picks which year is shown, and is cast to an integer and range-checked on the next line.
		return ( $y >= 2000 && $y <= $now + 1 ) ? $y : $now;
	}

	/** An amount with the business's currency sign; '' for a figure that is not there. */
	private static function money( $n ): string {
		if ( null === $n ) {
			return '';
		}
		$sign = class_exists( 'ZDZ_Business_Profile' ) ? (string) ZDZ_Business_Profile::get( 'locale.currency_sign', '$' ) : '$';
		return $sign . ( class_exists( 'ZDZ_Business_Profile' ) ? ZDZ_Business_Profile::number( (float) $n, 2 ) : number_format( (float) $n, 2 ) );
	}

	/** 24 not 24.00; 22.5 as 22.50. */
	private static function count_text( $n ): string {
		$n = (float) $n;
		return number_format( $n, ( abs( $n - round( $n ) ) < 0.005 ) ? 0 : 2 );
	}

	/* ================================================================
	 * THE SPREADSHEET
	 * ================================================================ */

	public static function handle_csv(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'zorderz' ), 403 );
		}
		check_admin_referer( self::CSV_ACTION );
		$year  = self::asked_year();
		$lines = self::rows( $year );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="internal-orders-' . $year . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		$fh = fopen( 'php://output', 'w' );
		self::write_csv( $fh, self::csv_rows( $lines ) );
		fclose( $fh );
		exit;
	}

	/**
	 * Write the rows as CSV. The escape character is switched OFF (the last argument): with PHP's default, a quote
	 * that follows a backslash is not doubled, so a typed  x\",=1+1,"  would end its cell early and hand a
	 * spreadsheet "=1+1" as a cell of its own, past csv_text()'s guard. With it off every quote is doubled, which is
	 * what a spreadsheet expects.
	 *
	 * @param resource $fh
	 */
	public static function write_csv( $fh, array $rows ): void {
		fwrite( $fh, "\xEF\xBB\xBF" ); // so a spreadsheet reads it as UTF-8
		foreach ( $rows as $r ) {
			fputcsv( $fh, $r, ',', '"', '' );
		}
	}

	/* ================================================================
	 * THE PAGE
	 * ================================================================ */

	public static function render_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'zorderz' ), 403 );
		}
		$year  = self::asked_year();
		$lines = self::rows( $year );
		$tot   = self::totals( $lines );
		$years = ZPREP_Remakes::internal_years();
		if ( ! in_array( $year, $years, true ) ) {
			$years[] = $year;
			rsort( $years );
		}
		$csv = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::CSV_ACTION . '&year=' . $year ), self::CSV_ACTION );
		?>
		<div class="wrap zprep-ior">
			<h1><?php esc_html_e( 'Internal orders', 'zorderz' ); ?></h1>
			<p><?php esc_html_e( 'What the shop cut for itself. These orders are made in Prep (Internal orders) and never go to the CRM or to billing.', 'zorderz' ); ?></p>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin:12px 0;">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<label for="zprep-ior-year"><strong><?php esc_html_e( 'Year', 'zorderz' ); ?></strong></label>
				<select id="zprep-ior-year" name="year">
					<?php foreach ( $years as $y ) : ?>
						<option value="<?php echo (int) $y; ?>" <?php selected( (int) $y, $year ); ?>><?php echo (int) $y; ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Show', 'zorderz' ); ?></button>
				<?php if ( $lines ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( $csv ); ?>">
						<?php
						/* translators: %d: a year. */
						echo esc_html( sprintf( __( 'Download the spreadsheet (%d)', 'zorderz' ), $year ) );
						?>
					</a>
				<?php endif; ?>
			</form>

			<?php if ( ! $lines ) : ?>
				<p><em>
					<?php
					/* translators: %d: a year. */
					echo esc_html( sprintf( __( 'No internal orders were made in %d.', 'zorderz' ), $year ) );
					?>
				</em></p>
			<?php else : ?>

			<h2>
				<?php
				/* translators: %d: a year. */
				echo esc_html( sprintf( __( '%d by what it was for', 'zorderz' ), $year ) );
				?>
			</h2>
			<table class="widefat striped" style="max-width:1100px;">
				<thead><tr>
					<th><?php esc_html_e( 'What for', 'zorderz' ); ?></th><th><?php esc_html_e( 'Orders', 'zorderz' ); ?></th><th><?php esc_html_e( 'Cut', 'zorderz' ); ?></th>
					<th><?php esc_html_e( 'Pieces', 'zorderz' ); ?></th><th><?php esc_html_e( 'Sq in', 'zorderz' ); ?></th><th><?php esc_html_e( 'Sheets used', 'zorderz' ); ?></th>
					<th><?php esc_html_e( 'Material cost', 'zorderz' ); ?></th><th><?php esc_html_e( 'Would sell for', 'zorderz' ); ?></th><th><?php esc_html_e( 'Hours', 'zorderz' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $tot['by_purpose'] as $t ) : ?>
					<tr><?php self::totals_cells( $t, false ); ?></tr>
				<?php endforeach; ?>
				</tbody>
				<tfoot><tr><?php self::totals_cells( $tot['all'], true ); ?></tr></tfoot>
			</table>

			<h2><?php esc_html_e( 'Each order', 'zorderz' ); ?></h2>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Reference', 'zorderz' ); ?></th><th><?php esc_html_e( 'Made', 'zorderz' ); ?></th><th><?php esc_html_e( 'Order', 'zorderz' ); ?></th>
					<th><?php esc_html_e( 'What for', 'zorderz' ); ?></th><th><?php esc_html_e( 'Who for', 'zorderz' ); ?></th><th><?php esc_html_e( 'Cut from', 'zorderz' ); ?></th>
					<th><?php esc_html_e( 'Pieces', 'zorderz' ); ?></th><th><?php esc_html_e( 'Sq in', 'zorderz' ); ?></th><th><?php esc_html_e( 'Sheets', 'zorderz' ); ?></th>
					<th><?php esc_html_e( 'Material cost', 'zorderz' ); ?></th><th><?php esc_html_e( 'Would sell for', 'zorderz' ); ?></th><th><?php esc_html_e( 'Hours', 'zorderz' ); ?></th>
					<th><?php esc_html_e( 'Cut', 'zorderz' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $lines as $l ) : ?>
					<tr>
						<td><code><?php echo esc_html( $l['ref_code'] ); ?></code></td>
						<td><?php echo esc_html( $l['made_on'] ); ?></td>
						<td><strong><?php echo esc_html( $l['name'] ); ?></strong><?php if ( '' !== $l['why'] ) : ?><br><span class="description"><?php echo esc_html( $l['why'] ); ?></span><?php endif; ?></td>
						<td><?php echo esc_html( $l['purpose'] ); ?></td>
						<td><?php echo esc_html( $l['for_whom'] ); ?></td>
						<td><?php echo esc_html( $l['stock'] . ', ' . $l['color'] ); ?></td>
						<td><?php echo (int) $l['pieces']; ?></td>
						<td><?php echo esc_html( self::count_text( $l['sq_in'] ) ); ?></td>
						<td><?php echo esc_html( $l['sheets_used'] > 0 ? (string) (int) $l['sheets_used'] : '' ); ?></td>
						<td>
							<?php if ( null === $l['material_cost'] ) : ?>
								<span class="description"><?php echo esc_html( $l['cost_note'] ); ?></span>
							<?php else : ?>
								<?php echo esc_html( self::money( $l['material_cost'] ) ); ?>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( null === $l['sell_total'] ) : ?>
								<span class="description"><?php esc_html_e( 'not priced', 'zorderz' ); ?></span>
							<?php else : ?>
								<?php echo esc_html( self::money( $l['sell_total'] ) ); ?>
								<?php if ( $l['rows_unpriced'] > 0 ) : ?>
									<span class="description">(<?php echo esc_html( self::rows_no_price( (int) $l['rows_unpriced'] ) ); ?>)</span>
								<?php endif; ?>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $l['labor_set'] ) : ?>
								<?php echo esc_html( self::count_text( $l['labor_hours'] ) ); ?>
								<?php if ( '' !== $l['labor_by'] ) : ?>
									<br><span class="description">
									<?php
									/* translators: %s: a person's name. */
									echo esc_html( sprintf( __( 'entered by %s', 'zorderz' ), $l['labor_by'] ) );
									?>
									</span>
								<?php endif; ?>
							<?php else : ?>
								<span class="description"><?php esc_html_e( 'not entered', 'zorderz' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $l['cut'] ) : ?>
								<?php esc_html_e( 'Yes', 'zorderz' ); ?>
								<?php if ( '' !== $l['cut_by'] ) : ?>
									<br><span class="description"><?php echo esc_html( $l['cut_by'] . ( '' !== $l['cut_on'] ? ', ' . $l['cut_on'] : '' ) ); ?></span>
								<?php endif; ?>
							<?php else : ?>
								<?php esc_html_e( 'Not yet', 'zorderz' ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'How each figure is worked out', 'zorderz' ); ?></h2>
			<ul style="list-style:disc;margin-left:20px;max-width:900px;">
				<li><?php esc_html_e( 'Pieces and sq in come from the order\'s own rows: quantity × width × height.', 'zorderz' ); ?></li>
				<li><?php esc_html_e( 'Sheets used is how many stock sheets the cut plan took, recorded when the order was marked cut.', 'zorderz' ); ?></li>
				<li><?php esc_html_e( 'Material cost is the pieces\' square inches × what the material cost per square inch when the order was made. The rate is kept on the order, so a later change does not move a past year. It is blank when no rate was on file.', 'zorderz' ); ?></li>
				<li><?php esc_html_e( 'Would sell for is quantity × the price typed on each row. Rows with no price are counted, never guessed.', 'zorderz' ); ?></li>
				<li><?php esc_html_e( 'Hours are typed on the order by a person. The app does not time the work. An order nobody has typed hours on is counted beside the total, not added as zero.', 'zorderz' ); ?></li>
				<li><?php esc_html_e( 'An order is listed in the year it was made. Removed orders are left out.', 'zorderz' ); ?></li>
			</ul>
			<p class="description"><?php esc_html_e( 'This page is a record of the work. How it is treated in the accounts is for your accountant to say.', 'zorderz' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function rows_no_price( int $n ): string {
		/* translators: %d: rows. */
		return sprintf( _n( '%d row with no price', '%d rows with no price', $n, 'zorderz' ), $n );
	}

	private static function totals_cells( array $t, bool $foot ): void {
		$tag  = $foot ? 'th' : 'td';
		$note = static function ( string $text ): string {
			return ' <span class="description">(' . esc_html( $text ) . ')</span>';
		};
		$cells   = array();
		$cells[] = $foot ? esc_html( (string) $t['label'] ) : '<strong>' . esc_html( (string) $t['label'] ) . '</strong>';
		$cells[] = (string) (int) $t['orders'];
		$cells[] = (string) (int) $t['cut'];
		$cells[] = (string) (int) $t['pieces'];
		$cells[] = esc_html( self::count_text( $t['sq_in'] ) );
		$cells[] = $t['sheets_used'] > 0 ? (string) (int) $t['sheets_used'] : '';
		/* translators: %d: orders. */
		$cells[] = esc_html( self::money( $t['material_cost'] ) ) . ( $t['orders_no_cost'] > 0 ? $note( sprintf( _n( '%d order with no cost', '%d orders with no cost', (int) $t['orders_no_cost'], 'zorderz' ), (int) $t['orders_no_cost'] ) ) : '' );
		$cells[] = esc_html( self::money( $t['sell_total'] ) ) . ( $t['rows_unpriced'] > 0 ? $note( self::rows_no_price( (int) $t['rows_unpriced'] ) ) : '' );
		/* translators: %d: orders. */
		$cells[] = ( null === $t['labor_hours'] ? '' : esc_html( self::count_text( $t['labor_hours'] ) ) ) . ( $t['orders_no_hours'] > 0 ? $note( sprintf( _n( '%d order with no hours', '%d orders with no hours', (int) $t['orders_no_hours'], 'zorderz' ), (int) $t['orders_no_hours'] ) ) : '' );
		foreach ( $cells as $c ) {
			echo '<' . $tag . '>' . $c . '</' . $tag . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $tag is the literal 'th' or 'td'; every cell above is an integer cast or esc_html() output, with fixed markup around it.
		}
	}
}
