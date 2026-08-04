<?php
/**
 * Zorderz Stock — admin dashboard page + AJAX endpoints.
 *
 * EVERY endpoint gates on REAL app-access via ZDZ_Plugin_API::user_can_access_app($uid, 'stock').
 * The blanket zdz_access_app cap is granted to every custom role (including the shared kiosk), so
 * gating on it would let any logged-in user run inventory writes. The v1.1.6 source already moved
 * to real app-access; that is preserved. A thin local user_can_access() delegates to the canonical
 * helper when present and falls back to a dual-cap check on an older theme.
 *
 * @package Zorderz\Stock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSTOCK_Dashboard {

	/** Canonical app-access gate. */
	public static function user_can_access() {
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return false;
		}
		if ( is_callable( array( 'ZDZ_Plugin_API', 'user_can_access_app' ) ) ) {
			return ZDZ_Plugin_API::user_can_access_app( $uid, ZSTOCK_APP_ID );
		}
		// Legacy fallback (older theme without the helper).
		return current_user_can( 'manage_options' ) || current_user_can( 'zdz_access_app' );
	}

	public static function init() {
		// Priority 11: the parent menu (zstock-settings, registered by ZSTOCK_Admin at the
		// default priority 10) must exist before this submenu is added. app.php inits this
		// class before ZSTOCK_Admin, so at equal priority the submenu was registering first
		// and orphaning - which made admin.php?page=zstock-dashboard deny access and produced
		// a broken menu link. Running at 11 guarantees the parent is registered first.
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ), 11 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );

		$actions = array(
			'zstock_get_stock_summary' => 'ajax_get_stock_summary',
			'zstock_get_item_detail'   => 'ajax_get_item_detail',
			'zstock_upload_invoice'    => 'ajax_upload_invoice',
			'zstock_approve_order'     => 'ajax_approve_order',
			'zstock_reject_order'      => 'ajax_reject_order',
			'zstock_manual_adjust'     => 'ajax_manual_adjust',
			'zstock_get_orders'        => 'ajax_get_orders',
			'zstock_sync_consumption'  => 'ajax_sync_consumption',
			'zstock_set_policy'        => 'ajax_set_policy',
			'zstock_get_low_stock'     => 'ajax_get_low_stock',
			'zstock_get_forecast'      => 'ajax_get_forecast',
		);
		foreach ( $actions as $hook => $method ) {
			add_action( 'wp_ajax_' . $hook, array( __CLASS__, $method ) );
		}
	}

	/** Shared preamble for every endpoint: verify nonce + real app-access. */
	private static function guard() {
		check_ajax_referer( ZSTOCK_NONCE, 'nonce' );
		if ( ! self::user_can_access() ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'zorderz' ) ) );
		}
	}

	/* ================================================================
	 * Admin page
	 * ================================================================ */

	public static function register_admin_page() {
		add_submenu_page(
			'zstock-settings',
			__( 'Stock Dashboard', 'zorderz' ),
			__( 'Dashboard', 'zorderz' ),
			'read', // 'read' so custom roles can reach it; the real gate is user_can_access().
			'zstock-dashboard',
			array( __CLASS__, 'render_dashboard' )
		);
	}

	public static function enqueue_admin_assets( $hook ) {
		if ( false === strpos( (string) $hook, 'zstock-dashboard' ) ) {
			return;
		}
		wp_enqueue_style( 'zstock-dashboard-css', ZSTOCK_URL . 'assets/css/dashboard.css', array(), zstock_asset_ver( 'assets/css/dashboard.css' ) );
		wp_enqueue_script( 'zstock-dashboard-js', ZSTOCK_URL . 'assets/js/dashboard.js', array(), zstock_asset_ver( 'assets/js/dashboard.js' ), true );
		wp_localize_script(
			'zstock-dashboard-js',
			'zstockData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( ZSTOCK_NONCE ),
			)
		);
	}

	public static function render_dashboard() {
		if ( ! self::user_can_access() ) {
			wp_die( esc_html__( 'You do not have access to this app.', 'zorderz' ) );
		}
		$is_mobile = isset( $_GET['zdz_mobile'] ) && '1' === $_GET['zdz_mobile']; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $is_mobile ) {
			echo '<style>#wpadminbar,#adminmenuwrap,#adminmenuback,#wpfooter,.update-nag,.notice{display:none!important;}#wpcontent{margin-left:0!important;padding-top:0!important;}html{margin-top:0!important;}</style>';
		}
		echo '<div class="wrap zstock-wrap"><h1>' . esc_html__( 'Stock', 'zorderz' ) . '</h1>';
		echo '<div id="zstock-dashboard-root"><p class="description">' . esc_html__( 'Loading stock dashboard…', 'zorderz' ) . '</p></div></div>';
	}

	/* ================================================================
	 * AJAX endpoints (all gated)
	 * ================================================================ */

	public static function ajax_get_stock_summary() {
		self::guard();
		wp_send_json_success( ZSTOCK_Engine::get_stock_summary() );
	}

	public static function ajax_get_low_stock() {
		self::guard();
		$needs = ZSTOCK_Engine::calculate_reorder_needs();
		wp_send_json_success( array( 'items' => $needs, 'count' => count( $needs ) ) );
	}

	public static function ajax_get_forecast() {
		self::guard();
		$lookback = max( 7, min( 365, absint( $_POST['lookback_days'] ?? 90 ) ) );
		$forecast = max( 7, min( 180, absint( $_POST['forecast_days'] ?? 30 ) ) );
		wp_send_json_success( ZSTOCK_Engine::forecast_usage( $lookback, $forecast ) );
	}

	public static function ajax_get_item_detail() {
		self::guard();
		$item_id = sanitize_text_field( wp_unslash( $_POST['item_id'] ?? '' ) );
		if ( '' === $item_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid item id.', 'zorderz' ) ) );
		}
		global $wpdb;
		$ledger = ZSTOCK_DB::ledger_table();
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifier from constant.
				"SELECT l.*, u.display_name AS user_name FROM `{$ledger}` l
				 LEFT JOIN {$wpdb->users} u ON l.user_id = u.ID
				 WHERE l.item_id = %s ORDER BY l.created_at DESC LIMIT 100",
				$item_id
			),
			ARRAY_A
		);
		wp_send_json_success(
			array(
				'item'   => ZSTOCK_Catalog::view( $item_id ),
				'ledger' => is_array( $rows ) ? $rows : array(),
			)
		);
	}

	public static function ajax_manual_adjust() {
		self::guard();
		$item_id = sanitize_text_field( wp_unslash( $_POST['item_id'] ?? '' ) );
		$type    = sanitize_text_field( wp_unslash( $_POST['adjustment_type'] ?? $_POST['type'] ?? 'MANUAL_ADJUST' ) );
		$notes   = sanitize_text_field( wp_unslash( $_POST['notes'] ?? '' ) );
		if ( '' === $item_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid item id.', 'zorderz' ) ) );
		}

		$valid = array( 'MANUAL_ADJUST', 'CYCLE_COUNT', 'WASTE', 'RETURN', 'DAMAGE', 'TRANSFER' );
		if ( 'MANUAL_ADJUSTMENT' === $type ) {
			$type = 'CYCLE_COUNT'; // widget's cycle-count label.
		}
		if ( ! in_array( $type, $valid, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid adjustment type.', 'zorderz' ) ) );
		}

		// CYCLE_COUNT sends the NEW actual count; convert to a delta.
		if ( 'CYCLE_COUNT' === $type || isset( $_POST['new_quantity'] ) ) {
			$new_count = (float) ( $_POST['new_quantity'] ?? $_POST['quantity'] ?? 0 );
			$current   = self::current_stock( $item_id );
			$qty       = $new_count - $current;
			$notes     = 'Cycle count: actual=' . $new_count . ', previous=' . $current . ( $notes ? '. ' . $notes : '' );
		} else {
			$qty = (float) ( $_POST['quantity'] ?? 0 );
			if ( in_array( $type, array( 'WASTE', 'DAMAGE' ), true ) && $qty > 0 ) {
				$qty = -$qty;
			}
		}

		if ( 0.0 === (float) $qty ) {
			wp_send_json_error( array( 'message' => __( 'No change to record.', 'zorderz' ) ) );
		}

		$res = ZSTOCK_Engine::add_ledger_entry( $item_id, $qty, $type, 'manual', '', '', $notes );
		if ( ! $res ) {
			wp_send_json_error( array( 'message' => __( 'Failed to record adjustment.', 'zorderz' ) ) );
		}
		self::audit( 'stock_adjusted', $type . ' for ' . $item_id . ': qty=' . $qty );
		wp_send_json_success( array( 'ledger_id' => $res, 'item_id' => $item_id, 'new_stock' => self::current_stock( $item_id ), 'adjustment' => $qty ) );
	}

	public static function ajax_set_policy() {
		self::guard();
		$item_id = sanitize_text_field( wp_unslash( $_POST['item_id'] ?? '' ) );
		if ( '' === $item_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid item id.', 'zorderz' ) ) );
		}
		$par     = max( 0, (float) ( $_POST['par_level'] ?? 0 ) );
		$reorder = max( 0, (float) ( $_POST['reorder_point'] ?? 0 ) );
		ZSTOCK_Engine::set_stock_policy( $item_id, $par, $reorder );
		self::audit( 'policy_set', $item_id . ': par=' . $par . ', reorder=' . $reorder );
		wp_send_json_success( array( 'item' => ZSTOCK_Catalog::view( $item_id, array( 'par_level' => $par, 'reorder_point' => $reorder ) ) ) );
	}

	public static function ajax_upload_invoice() {
		self::guard();
		if ( empty( $_FILES['invoice_file'] ) && empty( $_FILES['invoice'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No invoice file uploaded.', 'zorderz' ) ) );
		}
		$file = ! empty( $_FILES['invoice_file'] ) ? $_FILES['invoice_file'] : $_FILES['invoice']; // phpcs:ignore

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$upload = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => array(
					'jpg|jpeg' => 'image/jpeg',
					'png'      => 'image/png',
					'webp'     => 'image/webp',
					'heic'     => 'image/heic',
					'gif'      => 'image/gif',
					'pdf'      => 'application/pdf',
				),
			)
		);
		if ( isset( $upload['error'] ) ) {
			wp_send_json_error( array( 'message' => 'Upload failed: ' . $upload['error'] ) );
		}
		$file_url = $upload['url'];

		$parsed = ZSTOCK_Engine::parse_supplier_invoice( $file_url );
		if ( isset( $parsed['error'] ) ) {
			wp_send_json_error( array( 'message' => $parsed['error'] ) );
		}
		$match = ZSTOCK_Engine::match_line_items( $parsed['line_items'] );

		global $wpdb;
		$supplier = sanitize_text_field( $parsed['supplier_name'] );
		if ( '' === $supplier ) {
			$supplier = (string) get_option( 'zstock_default_supplier_name', __( 'Unknown Supplier', 'zorderz' ) );
		}
		$wpdb->insert(
			ZSTOCK_DB::orders_table(),
			array(
				'supplier_name'  => $supplier,
				'invoice_number' => sanitize_text_field( $parsed['invoice_number'] ),
				'invoice_date'   => self::safe_date( $parsed['invoice_date'] ),
				'subtotal'       => (float) $parsed['subtotal'],
				'tax'            => (float) $parsed['tax'],
				'total_amount'   => (float) $parsed['total'],
				'status'         => 'draft',
				'file_url'       => esc_url_raw( $file_url ),
				'created_by'     => get_current_user_id(),
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%d', '%s' )
		);
		$order_id = (int) $wpdb->insert_id;
		if ( ! $order_id ) {
			wp_send_json_error( array( 'message' => __( 'Failed to create draft order.', 'zorderz' ) ) );
		}

		$all = array_merge( $match['matched'], $match['unmatched'] );
		foreach ( $all as $line ) {
			$wpdb->insert(
				ZSTOCK_DB::order_items_table(),
				array(
					'order_id'             => $order_id,
					'item_id'              => (string) ( $line['item_id'] ?? '' ),
					'sku'                  => sanitize_text_field( $line['sku'] ?? '' ),
					'supplier_description' => sanitize_text_field( $line['description'] ?? '' ),
					'quantity'             => (float) ( $line['quantity'] ?? 0 ),
					'unit'                 => sanitize_text_field( $line['unit'] ?? 'each' ),
					'unit_price'           => (float) ( $line['unit_price'] ?? 0 ),
					'total'                => (float) ( $line['total'] ?? 0 ),
					'matched'              => ! empty( $line['item_id'] ) ? 1 : 0,
					'created_at'           => current_time( 'mysql' ),
				),
				array( '%d', '%s', '%s', '%s', '%f', '%s', '%f', '%f', '%d', '%s' )
			);
		}
		self::audit( 'invoice_uploaded', 'Uploaded invoice from ' . $supplier . ', ' . count( $all ) . ' lines' );

		// Shape parsed lines for the review UI (name/qty/unit/cost/confidence).
		$review = array();
		foreach ( $all as $line ) {
			$review[] = array(
				'name'       => $line['matched_name'] ?? $line['description'] ?? __( 'Unknown', 'zorderz' ),
				'quantity'   => (float) ( $line['quantity'] ?? 0 ),
				'unit'       => $line['unit'] ?? 'each',
				'unit_cost'  => (float) ( $line['unit_price'] ?? 0 ),
				'confidence' => ! empty( $line['item_id'] ) ? 'HIGH' : 'LOW',
			);
		}
		wp_send_json_success(
			array(
				'order_id'      => $order_id,
				'supplier_name' => $supplier,
				'items'         => $review,
				'matched_count' => count( $match['matched'] ),
			)
		);
	}

	public static function ajax_approve_order() {
		self::guard();
		// The widget posts reject=1 to the same action for reject.
		if ( ! empty( $_POST['reject'] ) ) {
			self::reject_order();
			return;
		}
		$order_id = absint( $_POST['order_id'] ?? 0 );
		if ( ! $order_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order id.', 'zorderz' ) ) );
		}
		global $wpdb;
		$orders = ZSTOCK_DB::orders_table();
		$order  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$orders}` WHERE id = %d", $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'zorderz' ) ) );
		}
		if ( 'draft' !== $order['status'] ) {
			wp_send_json_error( array( 'message' => __( 'Order is not a draft.', 'zorderz' ) ) );
		}
		$items_tbl = ZSTOCK_DB::order_items_table();
		$lines     = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifier from constant.
				"SELECT * FROM `{$items_tbl}` WHERE order_id = %d AND matched = 1 AND item_id <> ''",
				$order_id
			),
			ARRAY_A
		);
		$committed = 0;
		foreach ( (array) $lines as $line ) {
			$qty = (float) $line['quantity'];
			if ( $qty <= 0 ) {
				continue;
			}
			if ( ZSTOCK_Engine::add_ledger_entry( (string) $line['item_id'], $qty, 'SUPPLIER_ORDER', 'order', (string) $order_id, 'Order #' . $order_id . ' / ' . $order['invoice_number'], 'Supplier: ' . $order['supplier_name'] ) ) {
				$committed++;
			}
		}
		$wpdb->update(
			$orders,
			array( 'status' => 'approved', 'approved_by' => get_current_user_id(), 'approved_at' => current_time( 'mysql' ) ),
			array( 'id' => $order_id )
		);
		self::audit( 'order_approved', 'Order #' . $order_id . ' (' . $committed . ' committed)' );
		wp_send_json_success( array( 'order_id' => $order_id, 'items_committed' => $committed ) );
	}

	public static function ajax_reject_order() {
		self::guard();
		self::reject_order();
	}

	private static function reject_order() {
		$order_id = absint( $_POST['order_id'] ?? 0 );
		$reason   = sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) );
		if ( ! $order_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order id.', 'zorderz' ) ) );
		}
		global $wpdb;
		$orders = ZSTOCK_DB::orders_table();
		$order  = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM `{$orders}` WHERE id = %d", $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'zorderz' ) ) );
		}
		if ( 'draft' !== $order['status'] ) {
			wp_send_json_error( array( 'message' => __( 'Only draft orders can be rejected.', 'zorderz' ) ) );
		}
		$wpdb->update(
			$orders,
			array( 'status' => 'rejected', 'reject_reason' => $reason, 'approved_by' => get_current_user_id(), 'approved_at' => current_time( 'mysql' ) ),
			array( 'id' => $order_id )
		);
		self::audit( 'order_rejected', 'Order #' . $order_id . ( $reason ? ': ' . $reason : '' ) );
		wp_send_json_success( array( 'order_id' => $order_id ) );
	}

	public static function ajax_get_orders() {
		self::guard();
		$status = sanitize_text_field( wp_unslash( $_POST['status'] ?? '' ) );
		$limit  = min( 200, absint( $_POST['limit'] ?? 50 ) );
		global $wpdb;
		$orders = ZSTOCK_DB::orders_table();
		if ( '' !== $status ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifier from constant.
					"SELECT * FROM `{$orders}` WHERE status = %s ORDER BY created_at DESC LIMIT %d",
					$status,
					$limit
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifier from constant.
					"SELECT * FROM `{$orders}` ORDER BY created_at DESC LIMIT %d",
					$limit
				),
				ARRAY_A
			);
		}
		wp_send_json_success( is_array( $rows ) ? $rows : array() );
	}

	public static function ajax_sync_consumption() {
		self::guard();
		$res = ZSTOCK_Engine::run_consumption_sweep();
		self::audit( 'consumption_sync', $res['invoices_processed'] . ' invoices, ' . $res['items_consumed'] . ' deductions' );
		$res['synced_count'] = $res['items_consumed'];
		wp_send_json_success( $res );
	}

	/* ================================================================
	 * Helpers
	 * ================================================================ */

	private static function current_stock( $item_id ) {
		global $wpdb;
		$tbl = ZSTOCK_DB::stock_table();
		return (float) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifier from constant.
				"SELECT current_stock FROM `{$tbl}` WHERE item_id = %s",
				(string) $item_id
			)
		);
	}

	/** Coerce an AI-parsed date to Y-m-d or null (never a bad DATE literal). */
	private static function safe_date( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return null;
		}
		$ts = strtotime( $raw );
		return $ts ? gmdate( 'Y-m-d', $ts ) : null;
	}

	/** Audit-log through the theme dashboard log when available (nothing silent). */
	private static function audit( $action, $detail ) {
		if ( class_exists( 'ZDZ_Admin_Dashboard' ) && method_exists( 'ZDZ_Admin_Dashboard', 'log_action' ) ) {
			ZDZ_Admin_Dashboard::log_action( get_current_user_id(), $action, $detail, ZSTOCK_APP_ID );
		} else {
			zstock_log( $action . ': ' . $detail );
		}
	}
}
