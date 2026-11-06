<?php
/**
 * ZJOB_Project_Orders — the material order on a Project, checked by two people, with a purchase-order number that
 * comes from the job.
 *
 *   estimate lines --prefill()--> ORDER (units, parts, notes, what it is for)   <-- the seller side edits / confirms
 *                                        |                                     <-- the operator side edits / confirms
 *                                        |  each confirmation is a SNAPSHOT of the record at that revision (and PO)
 *                                        v
 *                      compare(seller snapshot, operator snapshot) -> the differences, in words
 *                                        v
 *                      Ordered (who placed it, how, when) — the operator side only, on the record it confirmed
 *
 * PRE-FILL NEVER GUESSES. A line goes on an order form only when the form's family (a price-book family, through
 * ZDZ_Price_Book::is_family_line) says it is one of its products, or the form takes every line; sizes come from the
 * estimate's own size words (ZDZ_Price_Book::parse_wh); colour, side and the like stay blank for a person. Every
 * pre-filled value is marked with where it came from (src 'estimate') until somebody changes it.
 *
 * THE FORMS are the business's: each supplier's order form (its fields, and where each goes on the supplier's own
 * paper) is a mapping the business supplies (option zjob_order_forms, written from its Identity Pack or the Jobs
 * settings' form upload, or the filter `zjob_order_forms`). Core ships one neutral form, "Materials list" (item,
 * quantity, size, colour, note) with a neutral sample blank, so the feature works before any supplier is configured.
 * Printing an order on a form's paper is ZJOB_Order_Paper.
 *
 * WHO (worked out each time; nothing is stored as a permission):
 *   seller side    anyone tied to the work (the Projects visibility rule's `related`) and administrators;
 *   operator side  the people an administrator named as the ones who check and order materials (option
 *                  zjob_order_operators) and administrators. Until somebody is named, only administrators.
 *   One person never confirms both sides (an administrator excepted). An operator may sign off an order that is their
 *   own (their sale, they started it, or they confirmed it as sold) only when an administrator has said so for them
 *   ("orders own sales without a second check"); otherwise another operator or an administrator signs it off.
 *   Never the shared device. Anyone else who may see the Project sees the order, read-only.
 *   COSTS from the price book go only to whoever ZDZ_Price_Book::cost_visible() allows (the admin rung).
 *
 * PURCHASE ORDERS. Every order carries a PO made from its job — the prefix, a separator and the job's invoice number —
 * never typed. No invoice yet = no PO = the order stays a draft (confirm, mark ordered and the supplier's form are
 * refused with plain words) when its supplier requires one (default: every supplier). A warranty / service order names
 * the original job and goes out on that job's PO. Shop-stock orders (no customer job) need an approver's yes first and
 * then get their own PO (prefix + STOCK + the order's number).
 *
 * THE GO SIGN. Placing an order (Mark ordered, the supplier's form) also waits for the customer's agreement
 * (ZJOB_Order_Gate). Starting, saving, confirming and discarding stay open, so an order can be ready the moment it may go.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZJOB_Project_Orders {

	const OPT_READY     = 'zjob_orders_ready';
	const OPT_OPERATORS = 'zjob_order_operators';
	const OPT_PO        = 'zjob_order_po';
	const OPT_FORMS     = 'zjob_order_forms';

	const SIDE_SELLER   = 'seller';
	const SIDE_OPERATOR = 'operator';

	const ST_OPEN      = 'open';
	const ST_ORDERED   = 'ordered';
	const ST_DISCARDED = 'discarded';

	/** Shop-stock orders hang from no customer job: this project id. */
	const STOCK_PROJECT = 'stock';

	const MAX_ORDERS_PER_PROJECT = 12;
	const MAX_ROWS               = 40;

	/** How an order was placed (kept apart from the PO, which only says which job). */
	const VIA = array( 'counter', 'phone', 'email', 'form' );

	/** What an order is for. A warranty or service part uses the customer's ORIGINAL job, so it carries that job's PO. */
	const KINDS = array( 'new', 'addon', 'warranty' );

	/** The Core PO prefix until the business sets its own. */
	const PO_PREFIX_DEFAULT = 'PO';

	private static $memo = array();

	public static function init(): void {
		add_action( 'wp_ajax_zjob_order_act', array( __CLASS__, 'ajax_act' ) );
		// No _nopriv variant.
	}

	public static function flush_memo(): void {
		self::$memo = array();
	}

	/* =====================================================================================================================
	 * SCHEMA
	 * ===================================================================================================================== */

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zjob_project_orders';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta( "CREATE TABLE {$t} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id VARCHAR(40) NOT NULL DEFAULT '',
			form VARCHAR(64) NOT NULL DEFAULT '',
			status VARCHAR(16) NOT NULL DEFAULT 'open',
			body LONGTEXT NULL,
			rev INT UNSIGNED NOT NULL DEFAULT 1,
			seller_ok TEXT NULL,
			operator_ok TEXT NULL,
			po VARCHAR(48) NOT NULL DEFAULT '',
			orig_project_id VARCHAR(40) NOT NULL DEFAULT '',
			approval TEXT NULL,
			placed_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			placed_via VARCHAR(16) NOT NULL DEFAULT '',
			ordered_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			ordered_at DATETIME NULL DEFAULT NULL,
			created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY project_id (project_id),
			KEY po (po),
			KEY status (status)
		) " . $wpdb->get_charset_collate() . ';' );
		update_option( self::OPT_READY, 1 );
	}

	public static function ready(): bool {
		if ( ! isset( self::$memo['ready'] ) ) {
			self::$memo['ready'] = function_exists( 'get_option' ) && 1 === (int) get_option( self::OPT_READY, 0 );
		}
		return self::$memo['ready'];
	}

	/* =====================================================================================================================
	 * THE FORMS
	 * ===================================================================================================================== */

	/** The neutral form Core ships, printable on the neutral sample blank (ZJOB_Order_Paper::sample_paper()). */
	public static function core_form(): array {
		return array(
			'label'       => __( 'Materials list', 'zorderz' ),
			'supplier'    => '',
			'family'      => '',
			'all_lines'   => true,
			'unit_fields' => array(
				array( 'k' => 'item', 'label' => __( 'Item', 'zorderz' ), 'type' => 'text', 'options' => array(), 'required' => true ),
				array( 'k' => 'qty', 'label' => __( 'Quantity', 'zorderz' ), 'type' => 'number', 'options' => array(), 'required' => true ),
				array( 'k' => 'size', 'label' => __( 'Size', 'zorderz' ), 'type' => 'text', 'options' => array() ),
				array( 'k' => 'colour', 'label' => __( 'Colour', 'zorderz' ), 'type' => 'text', 'options' => array() ),
				array( 'k' => 'note', 'label' => __( 'Note', 'zorderz' ), 'type' => 'text', 'options' => array() ),
			),
			'part_fields' => array(),
			'paper'       => class_exists( 'ZJOB_Order_Paper' ) ? ZJOB_Order_Paper::sample_paper() : null,
		);
	}

	/** Shop stock has no customer job: the order hangs from this stand-in "Project". */
	public static function stock_project(): array {
		return array( 'id' => self::STOCK_PROJECT, 'is_stock' => true, 'human_code' => '', 'state' => 'open' );
	}

	/** key => form. The business's forms (validated) plus the Core form. */
	public static function forms(): array {
		if ( isset( self::$memo['forms'] ) ) {
			return self::$memo['forms'];
		}
		$raw = function_exists( 'get_option' ) ? get_option( self::OPT_FORMS, array() ) : array();
		$raw = is_array( $raw ) ? $raw : array();
		$raw = function_exists( 'apply_filters' ) ? apply_filters( 'zjob_order_forms', $raw ) : $raw;
		$out = array( 'materials' => self::core_form() );
		foreach ( (array) $raw as $k => $f ) {
			$k = function_exists( 'sanitize_key' ) ? sanitize_key( (string) $k ) : strtolower( (string) $k );
			$f = self::clean_form( $f );
			if ( '' !== $k && null !== $f ) {
				$out[ $k ] = $f;
			}
		}
		return self::$memo['forms'] = $out;
	}

	/** A form as data, or null when it is not one (a field needs a key and a label; at least one unit field). */
	public static function clean_form( $f ): ?array {
		if ( ! is_array( $f ) ) {
			return null;
		}
		$fields = static function ( $list ): array {
			$out = array();
			foreach ( (array) $list as $fd ) {
				if ( ! is_array( $fd ) ) {
					continue;
				}
				$k = strtolower( (string) preg_replace( '/[^A-Za-z0-9_]/', '', (string) ( $fd['k'] ?? '' ) ) );
				$l = trim( (string) ( $fd['label'] ?? '' ) );
				if ( '' === $k || '' === $l ) {
					continue;
				}
				$type = in_array( (string) ( $fd['type'] ?? 'text' ), array( 'text', 'number', 'choice' ), true ) ? (string) ( $fd['type'] ?? 'text' ) : 'text';
				$opts = array();
				foreach ( (array) ( $fd['options'] ?? array() ) as $o ) {
					if ( is_array( $o ) && isset( $o[0], $o[1] ) ) {
						$opts[] = array( (string) $o[0], (string) $o[1] );
					}
				}
				$row = array( 'k' => $k, 'label' => $l, 'type' => $type, 'options' => $opts );
				if ( ! empty( $fd['required'] ) ) {
					$row['required'] = true;
				}
				if ( isset( $fd['max'] ) && (int) $fd['max'] > 0 ) {
					$row['max'] = min( 600, (int) $fd['max'] );
				}
				if ( '' !== trim( (string) ( $fd['hint'] ?? '' ) ) ) {
					$row['hint'] = substr( trim( wp_strip_all_tags( (string) $fd['hint'] ) ), 0, 120 );
				}
				$out[] = $row;
			}
			return $out;
		};
		$units = $fields( $f['unit_fields'] ?? array() );
		if ( ! $units ) {
			return null;
		}
		return array(
			'label'       => trim( (string) ( $f['label'] ?? '' ) ) ?: __( 'Order form', 'zorderz' ),
			'supplier'    => strtolower( (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( $f['supplier'] ?? '' ) ) ),
			'family'      => strtolower( (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( $f['family'] ?? '' ) ) ),
			'all_lines'   => ! empty( $f['all_lines'] ),
			'unit_fields' => $units,
			'part_fields' => $fields( $f['part_fields'] ?? array() ),
			'paper'       => class_exists( 'ZJOB_Order_Paper' ) ? ZJOB_Order_Paper::clean_paper( $f['paper'] ?? null ) : null,
		);
	}

	public static function form( string $key ): ?array {
		$all = self::forms();
		return $all[ $key ] ?? null;
	}

	/* =====================================================================================================================
	 * WHO
	 * ===================================================================================================================== */

	private static function is_kiosk( int $uid ): bool {
		if ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $uid ) ) {
			return true;
		}
		return class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $uid );
	}

	/** A site administrator (the hierarchy's own rule). */
	public static function site_admin( int $uid ): bool {
		return $uid > 0 && class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_admin( $uid ) && ! self::is_kiosk( $uid );
	}

	/** The people named to check and order materials (never the shared device). */
	public static function operators(): array {
		$o   = function_exists( 'get_option' ) ? get_option( self::OPT_OPERATORS, array() ) : array();
		$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) ( is_array( $o ) ? ( $o['ids'] ?? array() ) : array() ) ) ) ) );
		return array_values( array_filter( $ids, static function ( $id ) {
			return $id > 0 && ! self::is_kiosk( $id );
		} ) );
	}

	/** Operators an administrator has allowed to sign off their own sales. */
	public static function self_ok(): array {
		$o = function_exists( 'get_option' ) ? get_option( self::OPT_OPERATORS, array() ) : array();
		$s = array_map( 'intval', (array) ( is_array( $o ) ? ( $o['self_ok'] ?? array() ) : array() ) );
		return array_values( array_intersect( $s, self::operators() ) );
	}

	/**
	 * The sides this person may act on: [] (read-only or nothing), ['seller'], ['operator'] or both.
	 * $rel is the person's relationship to the Project (Zjob_Project_Visibility::REL_*), asked by the caller.
	 */
	public static function sides_for( int $uid, string $rel, bool $is_stock = false ): array {
		if ( $uid <= 0 || self::is_kiosk( $uid ) ) {
			return array();
		}
		$admin = self::site_admin( $uid );
		if ( $is_stock ) {
			return ( $admin || in_array( $uid, self::operators(), true ) ) ? array( self::SIDE_OPERATOR ) : array();
		}
		$out = array();
		if ( $admin || 'all' === $rel || 'related' === $rel ) {
			$out[] = self::SIDE_SELLER;
		}
		if ( $admin || in_array( $uid, self::operators(), true ) ) {
			$out[] = self::SIDE_OPERATOR;
		}
		return $out;
	}

	/** The sides for a Project as stored (asks the visibility rule). */
	public static function sides( int $uid, ?array $project ): array {
		if ( ! $project ) {
			return array();
		}
		$stock = self::STOCK_PROJECT === (string) ( $project['id'] ?? '' );
		// A write gate: the reading grant never makes anyone a side of an order.
		$rel   = ( ! $stock && class_exists( 'Zjob_Project_Visibility' ) ) ? Zjob_Project_Visibility::relationship( $uid, $project, false ) : 'none';
		return self::sides_for( $uid, $rel, $stock );
	}

	/** Is this order the person's own (their sale, they started it, or they confirmed it as sold)? */
	public static function is_own( array $order, int $uid, array $sides ): bool {
		$seller_snap = is_array( $order['seller_ok'] ?? null ) ? $order['seller_ok'] : null;
		return in_array( self::SIDE_SELLER, $sides, true ) || (int) ( $order['created_by'] ?? 0 ) === $uid
			|| ( $seller_snap && (int) ( $seller_snap['by'] ?? 0 ) === $uid );
	}

	/** May this person sign this order off as the operator? Never someone without the operator side. */
	public static function may_sign( array $order, int $uid, array $sides, bool $admin ): bool {
		if ( ! in_array( self::SIDE_OPERATOR, $sides, true ) ) {
			return false;
		}
		return $admin || in_array( $uid, self::self_ok(), true ) || ! self::is_own( $order, $uid, $sides );
	}

	/**
	 * May this person confirm this side of the order? They must hold the side; the operator side follows may_sign();
	 * and one person never confirms both sides (an administrator excepted).
	 */
	public static function may_confirm( array $order, string $side, int $uid, array $sides, bool $admin ): bool {
		if ( ! in_array( $side, $sides, true ) ) {
			return false;
		}
		if ( self::SIDE_OPERATOR === $side && ! self::may_sign( $order, $uid, $sides, $admin ) ) {
			return false;
		}
		if ( $admin ) {
			return true;
		}
		$other = self::SIDE_SELLER === $side ? 'operator_ok' : 'seller_ok';
		$snap  = is_array( $order[ $other ] ?? null ) ? $order[ $other ] : null;
		return ! ( $snap && (int) ( $snap['by'] ?? 0 ) === $uid );
	}

	/** Supplier costs from the price book: only for whoever the price book lets see them. */
	public static function sees_costs( int $uid ): bool {
		return class_exists( 'ZDZ_Price_Book' ) && ZDZ_Price_Book::may_see_costs( $uid );
	}

	/* =====================================================================================================================
	 * PURCHASE ORDER NUMBERS
	 * ===================================================================================================================== */

	public static function po_settings(): array {
		$o      = function_exists( 'get_option' ) ? get_option( self::OPT_PO, array() ) : array();
		$o      = is_array( $o ) ? $o : array();
		$prefix = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $o['prefix'] ?? '' ) ) );
		$sep    = (string) ( $o['sep'] ?? '-' );
		$stock  = strtoupper( (string) preg_replace( '/[^A-Za-z]/', '', (string) ( $o['stock_prefix'] ?? '' ) ) );
		$req    = is_array( $o['required'] ?? null ) ? $o['required'] : array();
		$out    = array(
			'prefix'       => '' !== $prefix ? substr( $prefix, 0, 12 ) : self::PO_PREFIX_DEFAULT,
			'sep'          => in_array( $sep, array( '', '-', '_' ), true ) ? $sep : '-',
			'stock_prefix' => '' !== $stock ? substr( $stock, 0, 16 ) : 'STOCK',
			'zeros'        => ! empty( $o['drop_zeros'] ) ? 'drop' : 'keep',
			'required'     => array(),
		);
		foreach ( $req as $supplier => $on ) {
			$out['required'][ strtolower( (string) $supplier ) ] = (bool) $on;
		}
		return $out;
	}

	/** Does this form's supplier require a PO before the order leaves draft? Yes unless the business says no. */
	public static function po_required( string $form_key ): bool {
		$f = self::form( $form_key );
		$s = self::po_settings();
		$b = $f ? (string) $f['supplier'] : '';
		return (bool) ( $s['required'][ $b ] ?? true );
	}

	/** An invoice number, letters and digits only ('' when there is none; all zeros is no number). PURE. */
	public static function clean_invoice_number( $n ): string {
		$n = substr( (string) preg_replace( '/[^A-Za-z0-9]/', '', (string) $n ), 0, 20 );
		return '' === trim( $n, '0' ) ? '' : $n;
	}

	/** The job's invoice number: the Project's invoice reference (a billing connector may answer otherwise). Never a guess. */
	public static function job_invoice_number( array $project ): string {
		$pid = (string) ( $project['id'] ?? '' );
		$num = ( '' !== $pid && self::STOCK_PROJECT !== $pid && class_exists( 'ZJOB_Project_Refs' ) ) ? ZJOB_Project_Refs::get( $pid, 'invoice' ) : '';
		$num = function_exists( 'apply_filters' ) ? apply_filters( 'zjob_order_job_invoice', $num, $project ) : $num;
		return self::clean_invoice_number( $num );
	}

	/** PURE: the PO for an invoice number under these settings, or '' when there is no number. */
	public static function po_from( string $invoice_number, array $s ): string {
		$n = self::clean_invoice_number( $invoice_number );
		if ( '' === $n ) {
			return '';
		}
		if ( 'drop' === ( $s['zeros'] ?? 'keep' ) && preg_match( '/^\d+$/', $n ) ) {
			$n = ltrim( $n, '0' );
		}
		return (string) $s['prefix'] . (string) $s['sep'] . $n;
	}

	/** The job's PO, or '' when the job has no invoice yet. */
	public static function po_for( array $project ): string {
		return self::po_from( self::job_invoice_number( $project ), self::po_settings() );
	}

	/** PURE: a shop-stock PO (prefix, STOCK, the order's number) — only once approved. */
	public static function stock_po( array $order ): string {
		if ( 'approved' !== self::stock_state( $order ) || (int) ( $order['id'] ?? 0 ) <= 0 ) {
			return '';
		}
		$s = self::po_settings();
		return $s['prefix'] . $s['sep'] . $s['stock_prefix'] . $s['sep'] . (int) $order['id'];
	}

	public static function is_stock( array $order ): bool {
		return self::STOCK_PROJECT === (string) ( $order['project_id'] ?? '' ) && isset( $order['id'] );
	}

	/** What an approval is OF: the units, parts and notes (not row keys or "from" marks). */
	public static function approval_basis( array $body ): array {
		$rows = static function ( $list ): array {
			return array_map( static function ( $r ) {
				$r = (array) $r;
				unset( $r['k'], $r['src'], $r['line'] );
				ksort( $r );
				return $r;
			}, array_values( (array) $list ) );
		};
		return array( 'units' => $rows( $body['units'] ?? array() ), 'parts' => $rows( $body['parts'] ?? array() ), 'notes' => (string) ( $body['notes'] ?? '' ) );
	}

	/** 'asked', 'approved' or 'declined'. An approval is of the order as it stood: changed since, it is asked again. */
	public static function stock_state( array $order ): string {
		$a = is_array( $order['approval'] ?? null ) ? $order['approval'] : array();
		$s = (string) ( $a['state'] ?? 'asked' );
		$s = in_array( $s, array( 'asked', 'approved', 'declined' ), true ) ? $s : 'asked';
		if ( 'approved' === $s && isset( $a['body'] ) && md5( (string) wp_json_encode( self::approval_basis( (array) ( $order['body'] ?? array() ) ) ) ) !== (string) $a['body'] ) {
			return 'asked';
		}
		return $s;
	}

	/** Who answers shop-stock requests: the people an administrator named; until anyone is named, the administrators. */
	public static function approvers(): array {
		$o   = function_exists( 'get_option' ) ? get_option( self::OPT_PO, array() ) : array();
		$ids = is_array( $o ) ? array_map( 'intval', (array) ( $o['approvers'] ?? array() ) ) : array();
		return array_values( array_filter( array_unique( $ids ), static function ( $id ) {
			return $id > 0 && ! self::is_kiosk( $id );
		} ) );
	}

	public static function may_approve( int $uid ): bool {
		if ( $uid <= 0 || self::is_kiosk( $uid ) ) {
			return false;
		}
		$named = self::approvers();
		return $named ? in_array( $uid, $named, true ) : self::site_admin( $uid );
	}

	/** The materials desk (shop stock, supplier invoices): the operators, the administrators and the approvers. */
	public static function desk_ok( int $uid ): bool {
		if ( $uid <= 0 || self::is_kiosk( $uid ) ) {
			return false;
		}
		return self::site_admin( $uid ) || in_array( $uid, self::operators(), true ) || self::may_approve( $uid );
	}

	/** The original job a warranty / service order names, when it is still a warranty order and that Project exists. */
	public static function orig_project( array $order ): ?array {
		$id = (string) ( $order['orig_project_id'] ?? '' );
		if ( '' === $id || 'warranty' !== (string) ( $order['body']['kind'] ?? '' ) || ! class_exists( 'Zjob_Project' ) ) {
			return null;
		}
		$p = Zjob_Project::get( $id );
		return $p ? $p : null;
	}

	/** The PO this open order goes out on: its own (stock), its original job's (warranty), else its job's. */
	public static function po_of( array $order, array $project ): string {
		if ( self::is_stock( $order ) ) {
			return self::stock_po( $order );
		}
		$orig = self::orig_project( $order );
		return self::po_for( $orig ? $orig : $project );
	}

	/** May this order leave draft (confirm, order, the form) with the PO its supplier requires? */
	public static function po_ok( array $order ): bool {
		if ( self::is_stock( $order ) ) {
			return 'approved' === self::stock_state( $order ) && '' !== (string) ( $order['po'] ?? '' );
		}
		return ! self::po_required( (string) ( $order['form'] ?? '' ) ) || '' !== (string) ( $order['po'] ?? '' );
	}

	/** Why this order may not leave draft, in the words the person will read; '' when it may. */
	public static function po_refusal( array $order ): string {
		if ( self::is_stock( $order ) ) {
			$st = self::stock_state( $order );
			if ( 'approved' === $st ) {
				return '';
			}
			return 'declined' === $st
				? __( 'This shop-stock order was not approved, so it has no PO and cannot be ordered. Discard it, or start a new request with the new reason.', 'zorderz' )
				: __( 'A shop-stock order needs an approver\'s yes first. Until it is approved it stays a draft, without a PO.', 'zorderz' );
		}
		if ( self::po_ok( $order ) ) {
			return '';
		}
		if ( self::orig_project( $order ) ) {
			return __( 'The original job has no invoice yet, so this warranty order has no PO. Create that invoice first; until then the order stays a draft.', 'zorderz' );
		}
		return __( 'This job has no invoice yet, so this order has no PO. Create the invoice first; until then the order stays a draft.', 'zorderz' );
	}

	/**
	 * '' when this order may be PLACED now; else why not (the customer's agreement, through ZJOB_Order_Gate). Shop stock
	 * has no agreement. A warranty or service order skips the gate when it names its original job, another Project; one
	 * that doesn't waits like the rest, in its own words (a cancellation's words win).
	 */
	public static function agreement_refusal( array $order, array $project, array $jobs = array() ): string {
		if ( self::ST_OPEN !== ( $order['status'] ?? '' ) || self::is_stock( $order ) || ! class_exists( 'ZJOB_Order_Gate' ) ) {
			return '';
		}
		$warranty = 'warranty' === (string) ( ( is_array( $order['body'] ?? null ) ? $order['body'] : array() )['kind'] ?? '' );
		if ( $warranty ) {
			$orig = (string) ( $order['orig_project_id'] ?? '' );
			if ( '' !== $orig && $orig !== (string) ( $project['id'] ?? '' ) && self::orig_project( $order ) ) {
				return '';
			}
		}
		$why = ZJOB_Order_Gate::refusal( $project, $jobs );
		return '' !== $why && $warranty ? ZJOB_Order_Gate::warranty_words( $why ) : $why;
	}

	/* =====================================================================================================================
	 * THE RECORD
	 * ===================================================================================================================== */

	/** One clean line of text: no control characters, at most $max characters. */
	public static function clean_text( $v, int $max ): string {
		$v = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', (string) $v ) );
		$v = (string) preg_replace( '/\s{2,}/', ' ', $v );
		return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, $max ) : substr( $v, 0, $max );
	}

	/** A body as the form allows it: known fields only, choices from their options, at most MAX_ROWS rows. */
	public static function clean_body( string $form_key, $raw ): array {
		$f   = self::form( $form_key );
		$raw = is_array( $raw ) ? $raw : array();
		$out = array( 'units' => array(), 'parts' => array(), 'notes' => '', 'kind' => 'new' );
		if ( ! $f ) {
			return $out;
		}
		foreach ( array( 'units' => 'unit_fields', 'parts' => 'part_fields' ) as $list => $spec ) {
			$n = 0;
			foreach ( array_slice( (array) ( $raw[ $list ] ?? array() ), 0, self::MAX_ROWS ) as $r ) {
				if ( ! is_array( $r ) ) {
					continue;
				}
				$row = array( 'k' => self::clean_text( $r['k'] ?? '', 24 ) );
				if ( '' === $row['k'] ) {
					$row['k'] = $list[0] . ( ++$n ) . substr( md5( wp_json_encode( $r ) ), 0, 6 );
				}
				$any = false;
				foreach ( (array) $f[ $spec ] as $fd ) {
					$v = self::clean_text( $r[ $fd['k'] ] ?? '', 120 );
					if ( 'number' === $fd['type'] && '' !== $v ) {
						$v = is_numeric( $v ) ? (string) ( 0 + $v ) : '';
					}
					if ( 'choice' === $fd['type'] && '' !== $v && ! in_array( $v, array_column( $fd['options'], 0 ), true ) ) {
						$v = '';
					}
					$row[ $fd['k'] ] = $v;
					$any             = $any || '' !== $v;
				}
				if ( isset( $r['src'] ) && in_array( (string) $r['src'], array( 'estimate', 'derived', 'text' ), true ) ) {
					$row['src'] = (string) $r['src'];
				}
				if ( $any ) {
					$out[ $list ][] = $row;
				}
			}
		}
		$out['notes'] = self::clean_text( str_replace( "\n", ' / ', (string) ( $raw['notes'] ?? '' ) ), 600 );
		$kind         = (string) ( $raw['kind'] ?? 'new' );
		$out['kind']  = in_array( $kind, self::KINDS, true ) ? $kind : 'new';
		return $out;
	}

	private static function decode_row( array $r ): array {
		foreach ( array( 'body', 'seller_ok', 'operator_ok', 'approval' ) as $c ) {
			$v      = isset( $r[ $c ] ) ? json_decode( (string) $r[ $c ], true ) : null;
			$r[ $c ] = is_array( $v ) ? $v : ( 'body' === $c ? array() : null );
		}
		$r['id']  = (int) $r['id'];
		$r['rev'] = (int) $r['rev'];
		return $r;
	}

	public static function get( int $order_id ): ?array {
		global $wpdb;
		if ( $order_id <= 0 || ! self::ready() ) {
			return null;
		}
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name; the id is a placeholder.
		return is_array( $r ) ? self::decode_row( $r ) : null;
	}

	/** A Project's orders (not discarded), oldest first. */
	public static function for_project( string $project_id ): array {
		global $wpdb;
		if ( '' === $project_id || ! self::ready() ) {
			return array();
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE project_id = %s AND status <> %s ORDER BY id ASC LIMIT 50', $project_id, self::ST_DISCARDED ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name; values are placeholders.
		return array_map( array( __CLASS__, 'decode_row' ), $rows );
	}

	public static function create( string $project_id, string $form_key, array $body, int $uid, string $orig_project_id = '' ): int {
		global $wpdb;
		if ( '' === $project_id || ! self::ready() || ! self::form( $form_key ) ) {
			return 0;
		}
		$now = current_time( 'mysql', true );
		$row = array(
			'project_id'      => $project_id,
			'form'            => $form_key,
			'status'          => self::ST_OPEN,
			'body'            => wp_json_encode( $body ),
			'rev'             => 1,
			'orig_project_id' => 'warranty' === ( $body['kind'] ?? '' ) ? substr( $orig_project_id, 0, 40 ) : '',
			'created_by'      => $uid,
			'created_at'      => $now,
			'updated_by'      => $uid,
			'updated_at'      => $now,
		);
		if ( self::STOCK_PROJECT === $project_id ) {
			$row['approval'] = wp_json_encode( array( 'state' => 'asked', 'by' => $uid, 'at' => $now, 'why' => self::clean_text( $body['notes'] ?? '', 300 ) ) );
		}
		$ok = $wpdb->insert( self::table(), $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Write a new body when the caller's revision is still the stored one: two people editing at once, the second save
	 * is refused rather than overwriting the first. Nothing changed: no new revision (nobody's confirmation goes stale).
	 * Returns the new revision, 0 on a lost race or failure.
	 */
	public static function write_body( array $order, array $body, int $uid, int $expect_rev ): int {
		global $wpdb;
		if ( $expect_rev === (int) $order['rev'] && self::same( (string) $order['form'], (array) $order['body'], $body ) ) {
			return $expect_rev;
		}
		$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET body = %s, rev = rev + 1, updated_by = %d, updated_at = %s WHERE id = %d AND rev = %d AND status = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name; values are placeholders.
			wp_json_encode( $body ), $uid, current_time( 'mysql', true ), (int) $order['id'], $expect_rev, self::ST_OPEN ) );
		return ( 1 === (int) $n ) ? $expect_rev + 1 : 0;
	}

	/** Record a side's confirmation: a snapshot of the body at this revision and PO, who, when. */
	public static function write_confirm( array $order, string $side, int $uid ): bool {
		global $wpdb;
		$col  = self::SIDE_SELLER === $side ? 'seller_ok' : 'operator_ok';
		$snap = array( 'by' => $uid, 'at' => current_time( 'mysql', true ), 'rev' => (int) $order['rev'], 'body' => $order['body'], 'po' => (string) ( $order['po'] ?? '' ) );
		$n    = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET {$col} = %s WHERE id = %d AND rev = %d AND status = %s", wp_json_encode( $snap ), (int) $order['id'], (int) $order['rev'], self::ST_OPEN ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name; the column is one of two fixed names picked above; values are placeholders.
		return 1 === (int) $n;
	}

	/** Keep an open order's PO in step with its job (an invoice made after the order was started gives it one). */
	public static function sync_po( array $order, array $project ): array {
		global $wpdb;
		if ( self::ST_OPEN !== ( $order['status'] ?? '' ) ) {
			return $order;
		}
		$po = self::po_of( $order, $project );
		if ( class_exists( 'ZDZ_Privacy_View' ) && ZDZ_Privacy_View::is_on() ) {
			$order['po'] = $po; // shown as it would be, written for nobody
			return $order;
		}
		if ( $po !== (string) ( $order['po'] ?? '' ) ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET po = %s WHERE id = %d AND status = %s', $po, (int) $order['id'], self::ST_OPEN ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name; values are placeholders.
			$order['po'] = $po;
		}
		return $order;
	}

	/* =====================================================================================================================
	 * PRE-FILL
	 * ===================================================================================================================== */

	/**
	 * PURE: the order rows a form takes from an estimate's lines ({desc, sub, qty, size}). A form with a family takes a
	 * line only when ZDZ_Price_Book::is_family_line() says it is the family's; a form that takes every line takes all.
	 * Item, quantity and size are copied; nothing else is guessed.
	 */
	public static function prefill( string $form_key, array $lines ): array {
		$f = self::form( $form_key );
		if ( ! $f ) {
			return array();
		}
		$keys = array_column( $f['unit_fields'], 'k' );
		$out  = array();
		$i    = 0;
		foreach ( $lines as $li ) {
			$desc = trim( (string) ( $li['desc'] ?? '' ) );
			$sub  = trim( (string) ( $li['sub'] ?? '' ) );
			if ( '' === $desc ) {
				continue;
			}
			if ( '' !== $f['family'] ) {
				if ( ! class_exists( 'ZDZ_Price_Book' ) || ! ZDZ_Price_Book::is_family_line( $f['family'], $desc, $sub ) ) {
					continue;
				}
			} elseif ( empty( $f['all_lines'] ) ) {
				continue;
			}
			$row = array( 'k' => 'u' . ( ++$i ), 'src' => 'estimate', 'line' => $i );
			if ( in_array( 'item', $keys, true ) ) {
				$row['item'] = self::clean_text( $desc, 120 );
			}
			if ( in_array( 'qty', $keys, true ) && is_numeric( $li['qty'] ?? null ) ) {
				$row['qty'] = (string) ( 0 + $li['qty'] );
			}
			if ( in_array( 'size', $keys, true ) ) {
				$size = trim( (string) ( $li['size'] ?? '' ) );
				if ( '' === $size && class_exists( 'ZDZ_Price_Book' ) ) {
					list( $w, $h ) = ZDZ_Price_Book::parse_wh( $desc . ' ' . $sub );
					if ( null !== $w && null !== $h ) {
						$size = ZDZ_Price_Book::fmt_in( $w ) . ' x ' . ZDZ_Price_Book::fmt_in( $h );
					}
				}
				$row['size'] = self::clean_text( $size, 60 );
			}
			$out[] = $row;
			if ( count( $out ) >= self::MAX_ROWS ) {
				break;
			}
		}
		return $out;
	}

	/* =====================================================================================================================
	 * COMPARE — what one confirmation says that the other does not. Rows are matched by key; src and line are not facts.
	 * ===================================================================================================================== */

	public static function same( string $form_key, array $a, array $b ): bool {
		return array() === self::compare( $form_key, $a, $b );
	}

	/** Is this side's confirmation of the order as it stands now (same revision or same facts, and the same PO)? */
	public static function current( array $order, ?array $snap ): bool {
		if ( is_array( $snap ) && array_key_exists( 'po', $snap ) && (string) $snap['po'] !== (string) ( $order['po'] ?? '' ) ) {
			return false;
		}
		return is_array( $snap ) && ( (int) ( $snap['rev'] ?? 0 ) === (int) $order['rev']
			|| self::same( (string) $order['form'], (array) ( $snap['body'] ?? array() ), (array) $order['body'] ) );
	}

	/** @return array<int,array{where:string,what:string,a:string,b:string}> a = the first record, b = the second. */
	public static function compare( string $form_key, array $a, array $b ): array {
		$f   = self::form( $form_key );
		$out = array();
		if ( ! $f ) {
			return $out;
		}
		foreach ( array( 'units' => array( 'unit_fields', __( 'Item', 'zorderz' ) ), 'parts' => array( 'part_fields', __( 'Part', 'zorderz' ) ) ) as $list => $spec ) {
			$fields = (array) $f[ $spec[0] ];
			$ra     = array();
			$rb     = array();
			foreach ( (array) ( $a[ $list ] ?? array() ) as $r ) {
				$ra[ (string) ( $r['k'] ?? '' ) ] = $r;
			}
			foreach ( (array) ( $b[ $list ] ?? array() ) as $r ) {
				$rb[ (string) ( $r['k'] ?? '' ) ] = $r;
			}
			$n = 0;
			foreach ( $rb as $k => $r ) {
				$n++;
				$where = $spec[1] . ' ' . $n;
				if ( ! isset( $ra[ $k ] ) ) {
					$out[] = array( 'where' => $where, 'what' => 'added', 'a' => '', 'b' => self::row_words( $fields, $r ) );
					continue;
				}
				foreach ( $fields as $fd ) {
					$va = (string) ( $ra[ $k ][ $fd['k'] ] ?? '' );
					$vb = (string) ( $r[ $fd['k'] ] ?? '' );
					if ( $va !== $vb ) {
						$out[] = array( 'where' => $where, 'what' => (string) $fd['label'], 'a' => self::shown( $fd, $va ), 'b' => self::shown( $fd, $vb ) );
					}
				}
			}
			foreach ( $ra as $k => $r ) {
				if ( ! isset( $rb[ $k ] ) ) {
					$out[] = array( 'where' => $spec[1], 'what' => 'removed', 'a' => self::row_words( $fields, $r ), 'b' => '' );
				}
			}
		}
		if ( (string) ( $a['notes'] ?? '' ) !== (string) ( $b['notes'] ?? '' ) ) {
			$out[] = array( 'where' => __( 'Order', 'zorderz' ), 'what' => __( 'Notes', 'zorderz' ), 'a' => (string) ( $a['notes'] ?? '' ), 'b' => (string) ( $b['notes'] ?? '' ) );
		}
		$ka = in_array( (string) ( $a['kind'] ?? '' ), self::KINDS, true ) ? (string) $a['kind'] : 'new';
		$kb = in_array( (string) ( $b['kind'] ?? '' ), self::KINDS, true ) ? (string) $b['kind'] : 'new';
		if ( $ka !== $kb ) {
			$labels = self::kind_labels();
			$out[]  = array( 'where' => __( 'Order', 'zorderz' ), 'what' => __( 'For', 'zorderz' ), 'a' => $labels[ $ka ], 'b' => $labels[ $kb ] );
		}
		return $out;
	}

	public static function kind_labels(): array {
		return array(
			'new'      => __( 'New job', 'zorderz' ),
			'addon'    => __( 'Add-on', 'zorderz' ),
			'warranty' => __( 'Warranty / service', 'zorderz' ),
		);
	}

	/** A value as a person reads it: a choice's label, anything else as stored. */
	public static function shown( array $field, string $v ): string {
		if ( '' === $v ) {
			return '';
		}
		foreach ( (array) ( $field['options'] ?? array() ) as $o ) {
			if ( (string) $o[0] === $v ) {
				return (string) $o[1];
			}
		}
		return $v;
	}

	private static function row_words( array $fields, array $r ): string {
		$bits = array();
		foreach ( $fields as $fd ) {
			$v = self::shown( $fd, (string) ( $r[ $fd['k'] ] ?? '' ) );
			if ( '' !== $v ) {
				$bits[] = $v;
			}
		}
		return implode( ', ', $bits );
	}

	/** The differences as one short paragraph (at most $max named). */
	public static function diff_words( array $diff, int $max = 6 ): string {
		$bits = array();
		foreach ( array_slice( $diff, 0, $max ) as $d ) {
			if ( 'added' === $d['what'] ) {
				$bits[] = $d['where'] . ' added (' . $d['b'] . ')';
			} elseif ( 'removed' === $d['what'] ) {
				$bits[] = $d['where'] . ' removed (' . $d['a'] . ')';
			} else {
				$bits[] = $d['where'] . ' ' . strtolower( $d['what'] ) . ': "' . ( '' === $d['a'] ? 'blank' : $d['a'] ) . '" -> "' . ( '' === $d['b'] ? 'blank' : $d['b'] ) . '"';
			}
		}
		$more = count( $diff ) - count( $bits );
		return implode( '; ', $bits ) . ( $more > 0 ? '; and ' . $more . ' more' : '' ) . '.';
	}

	/* =====================================================================================================================
	 * THE STEPS — what may happen to an order now, for this person (PURE over what the caller worked out)
	 * ===================================================================================================================== */

	/**
	 * May this order be marked ordered by this person now? '' when yes, else why not.
	 *   - an open order; the operator side, allowed to sign it off (may_sign);
	 *   - the operator's confirmation stands for the order as it is now (current revision and PO);
	 *   - a PO when one is required (po_refusal), and the go sign (the agreement refusal passed in).
	 */
	public static function order_refusal( array $order, int $uid, array $sides, bool $admin, string $agreement_refusal ): string {
		if ( self::ST_OPEN !== ( $order['status'] ?? '' ) ) {
			return __( 'This order is not open.', 'zorderz' );
		}
		if ( ! self::may_sign( $order, $uid, $sides, $admin ) ) {
			return __( 'Only the people who check and order materials can mark this ordered, and not on their own sale without a second check.', 'zorderz' );
		}
		if ( ! self::current( $order, is_array( $order['operator_ok'] ?? null ) ? $order['operator_ok'] : null ) ) {
			return __( 'Confirm the order as it is now before marking it ordered.', 'zorderz' );
		}
		$po = self::po_refusal( $order );
		if ( '' !== $po ) {
			return $po;
		}
		return $agreement_refusal;
	}

	/** The three steps a person sees: sold / checked / ordered (shop stock: approved / checked / ordered). */
	public static function steps( array $order ): array {
		$first = self::is_stock( $order )
			? 'approved' === self::stock_state( $order )
			: self::current( $order, is_array( $order['seller_ok'] ?? null ) ? $order['seller_ok'] : null );
		return array(
			'first'   => $first,
			'checked' => self::current( $order, is_array( $order['operator_ok'] ?? null ) ? $order['operator_ok'] : null ),
			'ordered' => self::ST_ORDERED === ( $order['status'] ?? '' ),
		);
	}

	/* =====================================================================================================================
	 * VIEW and ACTIONS
	 * ===================================================================================================================== */

	/** One order as this person may see it (costs only for someone the price book allows). */
	public static function order_view( array $o, int $uid, array $sides, bool $admin, string $agreement ): array {
		$f    = self::form( (string) $o['form'] );
		$diff = array();
		if ( is_array( $o['seller_ok'] ) && is_array( $o['operator_ok'] ) ) {
			$diff = self::compare( (string) $o['form'], (array) ( $o['seller_ok']['body'] ?? array() ), (array) ( $o['operator_ok']['body'] ?? array() ) );
		}
		$refuse = self::order_refusal( $o, $uid, $sides, $admin, $agreement );
		return array(
			'id'             => (int) $o['id'],
			'form'           => (string) $o['form'],
			'form_label'     => $f ? (string) $f['label'] : (string) $o['form'],
			'fields'         => $f ? $f['unit_fields'] : array(),
			'part_fields'    => $f ? $f['part_fields'] : array(),
			'paper'          => array(
				'pdf'  => $f && ! empty( $f['paper']['pdf'] ),
				'xlsx' => $f && ! empty( $f['paper']['xlsx'] ),
			),
			'status'         => (string) $o['status'],
			'stock'          => self::is_stock( $o ),
			'rev'            => (int) $o['rev'],
			'body'           => (array) $o['body'],
			'po'             => (string) ( $o['po'] ?? '' ),
			'po_refusal'     => self::po_refusal( $o ),
			'agreement'      => $agreement,
			'steps'          => self::steps( $o ),
			'diff'           => $diff,
			'diff_words'     => $diff ? self::diff_words( $diff ) : '',
			'can_confirm'    => array(
				'seller'   => self::ST_OPEN === $o['status'] && self::may_confirm( $o, self::SIDE_SELLER, $uid, $sides, $admin ),
				'operator' => self::ST_OPEN === $o['status'] && self::may_confirm( $o, self::SIDE_OPERATOR, $uid, $sides, $admin ) && '' === self::po_refusal( $o ),
			),
			'can_edit'       => self::ST_OPEN === $o['status'] && ! empty( $sides ),
			'can_order'      => '' === $refuse,
			'order_why'      => $refuse,
			'ordered_at'     => (string) ( $o['ordered_at'] ?? '' ),
			'approval'       => self::is_stock( $o ) ? array(
				'state'  => self::stock_state( $o ),
				'why'    => (string) ( is_array( $o['approval'] ?? null ) ? ( $o['approval']['why'] ?? '' ) : '' ),
				'answer' => (string) ( is_array( $o['approval'] ?? null ) ? ( $o['approval']['answer'] ?? '' ) : '' ),
			) : null,
		);
	}

	/** The orders panel for a Project, for this person. */
	public static function view( array $project, array $jobs, int $uid ): array {
		$sides = self::sides( $uid, $project );
		$admin = self::site_admin( $uid );
		$stock = self::STOCK_PROJECT === (string) ( $project['id'] ?? '' );
		$agree = ! $stock && class_exists( 'ZJOB_Order_Gate' ) ? ZJOB_Order_Gate::refusal( $project, $jobs ) : '';
		$out   = array();
		foreach ( self::for_project( (string) ( $project['id'] ?? '' ) ) as $o ) {
			$o     = self::sync_po( $o, $project );
			$a     = self::agreement_refusal( $o, $project, $jobs );
			$out[] = self::order_view( $o, $uid, $sides, $admin, $a );
		}
		$forms = array();
		foreach ( self::forms() as $k => $f ) {
			$forms[] = array( 'key' => $k, 'label' => $f['label'] );
		}
		return array(
			'orders'    => $out,
			'forms'     => $forms,
			'kinds'     => self::kind_labels(),
			'can_start' => ! empty( $sides ) && count( $out ) < self::MAX_ORDERS_PER_PROJECT,
			'gate'      => '' !== $agree ? $agree : '',
			'po'        => $stock ? '' : self::po_for( $project ),
			'stock'     => $stock,
		);
	}

	/** One endpoint for every order action (signed-in, nonce, never the shared device). */
	public static function ajax_act(): void {
		check_ajax_referer( ZJOB_NONCE, 'nonce' );
		$uid = (int) get_current_user_id();
		if ( $uid <= 0 || self::is_kiosk( $uid ) || ( class_exists( 'ZDZ_Privacy_View' ) && ZDZ_Privacy_View::is_on( $uid ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Not available on this account.', 'zorderz' ) ), 403 );
		}
		if ( ! self::ready() ) {
			wp_send_json_error( array( 'message' => __( 'Material orders are not set up yet. Reload in a moment.', 'zorderz' ) ), 503 );
		}
		$op  = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$pid = isset( $_POST['project_id'] ) ? sanitize_text_field( wp_unslash( $_POST['project_id'] ) ) : '';
		$res = self::act( $uid, $op, $pid, $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- checked above.
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
		}
		wp_send_json_success( $res );
	}

	/**
	 * Do one action as $uid. Every check is made here (the browser's buttons are only a hint).
	 *
	 * @return array|WP_Error the Project's orders panel after the action
	 */
	public static function act( int $uid, string $op, string $project_id, array $in ) {
		$stock = self::STOCK_PROJECT === $project_id;
		if ( $stock ) {
			// Shop stock: the materials desk's people only; the same answer as a missing Project otherwise.
			if ( ! self::desk_ok( $uid ) ) {
				return new WP_Error( 'zjob_order', __( 'That project is not available.', 'zorderz' ) );
			}
			$project = self::stock_project();
			$jobs    = array();
		} else {
			$project = class_exists( 'Zjob_Project' ) ? Zjob_Project::get( $project_id ) : null;
			if ( ! $project || ! class_exists( 'Zjob_Project_Visibility' ) || ! Zjob_Project_Visibility::actor_may_use_project( $uid, $project ) ) {
				return new WP_Error( 'zjob_order', __( 'That project is not available.', 'zorderz' ) );
			}
			$jobs = Zjob_Project::jobs_for( $project_id );
		}
		$sides = self::sides( $uid, $project );
		$admin = self::site_admin( $uid );
		if ( ! $sides && ! ( $stock && 'approve' === $op && self::may_approve( $uid ) ) ) {
			return new WP_Error( 'zjob_order', __( 'You can see this order but not change it.', 'zorderz' ) );
		}
		$order = null;
		if ( 'start' !== $op ) {
			$order = self::get( (int) ( $in['order_id'] ?? 0 ) );
			if ( ! $order || (string) $order['project_id'] !== $project_id ) {
				return new WP_Error( 'zjob_order', __( 'That order could not be found.', 'zorderz' ) );
			}
			$order = self::sync_po( $order, $project );
		}
		$raw_body = isset( $in['body'] ) ? json_decode( wp_unslash( (string) $in['body'] ), true ) : null;
		switch ( $op ) {
			case 'start':
				$form = sanitize_key( (string) ( $in['form'] ?? 'materials' ) );
				if ( ! self::form( $form ) ) {
					return new WP_Error( 'zjob_order', __( 'Pick an order form.', 'zorderz' ) );
				}
				if ( count( self::for_project( $project_id ) ) >= self::MAX_ORDERS_PER_PROJECT ) {
					return new WP_Error( 'zjob_order', __( 'This project has as many orders as it can hold.', 'zorderz' ) );
				}
				$body = self::clean_body( $form, is_array( $raw_body ) ? $raw_body : array() );
				if ( $stock && '' === $body['notes'] ) {
					return new WP_Error( 'zjob_order', __( 'Say why the shop needs this stock: the approver reads it.', 'zorderz' ) );
				}
				if ( ! $body['units'] && ! $stock ) {
					$body['units'] = self::prefill( $form, self::estimate_lines( $project_id ) );
				}
				$orig = sanitize_text_field( (string) ( $in['orig_project_id'] ?? '' ) );
				if ( 'warranty' === $body['kind'] && '' !== $orig && ( ! Zjob_Project::get( $orig ) || ! Zjob_Project_Visibility::actor_may_use_project( $uid, (array) Zjob_Project::get( $orig ) ) ) ) {
					return new WP_Error( 'zjob_order', __( 'The original job could not be found.', 'zorderz' ) );
				}
				if ( ! self::create( $project_id, $form, $body, $uid, $orig ) ) {
					return new WP_Error( 'zjob_order', __( 'The order could not be saved. Try again.', 'zorderz' ) );
				}
				break;
			case 'save':
				if ( self::ST_OPEN !== $order['status'] ) {
					return new WP_Error( 'zjob_order', __( 'This order is not open.', 'zorderz' ) );
				}
				$rev = self::write_body( $order, self::clean_body( (string) $order['form'], is_array( $raw_body ) ? $raw_body : array() ), $uid, (int) ( $in['rev'] ?? 0 ) );
				if ( 0 === $rev ) {
					return new WP_Error( 'zjob_order', __( 'Someone else changed this order a moment ago. Reload to see their change.', 'zorderz' ) );
				}
				break;
			case 'confirm':
				$side = sanitize_key( (string) ( $in['side'] ?? '' ) );
				if ( ! in_array( $side, array( self::SIDE_SELLER, self::SIDE_OPERATOR ), true ) || ! self::may_confirm( $order, $side, $uid, $sides, $admin ) ) {
					return new WP_Error( 'zjob_order', __( 'You cannot confirm this side of the order.', 'zorderz' ) );
				}
				if ( self::SIDE_OPERATOR === $side && '' !== self::po_refusal( $order ) ) {
					return new WP_Error( 'zjob_order', self::po_refusal( $order ) );
				}
				if ( (int) ( $in['rev'] ?? 0 ) !== (int) $order['rev'] || ! self::write_confirm( $order, $side, $uid ) ) {
					return new WP_Error( 'zjob_order', __( 'Someone else changed this order a moment ago. Reload to see their change.', 'zorderz' ) );
				}
				break;
			case 'ordered':
				$why = self::order_refusal( $order, $uid, $sides, $admin, self::agreement_refusal( $order, $project, $jobs ) );
				if ( '' !== $why ) {
					return new WP_Error( 'zjob_order', $why );
				}
				global $wpdb;
				$via    = sanitize_key( (string) ( $in['via'] ?? '' ) );
				$placer = (int) ( $in['placed_by'] ?? $uid );
				$n      = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET status = %s, placed_by = %d, placed_via = %s, ordered_by = %d, ordered_at = %s WHERE id = %d AND rev = %d AND status = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name; values are placeholders.
					self::ST_ORDERED, $placer > 0 ? $placer : $uid, in_array( $via, self::VIA, true ) ? $via : '', $uid, current_time( 'mysql', true ), (int) $order['id'], (int) $order['rev'], self::ST_OPEN ) );
				if ( 1 !== (int) $n ) {
					return new WP_Error( 'zjob_order', __( 'Someone else changed this order a moment ago. Reload to see their change.', 'zorderz' ) );
				}
				break;
			case 'undo':
				if ( self::ST_ORDERED !== $order['status'] || ! in_array( self::SIDE_OPERATOR, $sides, true ) ) {
					return new WP_Error( 'zjob_order', __( 'Only the people who order materials can undo "Ordered".', 'zorderz' ) );
				}
				global $wpdb;
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET status = %s, ordered_by = 0, ordered_at = NULL WHERE id = %d AND status = %s', self::ST_OPEN, (int) $order['id'], self::ST_ORDERED ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name; values are placeholders.
				break;
			case 'approve':
				// A shop-stock request answered by an approver: yes or no, for the order exactly as it stands now.
				if ( ! self::is_stock( $order ) || self::ST_OPEN !== $order['status'] || ! self::may_approve( $uid ) ) {
					return new WP_Error( 'zjob_order', __( 'Only an approver can answer a shop-stock request.', 'zorderz' ) );
				}
				global $wpdb;
				$a = is_array( $order['approval'] ?? null ) ? $order['approval'] : array();
				$a = array_merge( $a, array(
					'state'  => empty( $in['yes'] ) ? 'declined' : 'approved',
					'by'     => $uid,
					'at'     => current_time( 'mysql', true ),
					'body'   => md5( (string) wp_json_encode( self::approval_basis( (array) $order['body'] ) ) ),
					'answer' => self::clean_text( $in['answer'] ?? '', 300 ),
				) );
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET approval = %s WHERE id = %d AND rev = %d AND status = %s', wp_json_encode( $a ), (int) $order['id'], (int) $order['rev'], self::ST_OPEN ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name; values are placeholders.
				break;
			case 'discard':
				if ( self::ST_OPEN !== $order['status'] ) {
					return new WP_Error( 'zjob_order', __( 'Only an open order can be discarded.', 'zorderz' ) );
				}
				global $wpdb;
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET status = %s, updated_by = %d, updated_at = %s WHERE id = %d AND status = %s', self::ST_DISCARDED, $uid, current_time( 'mysql', true ), (int) $order['id'], self::ST_OPEN ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name; values are placeholders.
				break;
			default:
				return new WP_Error( 'zjob_order', __( 'That is not something an order can do.', 'zorderz' ) );
		}
		// Counted in the platform's Usage view (a number at most, never a name): an order started, an order placed.
		if ( class_exists( 'ZDZ_Usage' ) && method_exists( 'ZDZ_Usage', 'record' ) && in_array( $op, array( 'start', 'ordered' ), true ) ) {
			ZDZ_Usage::record( 'jobs', 'start' === $op ? 'order_started' : 'order_placed', $order ? (int) $order['id'] : 0 );
		}
		return self::view( $project, $jobs, $uid );
	}

	/** The estimate's own lines for this Project ({desc, sub, qty}), from the estimate app's record. Never amounts. */
	public static function estimate_lines( string $project_id ): array {
		global $wpdb;
		$eid = class_exists( 'ZJOB_Project_Refs' ) ? (int) ZJOB_Project_Refs::get( $project_id, 'estimate' ) : 0;
		if ( $eid <= 0 || ! isset( $wpdb ) || ! class_exists( 'ZEST_DB' ) || ! is_callable( array( 'ZEST_DB', 'estimates_table' ) ) ) {
			return array();
		}
		$json = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT items_json FROM ' . ZEST_DB::estimates_table() . ' WHERE id = %d', $eid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name; the id is a placeholder.
		$out  = array();
		foreach ( (array) json_decode( $json, true ) as $li ) {
			if ( is_array( $li ) ) {
				$out[] = array(
					'desc' => (string) ( $li['description'] ?? '' ),
					'sub'  => (string) ( $li['sub_description'] ?? '' ),
					'qty'  => $li['quantity'] ?? ( $li['qty'] ?? '' ),
					'size' => (string) ( $li['dims'] ?? ( $li['size'] ?? '' ) ),
				);
			}
		}
		return $out;
	}
}
