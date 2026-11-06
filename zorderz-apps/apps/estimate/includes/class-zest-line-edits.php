<?php
/**
 * ZEST_Line_Edits - changes to an estimate's lines, made on purpose and said by whom.
 *
 * Easy to change, hard to change by accident, and every change says who made it. The pure part takes the document's
 * lines, the person's changes and their rights, and gives back the new lines and a log; the endpoints read the
 * document, hand it here, and write what comes back.
 *
 * A change is one of:
 *   text    {line, field: name|description, to}   the whole wording, typed (a deliberate second step)
 *   option  {line, field, start, end, from, to, slot, option}
 *           one word swapped for another choice of the same kind (the press-and-hold menu), checked against the
 *           business's option map (ZEST_Line_Options::check_option): `from` must be what is there and name an option
 *           of `slot`, the slot must belong to the line's family, and `to` must be exactly the chosen option's words
 *   qty     {line, to}
 *   rate    {line, to}
 *   remove  {line}
 *   add     {name, description, qty, rate}
 * Lines are numbered as loaded (0-based). The whole set applies, or none of it does. A change to a line that no longer
 * reads as the person saw it is refused (base_hash), never guessed.
 *
 * Rights (rights()): the wording for the estimate's owner; quantities and prices only with the price rights
 * (zest_field_permissions: edit_pricing, edit_quantity) and never while the business keeps prices blind
 * (zest_blind_pricing); an invoice with a payment on it keeps its prices and quantities for the admin tier; an archived
 * document is read only. Adding or removing a line needs both price and quantity rights. Deny by default: a right
 * nobody granted is no right.
 *
 * Endpoints: zest_line_edits_load {id} (the lines, the rights, the base hash and an edit token, ZEST_Edit_Token);
 * zest_line_edits_save {id, token, base, changes, reason} (checked, applied, written to the billing system with every
 * other field of each line kept, read back and compared, this app's row updated, an "Edited by" line added to the notes
 * once per editor per day).
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Line_Edits {

	const MAX_CHANGES = 60;
	const MAX_NAME    = 300;
	const MAX_DESC    = 2000;
	const MAX_REASON  = 300;
	const MAX_QTY     = 10000;
	const MAX_RATE    = 100000;

	public static function init(): void {
		add_action( 'wp_ajax_zest_line_edits_load', array( __CLASS__, 'ajax_load' ) );
		add_action( 'wp_ajax_zest_line_edits_save', array( __CLASS__, 'ajax_save' ) );
	}

	/* ------------------------------------------------------------ reading a document */

	/** Billing-shape lines -> the editor's plain shape; fields it does not edit (taxes, type) ride in `keep`. */
	public static function lines_from_doc( array $doc ): array {
		$out = array();
		foreach ( array_values( (array) ( $doc['lines'] ?? array() ) ) as $l ) {
			if ( ! is_array( $l ) ) {
				continue;
			}
			$keep = array();
			foreach ( array( 'type', 'taxName1', 'taxAmount1', 'taxName2', 'taxAmount2', 'taxNumber1', 'taxNumber2', 'expenseid', 'compounded_tax' ) as $k ) {
				if ( array_key_exists( $k, $l ) && null !== $l[ $k ] && '' !== $l[ $k ] ) {
					$keep[ $k ] = $l[ $k ];
				}
			}
			$out[] = array(
				'name'        => (string) ( $l['name'] ?? '' ),
				'description' => (string) ( $l['description'] ?? '' ),
				'qty'         => self::num( $l['qty'] ?? 1 ),
				'rate'        => self::num( is_array( $l['unit_cost'] ?? null ) ? ( $l['unit_cost']['amount'] ?? 0 ) : ( $l['unit_cost'] ?? 0 ) ),
				'keep'        => $keep,
				'raw_qty'     => is_scalar( $l['qty'] ?? null ) ? (string) $l['qty'] : '',
				'raw_rate'    => is_array( $l['unit_cost'] ?? null ) ? (string) ( $l['unit_cost']['amount'] ?? '' ) : (string) ( $l['unit_cost'] ?? '' ),
			);
		}
		return $out;
	}

	/** A hash of what the person saw: wording, quantities, rates and notes. */
	public static function base_hash( array $lines, string $notes ): string {
		$rows = array();
		foreach ( $lines as $l ) {
			$rows[] = array( (string) $l['name'], (string) $l['description'], self::fmt( $l['qty'] ), self::fmt( $l['rate'] ) );
		}
		return substr( hash( 'sha256', (string) json_encode( array( $rows, self::nl( $notes ) ) ) ), 0, 24 );
	}

	public static function total( array $lines ): float {
		$t = 0.0;
		foreach ( $lines as $l ) {
			$t += round( (float) $l['qty'] * (float) $l['rate'], 2 );
		}
		return round( $t, 2 );
	}

	/**
	 * What this person may change on this document. $perms: the resolved field permissions; $admin: the admin tier;
	 * $kind: estimate | invoice; $blind: the business keeps prices blind.
	 *
	 * @return array{text:bool,qty:bool,price:bool,add_remove:bool,note:string}
	 */
	public static function rights( array $doc, string $kind, array $perms, bool $admin, bool $blind = false ): array {
		$active  = 0 === (int) ( $doc['vis_state'] ?? 0 );
		$wording = $active && ! empty( $perms['edit_wording'] );
		$price   = $active && ! empty( $perms['edit_pricing'] ) && ! $blind;
		$qty     = $active && ! empty( $perms['edit_quantity'] );
		$note    = $active ? '' : __( 'Archived in the billing system: read only.', 'zorderz' );
		$paid    = self::num( is_array( $doc['paid'] ?? null ) ? ( $doc['paid']['amount'] ?? 0 ) : ( $doc['paid'] ?? 0 ) );
		if ( 'invoice' === $kind && $paid > 0.0 && ! $admin ) {
			$price = false;
			$qty   = false;
			$note  = __( 'This invoice has a payment, so only an admin can change its prices or quantities. Wording changes are fine.', 'zorderz' );
		}
		return array( 'text' => $wording, 'qty' => $qty, 'price' => $price, 'add_remove' => $price && $qty, 'note' => $note );
	}

	/* ------------------------------------------------------------ applying */

	/**
	 * Validate and apply. $rights: text, qty, price, add_remove (and options, defaulting to text). $map: the option map
	 * for option swaps (ZEST_Line_Options::get()); without one an option swap is refused.
	 *
	 * @return array { ok, error?, lines?, log? }  log rows: { line_no, type, field, before, after, before_amount, after_amount, slot?, option? }
	 */
	public static function apply( array $lines, array $changes, array $rights, ?array $map = null ): array {
		if ( ! $changes ) {
			return self::fail( __( 'There are no changes to save.', 'zorderz' ) );
		}
		if ( count( $changes ) > self::MAX_CHANGES ) {
			return self::fail( __( 'Too many changes at once. Save in smaller steps.', 'zorderz' ) );
		}
		$n      = count( $lines );
		$text   = array();
		$qty    = array();
		$rate   = array();
		$remove = array();
		$adds   = array();
		$swaps  = array();
		foreach ( $changes as $c ) {
			if ( ! is_array( $c ) ) {
				return self::fail( __( 'A change was not understood.', 'zorderz' ) );
			}
			$type = (string) ( $c['type'] ?? '' );
			if ( 'add' === $type ) {
				if ( empty( $rights['add_remove'] ) ) {
					return self::fail( __( 'You cannot add lines to this document.', 'zorderz' ) );
				}
				$adds[] = $c;
				continue;
			}
			$i = $c['line'] ?? null;
			if ( ! is_int( $i ) && ! ( is_string( $i ) && ctype_digit( $i ) ) ) {
				return self::fail( __( 'A change named no line.', 'zorderz' ) );
			}
			$i = (int) $i;
			if ( $i < 0 || $i >= $n ) {
				return self::fail( __( 'A change named a line that is not on this document.', 'zorderz' ) );
			}
			switch ( $type ) {
				case 'text':
					$field = (string) ( $c['field'] ?? '' );
					if ( 'name' !== $field && 'description' !== $field ) {
						return self::fail( __( 'A change named no part of the line.', 'zorderz' ) );
					}
					if ( empty( $rights['text'] ) ) {
						return self::fail( __( 'You cannot change the wording of this document.', 'zorderz' ) );
					}
					if ( isset( $text[ $i . '|' . $field ] ) || isset( $swaps[ $i . '|' . $field ] ) ) {
						/* translators: %d: line number */
						return self::fail( sprintf( __( 'Line %d was changed two ways at once. Undo one and save again.', 'zorderz' ), $i + 1 ) );
					}
					$text[ $i . '|' . $field ] = $c;
					break;
				case 'option':
					$field = (string) ( $c['field'] ?? '' );
					if ( 'name' !== $field && 'description' !== $field ) {
						return self::fail( __( 'A change named no part of the line.', 'zorderz' ) );
					}
					if ( empty( $rights['options'] ?? $rights['text'] ?? false ) ) {
						return self::fail( __( 'You cannot change the wording of this document.', 'zorderz' ) );
					}
					if ( isset( $text[ $i . '|' . $field ] ) ) {
						/* translators: %d: line number */
						return self::fail( sprintf( __( 'Line %d was changed two ways at once. Undo one and save again.', 'zorderz' ), $i + 1 ) );
					}
					$swaps[ $i . '|' . $field ][] = $c;
					break;
				case 'qty':
					if ( empty( $rights['qty'] ) ) {
						return self::fail( __( 'You cannot change quantities on this document.', 'zorderz' ) );
					}
					if ( isset( $qty[ $i ] ) ) {
						/* translators: %d: line number */
						return self::fail( sprintf( __( 'Line %d\'s quantity was changed twice.', 'zorderz' ), $i + 1 ) );
					}
					$qty[ $i ] = $c;
					break;
				case 'rate':
					if ( empty( $rights['price'] ) ) {
						return self::fail( __( 'You cannot change prices on this document.', 'zorderz' ) );
					}
					if ( isset( $rate[ $i ] ) ) {
						/* translators: %d: line number */
						return self::fail( sprintf( __( 'Line %d\'s price was changed twice.', 'zorderz' ), $i + 1 ) );
					}
					$rate[ $i ] = $c;
					break;
				case 'remove':
					if ( empty( $rights['add_remove'] ) ) {
						return self::fail( __( 'You cannot remove lines from this document.', 'zorderz' ) );
					}
					$remove[ $i ] = true;
					break;
				default:
					return self::fail( __( 'A change was not understood.', 'zorderz' ) );
			}
		}

		$out = $lines;
		$log = array();
		foreach ( $text as $k => $c ) {
			list( $i, $field ) = explode( '|', $k );
			$i      = (int) $i;
			$before = (string) $lines[ $i ][ $field ];
			$to     = self::clean_text( (string) ( $c['to'] ?? '' ) );
			$max    = 'name' === $field ? self::MAX_NAME : self::MAX_DESC;
			if ( mb_strlen( $to, 'UTF-8' ) > $max && mb_strlen( $to, 'UTF-8' ) > mb_strlen( $before, 'UTF-8' ) ) {
				/* translators: 1: line number, 2: name or description, 3: most characters */
				return self::fail( sprintf( __( 'Line %1$d: the %2$s is too long (%3$d characters at most).', 'zorderz' ), $i + 1, $field, $max ) );
			}
			if ( 'name' === $field && '' === trim( $to ) ) {
				/* translators: %d: line number */
				return self::fail( sprintf( __( 'Line %d needs a name.', 'zorderz' ), $i + 1 ) );
			}
			if ( $to === $before ) {
				continue;
			}
			$out[ $i ][ $field ] = $to;
			$log[]               = self::row( $i, 'text', $field, $before, $to );
		}
		foreach ( $swaps as $k => $set ) {
			if ( ! $map || ! class_exists( 'ZEST_Line_Options' ) ) {
				return self::fail( __( 'The list of choices is not available right now, so nothing was saved.', 'zorderz' ) );
			}
			list( $i, $field ) = explode( '|', $k );
			$i   = (int) $i;
			$fam = ZEST_Line_Options::family_of( (string) $lines[ $i ]['name'] . ' - ' . (string) $lines[ $i ]['description'], $map ); // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.famRemoved -- ZEST_Line_Options::family_of() is a method of this app, not the removed fam extension
			$res = ZEST_Line_Options::apply_swaps( (string) $lines[ $i ][ $field ], $set, $map, $fam ? (array) $fam['slots'] : array() );
			if ( is_string( $res ) ) {
				/* translators: 1: line number, 2: the reason */
				return self::fail( sprintf( __( 'Line %1$d: %2$s', 'zorderz' ), $i + 1, $res ) );
			}
			$out[ $i ][ $field ] = $res[0];
			foreach ( $res[1] as $v ) {
				$log[] = self::row( $i, 'option', $field, $v['from'], $v['to'] ) + array( 'slot' => $v['slot'], 'option' => $v['option'] );
			}
		}
		foreach ( $qty as $i => $c ) {
			$to = self::parse_num( $c['to'] ?? null );
			if ( null === $to || $to <= 0 || $to > self::MAX_QTY ) {
				/* translators: %d: line number */
				return self::fail( sprintf( __( 'Line %d: the quantity must be a number above 0.', 'zorderz' ), $i + 1 ) );
			}
			if ( self::fmt( $to ) === self::fmt( $lines[ $i ]['qty'] ) ) {
				continue;
			}
			$out[ $i ]['qty'] = $to;
			$log[] = self::row( $i, 'qty', 'qty', self::fmt( $lines[ $i ]['qty'] ), self::fmt( $to ), self::line_amount( $lines[ $i ] ), round( $to * (float) $out[ $i ]['rate'], 2 ) );
		}
		foreach ( $rate as $i => $c ) {
			$to = self::parse_num( $c['to'] ?? null );
			if ( null === $to || abs( $to ) > self::MAX_RATE ) {
				/* translators: %d: line number */
				return self::fail( sprintf( __( 'Line %d: the price must be a number.', 'zorderz' ), $i + 1 ) );
			}
			if ( self::fmt( $to ) === self::fmt( $lines[ $i ]['rate'] ) ) {
				continue;
			}
			$out[ $i ]['rate'] = $to;
			$log[] = self::row( $i, 'rate', 'rate', self::money( $lines[ $i ]['rate'] ), self::money( $to ), self::line_amount( $lines[ $i ] ), round( (float) $out[ $i ]['qty'] * $to, 2 ) );
		}
		krsort( $remove );
		foreach ( array_keys( $remove ) as $i ) {
			$log[] = self::row( $i, 'remove', 'line', self::line_label( $out[ $i ] ), '', self::line_amount( $out[ $i ] ), 0.0 );
			array_splice( $out, $i, 1 );
		}
		// one of two options removed: the option kept loses its option wording, so the customer's copy reads as one line
		if ( $remove && class_exists( 'ZEST_Price_Book_Lines' ) ) {
			$clean = ZEST_Price_Book_Lines::clean_lone_option( $out, 'description' );
			foreach ( $clean as $k => $l ) {
				if ( (string) $l['description'] !== (string) $out[ $k ]['description'] ) {
					$log[] = self::row( $k, 'text', 'description', (string) $out[ $k ]['description'], (string) $l['description'] );
					$out[ $k ]['description'] = (string) $l['description'];
				}
			}
		}
		foreach ( $adds as $c ) {
			$name = self::clean_text( (string) ( $c['name'] ?? '' ) );
			$desc = self::clean_text( (string) ( $c['description'] ?? '' ) );
			if ( mb_strlen( $name, 'UTF-8' ) > self::MAX_NAME || mb_strlen( $desc, 'UTF-8' ) > self::MAX_DESC ) {
				return self::fail( __( 'A new line is too long.', 'zorderz' ) );
			}
			$q = self::parse_num( $c['qty'] ?? 1 );
			$r = self::parse_num( $c['rate'] ?? 0 );
			if ( '' === trim( $name ) ) {
				return self::fail( __( 'A new line needs a name.', 'zorderz' ) );
			}
			if ( null === $q || $q <= 0 || $q > self::MAX_QTY ) {
				return self::fail( __( 'A new line needs a quantity above 0.', 'zorderz' ) );
			}
			if ( null === $r || abs( $r ) > self::MAX_RATE ) {
				return self::fail( __( 'A new line needs a price (0 is fine).', 'zorderz' ) );
			}
			$new = array( 'name' => $name, 'description' => $desc, 'qty' => $q, 'rate' => $r, 'keep' => array( 'type' => 0 ) );
			$at  = count( $out );
			$out[] = $new;
			$log[] = self::row( $at, 'add', 'line', '', self::line_label( $new ), 0.0, round( $q * $r, 2 ) );
		}
		if ( ! $out ) {
			return self::fail( __( 'A document needs at least one line.', 'zorderz' ) );
		}
		if ( ! $log ) {
			return self::fail( __( 'Nothing would change, so nothing was saved.', 'zorderz' ) );
		}
		return array( 'ok' => true, 'lines' => $out, 'log' => $log );
	}

	/* ------------------------------------------------------------ writing */

	/** The editor's lines -> billing-shape lines, every kept field written back; an untouched figure goes back as read. */
	public static function to_wire_lines( array $lines ): array {
		$out = array();
		foreach ( $lines as $l ) {
			$row                = array_merge( array( 'type' => 0 ), (array) ( $l['keep'] ?? array() ) );
			$row['name']        = (string) $l['name'];
			$row['description'] = (string) $l['description'];
			$raw_q              = (string) ( $l['raw_qty'] ?? '' );
			$raw_r              = (string) ( $l['raw_rate'] ?? '' );
			$row['qty']         = ( '' !== $raw_q && is_numeric( $raw_q ) && (float) $raw_q == (float) $l['qty'] ) ? $raw_q : self::fmt( $l['qty'] ); // phpcs:ignore WordPress.PHP.StrictComparisons -- numeric equality of a figure as read
			$row['unit_cost']   = array( 'amount' => ( '' !== $raw_r && is_numeric( $raw_r ) && (float) $raw_r == (float) $l['rate'] ) ? $raw_r : number_format( (float) $l['rate'], 2, '.', '' ) ); // phpcs:ignore WordPress.PHP.StrictComparisons -- as above
			$out[]              = $row;
		}
		return $out;
	}

	/** Do the saved lines read as what was sent? '' or the first line that differs (1-based), or 'count'. */
	public static function verify( array $sent, array $doc ): string {
		$got = self::lines_from_doc( $doc );
		if ( count( $got ) !== count( $sent ) ) {
			return 'count';
		}
		foreach ( $sent as $i => $l ) {
			$g = $got[ $i ];
			if ( self::nl( $g['name'] ) !== self::nl( $l['name'] ) || self::nl( $g['description'] ) !== self::nl( $l['description'] )
				|| self::fmt( $g['qty'] ) !== self::fmt( $l['qty'] ) || self::fmt( $g['rate'] ) !== self::fmt( $l['rate'] ) ) {
				return (string) ( $i + 1 );
			}
		}
		return '';
	}

	/**
	 * The "Edited by" line (document conventions estimate.edited_by_line, default "Edited by: {initials} - {date}"),
	 * added once per editor per day, and not for the person the provenance line names.
	 */
	public static function edited_by( string $notes, string $initials, string $date ): string {
		$initials = strtoupper( trim( $initials ) );
		if ( '' === $initials ) {
			return $notes;
		}
		if ( class_exists( 'ZEST_Salesperson' ) && in_array( $initials, ZEST_Salesperson::provenance_codes( $notes ), true ) ) {
			return $notes;
		}
		$tmpl = class_exists( 'ZDZ_Doc_Conventions' ) ? (string) ZDZ_Doc_Conventions::get( 'estimate.edited_by_line', '' ) : '';
		$tmpl = ( '' !== trim( $tmpl ) && false !== strpos( $tmpl, '{initials}' ) ) ? $tmpl : 'Edited by: {initials} - {date}';
		$line = strtr( $tmpl, array( '{initials}' => $initials, '{date}' => $date ) );
		foreach ( (array) preg_split( '/\r\n|\r|\n/', $notes ) as $l ) {
			if ( trim( (string) $l ) === $line ) {
				return $notes;
			}
		}
		return '' === rtrim( $notes ) ? $line : rtrim( $notes ) . "\n" . $line;
	}

	/** Plain one-line descriptions of a save, for the review sheet, the CRM note and the history. */
	public static function summary( array $log ): array {
		$out = array();
		foreach ( $log as $r ) {
			/* translators: %d: line number */
			$n = sprintf( __( 'Line %d', 'zorderz' ), (int) $r['line_no'] );
			switch ( $r['type'] ) {
				case 'text':
					$out[] = $n . ': ' . ( 'name' === $r['field'] ? __( 'name reworded', 'zorderz' ) : __( 'description reworded', 'zorderz' ) );
					break;
				case 'option':
					/* translators: 1: the word before, 2: the word after */
					$out[] = $n . ': ' . sprintf( __( '"%1$s" to "%2$s"', 'zorderz' ), $r['before'], $r['after'] );
					break;
				case 'qty':
					$out[] = $n . ': ' . sprintf( __( 'quantity %1$s to %2$s', 'zorderz' ), $r['before'], $r['after'] );
					break;
				case 'rate':
					$out[] = $n . ': ' . sprintf( __( 'price %1$s to %2$s', 'zorderz' ), $r['before'], $r['after'] );
					break;
				case 'remove':
					$out[] = $n . ' ' . __( 'removed:', 'zorderz' ) . ' ' . $r['before'];
					break;
				case 'add':
					$out[] = $n . ' ' . __( 'added:', 'zorderz' ) . ' ' . $r['after'];
					break;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------ endpoints */

	private static function perms( int $uid ): array {
		$p = class_exists( 'ZEST_Dashboard' ) ? ZEST_Dashboard::get_resolved_permissions( $uid ) : array();
		return is_array( $p ) ? $p : array();
	}

	public static function ajax_load(): void {
		list( $uid, $row ) = ZEST_Access::load_owned( 'id', true );
		list( $doc, $from ) = ZEST_Deposit_Check::doc_for( $row );
		$lines  = self::lines_from_doc( $doc );
		$rights = self::rights( $doc, 'estimate', self::perms( $uid ), ZEST_Access::is_admin_tier( $uid ), (bool) get_option( 'zest_blind_pricing', false ) );
		$cut    = static function ( string $s ): string {
			return class_exists( 'ZDZ_Sealed' ) ? (string) ZDZ_Sealed::cut( $s ) : $s;
		};
		$show = array();
		foreach ( $lines as $l ) {
			$show[] = array( 'name' => $cut( $l['name'] ), 'description' => $cut( $l['description'] ), 'qty' => $l['qty'], 'rate' => empty( self::perms( $uid )['view_pricing'] ) ? null : $l['rate'] );
		}
		wp_send_json_success( array(
			'lines'  => $show,
			'rights' => $rights,
			'base'   => self::base_hash( $lines, (string) ( $doc['notes'] ?? '' ) ),
			'from'   => $from,
			'token'  => ZEST_Edit_Token::issue( $uid, (int) $row['id'] ),
			'codes'  => class_exists( 'ZEST_Sealed' ) ? ZEST_Sealed::for_row( $row, $uid ) : array(),
		) + ( class_exists( 'ZEST_Line_Options' ) ? ZEST_Line_Options::for_browser( isset( $_POST['have_map'] ) ? sanitize_text_field( wp_unslash( $_POST['have_map'] ) ) : '' ) : array() ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in ZEST_Access::load_owned() above
	}

	public static function ajax_save(): void {
		list( $uid, $row ) = ZEST_Access::load_owned( 'id', true );
		$no = ZEST_Edit_Token::check( $uid, (int) $row['id'], isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in ZEST_Access::load_owned() above
		if ( '' !== $no ) {
			wp_send_json_error( array( 'code' => 'token', 'message' => $no ) );
		}
		list( $doc, $from ) = ZEST_Deposit_Check::doc_for( $row );
		$lines = self::lines_from_doc( $doc );
		$base  = isset( $_POST['base'] ) ? sanitize_text_field( wp_unslash( $_POST['base'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in ZEST_Access::load_owned() above
		if ( ! hash_equals( self::base_hash( $lines, (string) ( $doc['notes'] ?? '' ) ), $base ) ) {
			wp_send_json_error( array( 'code' => 'changed', 'message' => __( 'The estimate changed since you opened it, so nothing was saved. Open it again.', 'zorderz' ) ) );
		}
		$changes = isset( $_POST['changes'] ) ? json_decode( wp_unslash( $_POST['changes'] ), true ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- JSON, each value checked by apply(); nonce verified in ZEST_Access::load_owned() above
		$rights  = self::rights( $doc, 'estimate', self::perms( $uid ), ZEST_Access::is_admin_tier( $uid ), (bool) get_option( 'zest_blind_pricing', false ) );
		$res     = self::apply( $lines, is_array( $changes ) ? $changes : array(), $rights, class_exists( 'ZEST_Line_Options' ) ? ZEST_Line_Options::get() : null );
		if ( empty( $res['ok'] ) ) {
			wp_send_json_error( array( 'message' => $res['error'] ) );
		}
		$notes = self::edited_by( (string) ( $doc['notes'] ?? '' ), class_exists( 'ZEST_Salesperson' ) ? ZEST_Salesperson::initials_of( $uid ) : '', function_exists( 'wp_date' ) ? (string) wp_date( 'n/j/Y' ) : gmdate( 'n/j/Y' ) );
		if ( class_exists( 'ZEST_Warranty_Note' ) ) {
			$notes = ZEST_Warranty_Note::add( $notes, self::to_wire_lines( $res['lines'] ) );
		}
		if ( 'billing' === $from ) {
			$put = ZEST_Billing_Doc::put( 'estimate', (string) $row['billing_doc_id'], array( 'lines' => self::to_wire_lines( $res['lines'] ), 'notes' => $notes ), (string) ( $doc['create_date'] ?? '' ) );
			if ( empty( $put['ok'] ) ) {
				wp_send_json_error( array( 'message' => $put['error'] ) );
			}
			$saved = ZEST_Billing_Doc::read( 'estimate', (string) $row['billing_doc_id'] );
			$diff  = is_array( $saved ) ? self::verify( $res['lines'], $saved ) : 'read';
			if ( '' !== $diff ) {
				error_log( 'Zorderz Estimates: a line edit on estimate id ' . (int) $row['id'] . ' read back differently (' . $diff . ').' );
			}
		}
		$items = array();
		foreach ( $res['lines'] as $l ) {
			$items[] = array( 'description' => (string) $l['name'], 'sub_description' => (string) $l['description'], 'quantity' => (float) $l['qty'], 'unit_price' => (float) $l['rate'] );
		}
		global $wpdb;
		$data = array( 'items_json' => wp_json_encode( $items ), 'item_count' => count( $items ), 'notes' => $notes, 'updated_at' => current_time( 'mysql', true ), 'seal_hold' => (string) ( $row['seal_hold'] ?? '' ) );
		$data = array_intersect_key( (array) apply_filters( 'zest_row_data', $data, $uid ), $data );
		$wpdb->update( ZEST_DB::estimates_table(), $data, array( 'id' => (int) $row['id'] ) );
		if ( function_exists( 'zest_usage' ) ) {
			zest_usage( 'estimate_edited', (int) $row['id'] );
		}
		wp_send_json_success( array( 'saved' => true, 'summary' => self::summary( $res['log'] ), 'total' => self::total( $res['lines'] ) ) );
	}

	/* ------------------------------------------------------------ helpers */

	private static function row( int $i, string $type, string $field, string $before, string $after, ?float $ba = null, ?float $aa = null ): array {
		return array( 'line_no' => $i + 1, 'type' => $type, 'field' => $field, 'before' => $before, 'after' => $after, 'before_amount' => $ba, 'after_amount' => $aa );
	}

	private static function fail( string $why ): array {
		return array( 'ok' => false, 'error' => $why );
	}

	private static function line_label( array $l ): string {
		$s = trim( (string) $l['name'] );
		return strlen( $s ) > 80 ? substr( $s, 0, 77 ) . '...' : $s;
	}

	private static function line_amount( array $l ): float {
		return round( (float) $l['qty'] * (float) $l['rate'], 2 );
	}

	/** Typed text as typed: line endings unified, control characters out, ends trimmed (length is the caller's check). */
	public static function clean_text( string $s, int $max = 0 ): string {
		$s = str_replace( array( "\r\n", "\r" ), "\n", $s );
		$s = trim( (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s ) );
		if ( $max > 0 && mb_strlen( $s, 'UTF-8' ) > $max ) {
			$s = mb_substr( $s, 0, $max, 'UTF-8' );
		}
		return $s;
	}

	private static function nl( string $s ): string {
		return trim( str_replace( array( "\r\n", "\r" ), "\n", $s ) );
	}

	private static function num( $v ): float {
		return is_numeric( $v ) ? (float) $v : 0.0;
	}

	public static function parse_num( $v ): ?float {
		if ( is_int( $v ) || is_float( $v ) ) {
			return round( (float) $v, 2 );
		}
		$s = trim( str_replace( array( '$', ',' ), '', (string) $v ) );
		if ( '' === $s || ! preg_match( '/^-?\d+(\.\d{1,2})?$/', $s ) ) {
			return null;
		}
		return round( (float) $s, 2 );
	}

	/** A number with no trailing zeros ("1", "2.5", "1750"). */
	public static function fmt( $v ): string {
		$s = number_format( (float) $v, 4, '.', '' );
		return rtrim( rtrim( $s, '0' ), '.' ) ?: '0';
	}

	public static function money( $v ): string {
		$v = (float) $v;
		return ( $v < 0 ? '-$' : '$' ) . number_format( abs( $v ), 2 );
	}
}
