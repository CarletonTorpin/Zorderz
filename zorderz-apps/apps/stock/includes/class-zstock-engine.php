<?php
/**
 * Zorderz Stock — inventory engine.
 *
 * Stock summary, reorder needs, usage forecasting, the immutable ledger, BOM-driven consumption,
 * AI invoice parsing and the inventory brain — all catalog-driven through ZSTOCK_Catalog (the
 * Item Engine binding). Current stock is the SUM of the ledger; wp_zstock_stock caches it.
 *
 * AI goes through the shared ZDZ_Core_Poe client (credentials from ZDZ_Core_Settings); there is
 * no plugin-local HTTP client and no credential cascade. Prompts are assembled at runtime from
 * the Business Profile + the live catalog and the in-repo neutral template — nothing about any
 * one business is baked in.
 *
 * @package Zorderz\Stock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSTOCK_Engine {

	/* ================================================================
	 * LEDGER (source of truth) + cached stock state
	 * ================================================================ */

	/**
	 * Insert a ledger entry and update the cached current_stock. The ledger is append-only.
	 *
	 * @param string $item_id   Item Engine item id.
	 * @param float  $quantity  Positive = stock in, negative = stock out.
	 * @param string $type      SUPPLIER_ORDER | JOB_CONSUMPTION | MANUAL_ADJUST | CYCLE_COUNT |
	 *                          WASTE | DAMAGE | RETURN | TRANSFER.
	 * @param string $ref_type  Reference kind (e.g. 'order', 'invoice').
	 * @param string $ref_id    Reference id.
	 * @param string $ref_label Human label.
	 * @param string $notes     Optional notes.
	 * @param int    $user_id   Actor (defaults to current user).
	 * @return int|false Insert id, or false.
	 */
	public static function add_ledger_entry( $item_id, $quantity, $type, $ref_type = '', $ref_id = '', $ref_label = '', $notes = '', $user_id = 0 ) {
		global $wpdb;
		$item_id = (string) $item_id;
		if ( '' === $item_id ) {
			return false;
		}
		$user_id  = $user_id ? (int) $user_id : get_current_user_id();
		$inserted = $wpdb->insert(
			ZSTOCK_DB::ledger_table(),
			array(
				'item_id'          => $item_id,
				'quantity_change'  => (float) $quantity,
				'transaction_type' => (string) $type,
				'reference_type'   => (string) $ref_type,
				'reference_id'     => (string) $ref_id,
				'reference_label'  => (string) $ref_label,
				'user_id'          => $user_id,
				'notes'            => (string) $notes,
				'created_at'       => current_time( 'mysql' ),
			),
			array( '%s', '%f', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		if ( ! $inserted ) {
			return false;
		}
		self::bump_stock( $item_id, (float) $quantity );
		return (int) $wpdb->insert_id;
	}

	/** Increment the cached stock state for an item (upsert). */
	private static function bump_stock( $item_id, $delta ) {
		global $wpdb;
		$tbl = ZSTOCK_DB::stock_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifier from constant; values prepared.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$tbl}` (item_id, current_stock, updated_at) VALUES (%s, %f, %s)
				 ON DUPLICATE KEY UPDATE current_stock = current_stock + VALUES(current_stock), updated_at = VALUES(updated_at)",
				(string) $item_id,
				(float) $delta,
				current_time( 'mysql' )
			)
		);
	}

	/** Recompute current_stock for an item from its full ledger history (ledger = truth). */
	public static function recalculate_stock( $item_id ) {
		global $wpdb;
		$ledger = ZSTOCK_DB::ledger_table();
		$sum    = (float) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifier from constant.
				"SELECT COALESCE(SUM(quantity_change),0) FROM `{$ledger}` WHERE item_id = %s",
				(string) $item_id
			)
		);
		$tbl = ZSTOCK_DB::stock_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifier from constant; values prepared.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$tbl}` (item_id, current_stock, updated_at) VALUES (%s, %f, %s)
				 ON DUPLICATE KEY UPDATE current_stock = VALUES(current_stock), updated_at = VALUES(updated_at)",
				(string) $item_id,
				$sum,
				current_time( 'mysql' )
			)
		);
		return $sum;
	}

	/** Set an item's par/reorder policy override (null clears an override → inherit from catalog). */
	public static function set_stock_policy( $item_id, $par, $reorder ) {
		global $wpdb;
		$tbl = ZSTOCK_DB::stock_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifier from constant; values prepared.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$tbl}` (item_id, current_stock, par_level, reorder_point, updated_at)
				 VALUES (%s, 0, %f, %f, %s)
				 ON DUPLICATE KEY UPDATE par_level = VALUES(par_level), reorder_point = VALUES(reorder_point), updated_at = VALUES(updated_at)",
				(string) $item_id,
				(float) $par,
				(float) $reorder,
				current_time( 'mysql' )
			)
		);
		return true;
	}

	/** All cached stock-state rows keyed by item_id. */
	private static function stock_state() {
		global $wpdb;
		$tbl = ZSTOCK_DB::stock_table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) !== $tbl ) {
			return array();
		}
		$out = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifier from constant.
		foreach ( (array) $wpdb->get_results( "SELECT * FROM `{$tbl}`", ARRAY_A ) as $r ) {
			$out[ (string) $r['item_id'] ] = $r;
		}
		return $out;
	}

	/** Every tracked item as a display view (catalog + state merged). Neutral when empty. */
	public static function items() {
		$state = self::stock_state();
		$out   = array();
		foreach ( ZSTOCK_Catalog::stock_item_ids() as $id ) {
			$view = ZSTOCK_Catalog::view( $id, $state[ $id ] ?? array() );
			if ( $view ) {
				$out[] = $view;
			}
		}
		return $out;
	}

	/* ================================================================
	 * SUMMARY / REORDER / FORECAST
	 * ================================================================ */

	/** Status bucket for an item view. */
	private static function status_of( array $item ) {
		$stock   = (float) $item['current_stock'];
		$reorder = (float) $item['reorder_point'];
		$par     = (float) $item['par_level'];
		if ( $stock <= 0 ) {
			return 'out';
		}
		if ( $stock <= $reorder ) {
			return 'critical';
		}
		if ( $stock <= $par ) {
			return 'low';
		}
		return 'good';
	}

	/**
	 * Dashboard summary payload. Empty catalog ⇒ zeros + empty lists (neutral, no crash).
	 * Field names are the contract the JS reads — do not rename without a parallel JS update.
	 */
	public static function get_stock_summary() {
		$items           = self::items();
		$low_stock_items = array();
		$low_count       = 0;
		$total_value     = 0.0;

		foreach ( $items as $item ) {
			$total_value += (float) $item['current_stock'] * (float) $item['unit_cost'];
			if ( 'good' !== self::status_of( $item ) ) {
				$low_count++;
				if ( count( $low_stock_items ) < 5 ) {
					$low_stock_items[] = $item;
				}
			}
		}

		global $wpdb;
		$orders_tbl     = ZSTOCK_DB::orders_table();
		$pending_orders = 0;
		$recent_orders  = array();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $orders_tbl ) ) === $orders_tbl ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifier from constant.
			$pending_orders = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$orders_tbl}` WHERE status = 'draft'" );
			$recent_orders  = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifier from constant.
				"SELECT id, supplier_name, invoice_number, status, total_amount AS total, created_at
				 FROM `{$orders_tbl}` ORDER BY created_at DESC LIMIT 5",
				ARRAY_A
			);
			if ( ! is_array( $recent_orders ) ) {
				$recent_orders = array();
			}
		}

		return array(
			'total_items'     => count( $items ),
			'low_stock_count' => $low_count,
			'pending_orders'  => $pending_orders,
			'low_stock_items' => $low_stock_items,
			'recent_orders'   => $recent_orders,
			'all_items'       => $items,
			'total_value'     => round( $total_value, 2 ),
			'catalog_empty'   => ZSTOCK_Catalog::is_empty(),
		);
	}

	/** Items at or below their reorder point, with a recommended order quantity to reach par. */
	public static function calculate_reorder_needs() {
		$needs = array();
		foreach ( self::items() as $item ) {
			if ( (float) $item['current_stock'] > (float) $item['reorder_point'] ) {
				continue;
			}
			$order_qty     = max( 0, (float) $item['par_level'] - (float) $item['current_stock'] );
			$item['order_qty']      = $order_qty;
			$item['estimated_cost'] = round( $order_qty * (float) $item['unit_cost'], 2 );
			$item['urgency']        = (float) $item['current_stock'] <= 0 ? 'critical' : 'low';
			$needs[]                = $item;
		}
		return $needs;
	}

	/**
	 * Project usage forward from historical consumption (negative ledger entries).
	 *
	 * @param int $lookback_days
	 * @param int $forecast_days
	 */
	public static function forecast_usage( $lookback_days = 90, $forecast_days = 30 ) {
		global $wpdb;
		$ledger        = ZSTOCK_DB::ledger_table();
		$lookback_days = max( 1, (int) $lookback_days );
		$forecast_days = max( 1, (int) $forecast_days );
		$since         = gmdate( 'Y-m-d H:i:s', time() - ( $lookback_days * DAY_IN_SECONDS ) );

		$consumed = array();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ledger ) ) === $ledger ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifier from constant.
					"SELECT item_id, SUM(ABS(quantity_change)) AS total FROM `{$ledger}`
					 WHERE quantity_change < 0 AND created_at >= %s GROUP BY item_id",
					$since
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $r ) {
				$consumed[ (string) $r['item_id'] ] = (float) $r['total'];
			}
		}

		$forecast = array();
		foreach ( self::items() as $item ) {
			$id              = $item['id'];
			$stock           = (float) $item['current_stock'];
			$total_consumed  = $consumed[ $id ] ?? 0.0;
			$avg_daily       = $total_consumed / $lookback_days;
			$projected_usage = $avg_daily * $forecast_days;
			$days_of_supply  = $avg_daily > 0 ? floor( $stock / $avg_daily ) : ( $stock > 0 ? 999 : 0 );
			$projected_stock = max( 0, $stock - $projected_usage );
			$recommended     = ( $projected_stock <= (float) $item['reorder_point'] )
				? max( 0, (float) $item['par_level'] - $projected_stock )
				: 0;

			$forecast[] = array(
				'item_id'           => $id,
				'name'              => $item['name'],
				'unit'              => $item['unit'],
				'current_stock'     => $stock,
				'avg_daily_usage'   => round( $avg_daily, 2 ),
				'forecasted_usage'  => round( $projected_usage, 2 ),
				'days_of_supply'    => $days_of_supply,
				'projected_stock'   => round( $projected_stock, 2 ),
				'recommended_order' => round( $recommended, 2 ),
				'estimated_cost'    => round( $recommended * (float) $item['unit_cost'], 2 ),
			);
		}
		usort(
			$forecast,
			function ( $a, $b ) {
				return $a['days_of_supply'] <=> $b['days_of_supply'];
			}
		);
		return array(
			'lookback_days' => $lookback_days,
			'forecast_days' => $forecast_days,
			'generated_at'  => current_time( 'mysql' ),
			'items'         => $forecast,
		);
	}

	/* ================================================================
	 * BOM-DRIVEN CONSUMPTION (billed jobs → stock deductions)
	 * ================================================================ */

	/**
	 * Consumption entries for one billed invoice, via each line's Item Engine `consumes[]`.
	 *
	 * @param array $invoice { id|invoiceid, lines:[ { name, qty|quantity } ] }
	 * @return array<int,array>
	 */
	public static function calculate_consumption( array $invoice ) {
		$invoice_id  = $invoice['id'] ?? $invoice['invoiceid'] ?? 0;
		$lines       = is_array( $invoice['lines'] ?? null ) ? $invoice['lines'] : array();
		$consumption = array();
		foreach ( $lines as $line ) {
			$name = trim( (string) ( $line['name'] ?? '' ) );
			$qty  = (float) ( $line['qty'] ?? $line['quantity'] ?? 1 );
			if ( '' === $name || $qty <= 0 ) {
				continue;
			}
			foreach ( ZSTOCK_Catalog::resolve_consumption( $name, $qty ) as $c ) {
				$c['source_invoice_id'] = $invoice_id;
				$consumption[]          = $c;
			}
		}
		return $consumption;
	}

	/**
	 * The auto consumption sweep: pull billed invoices from the configured source (FreshBooks by
	 * default; overridable via `zstock_consumption_invoices`), and post a JOB_CONSUMPTION deduction
	 * per resolved BOM entry — de-duped by source invoice id. A missing/unconfigured source is a
	 * logged disposition, never a crash.
	 *
	 * @return array summary
	 */
	public static function run_consumption_sweep() {
		global $wpdb;
		$since     = (string) get_option( 'zstock_last_sync', '' );
		$since     = $since ?: gmdate( 'Y-m-d', time() - ( 30 * DAY_IN_SECONDS ) );
		$invoices  = self::fetch_billed_invoices( $since );
		$processed = 0;
		$deducted  = 0;
		$skipped   = 0;

		$sync_tbl = ZSTOCK_DB::sync_log_table();
		foreach ( (array) $invoices as $invoice ) {
			$inv_id = (string) ( $invoice['id'] ?? $invoice['invoiceid'] ?? '' );
			if ( '' === $inv_id ) {
				continue;
			}
			$already = (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifier from constant.
					"SELECT COUNT(*) FROM `{$sync_tbl}` WHERE source_invoice_id = %s",
					$inv_id
				)
			);
			if ( $already ) {
				$skipped++;
				continue;
			}
			$consumption = self::calculate_consumption( (array) $invoice );
			foreach ( $consumption as $entry ) {
				self::add_ledger_entry(
					$entry['item_id'],
					-1 * (float) $entry['quantity'],
					'JOB_CONSUMPTION',
					'invoice',
					$inv_id,
					'Job invoice #' . $inv_id,
					'From: ' . $entry['source_line']
				);
				$deducted++;
			}
			$wpdb->insert(
				$sync_tbl,
				array(
					'source_invoice_id' => $inv_id,
					'invoice_date'      => (string) ( $invoice['create_date'] ?? $invoice['invoice_date'] ?? '' ),
					'items_count'       => count( $consumption ),
					'synced_at'         => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%d', '%s' )
			);
			$processed++;
		}
		update_option( 'zstock_last_sync', current_time( 'mysql' ), false );
		zstock_log( "consumption sweep: {$processed} invoices, {$deducted} deductions, {$skipped} skipped" );
		return array(
			'invoices_processed' => $processed,
			'items_consumed'     => $deducted,
			'invoices_skipped'   => $skipped,
			'sync_since'         => $since,
		);
	}

	/**
	 * Fetch billed invoices from the configured source. Default: ZDZ_Core_FreshBooks (a bound
	 * provider). Override the whole source via `zstock_consumption_invoices`.
	 *
	 * @param string $since YYYY-MM-DD
	 * @return array invoices (each with a `lines` array)
	 */
	private static function fetch_billed_invoices( $since ) {
		$pre = apply_filters( 'zstock_consumption_invoices', null, $since );
		if ( is_array( $pre ) ) {
			return $pre;
		}
		if ( ! class_exists( 'ZDZ_Core_FreshBooks' ) ) {
			zstock_log( 'consumption sweep: no billing provider available (ZDZ_Core_FreshBooks absent) — nothing fetched' );
			return array();
		}
		try {
			$fb = new ZDZ_Core_FreshBooks();
			if ( method_exists( $fb, 'is_configured' ) && ! $fb->is_configured() ) {
				zstock_log( 'consumption sweep: FreshBooks not configured — nothing fetched' );
				return array();
			}
			$result = $fb->get_invoices(
				array(
					'search[date_min]' => $since,
					'include[]'        => 'lines',
					'per_page'         => 100,
				)
			);
			return is_array( $result ) ? $result : array();
		} catch ( \Throwable $e ) {
			zstock_log( 'consumption sweep: provider error — ' . $e->getMessage() );
			return array();
		}
	}

	/* ================================================================
	 * AI: invoice parsing + inventory brain (shared ZDZ_Core_Poe)
	 * ================================================================ */

	/** Build a Poe client from the shared credentials, or null if no key is configured. */
	private static function poe() {
		if ( ! class_exists( 'ZDZ_Core_Poe' ) ) {
			return null;
		}
		$key = class_exists( 'ZDZ_Core_Settings' ) ? ZDZ_Core_Settings::get_poe_api_key() : '';
		if ( '' === $key ) {
			return null;
		}
		// Always pass an explicit model so the client never has to resolve a default itself.
		return new ZDZ_Core_Poe( $key, zstock_default_model() );
	}

	/**
	 * Parse a supplier-invoice image into structured line items. The prompt is assembled at
	 * runtime with the Business Profile descriptor — no company/industry is typed in.
	 *
	 * @param string $file_url
	 * @return array parsed data or [ 'error' => ... ]
	 */
	public static function parse_supplier_invoice( $file_url ) {
		$poe = self::poe();
		if ( ! $poe ) {
			return array( 'error' => __( 'AI client not configured. Add a Poe API key in Zorderz settings.', 'zorderz' ) );
		}
		$messages = array(
			array( 'role' => 'system', 'content' => self::invoice_parse_prompt() ),
			array(
				'role'    => 'user',
				'content' => array(
					array( 'type' => 'text', 'text' => 'Parse this supplier invoice. Extract all line items, quantities, unit prices, and totals. Return structured JSON.' ),
					array( 'type' => 'image_url', 'image_url' => array( 'url' => $file_url ) ),
				),
			),
		);
		$response = $poe->query( $messages, 0.0, array( 'thinking_budget' => 8192 ), zstock_default_model() );
		if ( 0 === strpos( $response, 'Error' ) ) {
			zstock_log( 'invoice parse error: ' . substr( $response, 0, 300 ) );
			return array( 'error' => __( 'AI parsing failed.', 'zorderz' ) );
		}
		$parsed = $poe->parse_llm_json( $response );
		if ( ! $parsed ) {
			return array( 'error' => __( 'Could not extract structured data from the invoice.', 'zorderz' ) );
		}
		return array(
			'supplier_name'  => sanitize_text_field( $parsed['supplier_name'] ?? $parsed['supplier'] ?? '' ),
			'invoice_number' => sanitize_text_field( $parsed['invoice_number'] ?? '' ),
			'invoice_date'   => sanitize_text_field( $parsed['invoice_date'] ?? $parsed['date'] ?? '' ),
			'line_items'     => self::sanitize_parsed_items( $parsed['line_items'] ?? $parsed['items'] ?? array() ),
			'subtotal'       => (float) ( $parsed['subtotal'] ?? 0 ),
			'tax'            => (float) ( $parsed['tax'] ?? 0 ),
			'total'          => (float) ( $parsed['total'] ?? 0 ),
		);
	}

	private static function sanitize_parsed_items( $items ) {
		if ( ! is_array( $items ) ) {
			return array();
		}
		$out = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$out[] = array(
				'sku'         => sanitize_text_field( $item['sku'] ?? '' ),
				'description' => sanitize_text_field( $item['description'] ?? '' ),
				'quantity'    => (float) ( $item['quantity'] ?? 0 ),
				'unit'        => sanitize_text_field( $item['unit'] ?? 'each' ),
				'unit_price'  => (float) ( $item['unit_price'] ?? 0 ),
				'total'       => (float) ( $item['total'] ?? 0 ),
			);
		}
		return $out;
	}

	/** The invoice-parse system prompt — generic, business-named at runtime, no product names. */
	private static function invoice_parse_prompt() {
		$who = zstock_business_descriptor();
		return "You are an invoice-parsing assistant for {$who}.\n\n"
			. "You will receive an image of a supplier invoice (a materials purchase). Extract every line into structured JSON.\n\n"
			. "Return ONLY a JSON code block of the form:\n"
			. "```json\n{\n  \"supplier_name\": \"\",\n  \"invoice_number\": \"\",\n  \"invoice_date\": \"YYYY-MM-DD\",\n"
			. "  \"line_items\": [ { \"sku\": \"\", \"description\": \"\", \"quantity\": 0, \"unit\": \"each\", \"unit_price\": 0, \"total\": 0 } ],\n"
			. "  \"subtotal\": 0, \"tax\": 0, \"total\": 0\n}\n```\n\n"
			. "Rules:\n"
			. "1. Extract EVERY line item — do not skip any.\n"
			. "2. Include a SKU when visible, otherwise \"\".\n"
			. "3. Quantities and prices must be numeric (decimal, the site currency assumed).\n"
			. "4. Unit should be one of: each, box, roll, ft, sqft, lb, gal — or the unit shown.\n"
			. "5. If the invoice is partly illegible, include what you can read.\n"
			. "6. Do NOT include shipping/freight as a line item.\n";
	}

	/** Match parsed supplier lines to catalog items (matched + unmatched). */
	public static function match_line_items( array $parsed_items ) {
		$matched   = array();
		$unmatched = array();
		foreach ( $parsed_items as $parsed ) {
			$id = ZSTOCK_Catalog::match_supplier_line( (array) $parsed );
			if ( '' !== $id ) {
				$item      = ZSTOCK_Catalog::view( $id );
				$matched[] = array_merge(
					(array) $parsed,
					array(
						'item_id'      => $id,
						'matched_name' => $item['name'] ?? $id,
					)
				);
			} else {
				$unmatched[] = (array) $parsed;
			}
		}
		return array( 'matched' => $matched, 'unmatched' => $unmatched );
	}

	/**
	 * Ask the inventory brain a question. The "built-in product catalog knowledge" is no longer an
	 * off-repo bot's baked list — it is the in-repo neutral template (defaults/brain-prompt.md)
	 * assembled at runtime with the live Item Engine catalog snapshot and sent through ZDZ_Core_Poe
	 * using the configured bot name (blank ⇒ the platform default model).
	 *
	 * @param string $query
	 * @param array  $context  Inventory data to ground the answer.
	 * @return string
	 */
	public static function query_brain( $query, array $context = array() ) {
		$poe = self::poe();
		if ( ! $poe ) {
			return __( 'AI client not configured. Add a Poe API key in Zorderz settings.', 'zorderz' );
		}
		$messages = array( array( 'role' => 'system', 'content' => self::brain_system_prompt() ) );
		if ( ! empty( $context ) ) {
			$messages[] = array(
				'role'    => 'system',
				'content' => "INVENTORY DATA (from this business's stock records):\n\n" . wp_json_encode( $context, JSON_PRETTY_PRINT ),
			);
		}
		$messages[] = array( 'role' => 'user', 'content' => (string) $query );

		$response = $poe->query( $messages, 0.2, array(), zstock_brain_bot() );

		// Strip the answer sentinel (current constant + the deprecated legacy token).
		foreach ( array( ZSTOCK_BRAIN_SENTINEL, ZSTOCK_BRAIN_SENTINEL_LEGACY ) as $sentinel ) {
			$pos = strpos( $response, $sentinel );
			if ( false !== $pos ) {
				$response = trim( substr( $response, $pos + strlen( $sentinel ) ) );
				break;
			}
		}
		return $response;
	}

	/**
	 * Assemble the brain system prompt from the in-repo template + Business Profile + a compact
	 * live catalog snapshot. Placeholders: {{business}}, {{catalog}}, {{sentinel}}.
	 */
	private static function brain_system_prompt() {
		$template = @file_get_contents( ZSTOCK_DIR . 'defaults/brain-prompt.md' );
		if ( ! is_string( $template ) || '' === trim( $template ) ) {
			$template = "You are an inventory-intelligence assistant for {{business}}.\n"
				. "Use only the catalog and inventory data provided; never invent products, SKUs or prices.\n"
				. "When you are confident of a final answer, prefix it with {{sentinel}}.\n\n"
				. "CATALOG:\n{{catalog}}\n";
		}
		return strtr(
			$template,
			array(
				'{{business}}' => zstock_business_descriptor(),
				'{{catalog}}'  => self::catalog_snapshot(),
				'{{sentinel}}' => ZSTOCK_BRAIN_SENTINEL,
			)
		);
	}

	/** A compact text snapshot of the live catalog for prompt grounding. Empty ⇒ a neutral note. */
	private static function catalog_snapshot() {
		$lines = array();
		foreach ( self::items() as $it ) {
			$sku    = '' !== $it['sku'] ? " [{$it['sku']}]" : '';
			$lines[] = "- {$it['name']}{$sku} ({$it['category']}), unit: {$it['unit']}";
			if ( count( $lines ) >= 200 ) {
				break;
			}
		}
		return $lines ? implode( "\n", $lines ) : '(no catalog items defined yet)';
	}
}
