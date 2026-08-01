<?php
/**
 * ZL Dashboard — Admin dashboard page + AJAX handlers for lead generation.
 *
 * ARCHITECTURE ROLE:
 * This file handles the WordPress admin interface and orchestrates the 8-step AJAX pipeline
 * for generating sales leads. It acts as the controller between the frontend UI (dashboard.js)
 * and the core engine (ZL_Lead_Generator).
 *
 * BUSINESS CONTEXT:
 * Built for the business. Connects FreshBooks (invoices) to Nutshell CRM (leads).
 * Supports strict territory assignments (each salesperson owns one or more territory codes).
 * Uses Gemini-3.1-Pro (via Poe API) for 3-layer AI product filtering and description refinement.
 *
 * DATABASE USAGE:
 * - Reads/Writes: wp_zl_batches (Stores generation runs)
 * - Reads/Writes: wp_zl_leads (Stores individual generated leads)
 * - Transients: Used heavily to pass state between AJAX steps (ZL_TRANSIENT_TTL).
 *   Large transients (_customers, _candidates) use gzip compression to avoid
 *   MySQL max_allowed_packet overflow on 15-year lookbacks (10,000+ invoices).
 *
 * Multi-step AJAX flow:
 *   1. zl_start_batch      — Create batch record & store options in transient.
 *   2. zl_fetch_invoices   — Fetch FreshBooks paid invoices, group by customer.
 *   2b. zl_expand_filter   — AI product filter expansion (v1.2.1 502 fix).
 *   3. zl_enrich_chunk     — Enrich a chunk of customers (FB client + Nutshell + cooldown).
 *   4. zl_select_leads     — Filter by territory, score, select top N, save to DB.
 *   4.5 zl_ai_validate     — AI strict validation against product filter.
 *   5. zl_ai_refine        — AI-refine purchase descriptions (<101 chars for Nutshell).
 *   6. zl_create_nutshell  — Create Nutshell leads (skipped for test mode).
 *   7. zl_finalize         — AI batch summary, mark batch complete.
 *
 *   Widget endpoints (v1.5.0):
 *   - zl_widget_batches    — Paginated batch history for full-parity widget.
 *   - zl_save_permissions  — Save permission config (admin-only).
 *   - zl_get_permissions   — Get permission config (admin-only).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZL_Dashboard {

	/**
	 * Constructor.
	 * Registers the admin menu, all AJAX endpoints for the generation pipeline,
	 * lead management endpoints, and enqueues necessary assets.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_dashboard_page' ) );

		// Generation AJAX (The 8-step pipeline)
		add_action( 'wp_ajax_zl_start_batch',     array( $this, 'ajax_start_batch' ) );
		add_action( 'wp_ajax_zl_fetch_invoices',   array( $this, 'ajax_fetch_invoices' ) );
		add_action( 'wp_ajax_zl_expand_filter',    array( $this, 'ajax_expand_filter' ) );
		add_action( 'wp_ajax_zl_enrich_chunk',     array( $this, 'ajax_enrich_chunk' ) );
		add_action( 'wp_ajax_zl_select_leads',     array( $this, 'ajax_select_leads' ) );
		add_action( 'wp_ajax_zl_ai_validate',      array( $this, 'ajax_ai_validate' ) );
		add_action( 'wp_ajax_zl_ai_refine',        array( $this, 'ajax_ai_refine' ) );
		add_action( 'wp_ajax_zl_create_nutshell',  array( $this, 'ajax_create_nutshell' ) );
		add_action( 'wp_ajax_zl_finalize',         array( $this, 'ajax_finalize' ) );

		// Lead management AJAX (UI interactions)
		add_action( 'wp_ajax_zl_get_batch_leads',           array( $this, 'ajax_get_batch_leads' ) );
		add_action( 'wp_ajax_zl_update_contact_status',      array( $this, 'ajax_update_contact_status' ) );
		add_action( 'wp_ajax_zl_delete_batch',               array( $this, 'ajax_delete_batch' ) );
		add_action( 'wp_ajax_zl_send_test_to_nutshell',      array( $this, 'ajax_send_test_to_nutshell' ) );

		// v2.4.0 — Per-user lead assignment (Phase 1)
		add_action( 'wp_ajax_zl_assign_leads',               array( $this, 'ajax_assign_leads' ) );
		add_action( 'wp_ajax_zl_get_assignable_users',       array( $this, 'ajax_get_assignable_users' ) );
		add_action( 'wp_ajax_zl_my_leads_count',             array( $this, 'ajax_my_leads_count' ) );
		// v2.5.0 — Rep-mode assigned-leads list (Phase 2)
		add_action( 'wp_ajax_zl_my_leads',                   array( $this, 'ajax_my_leads' ) );
		
		// Nutshell Sync AJAX
		add_action( 'wp_ajax_zl_sync_nutshell',              array( $this, 'ajax_sync_nutshell' ) );

		// Widget summary AJAX (for inline dashboard widget)
		add_action( 'wp_ajax_zl_widget_summary',             array( $this, 'ajax_widget_summary' ) );

		// v1.7.0 — Background generation (flush-and-continue)
		add_action( 'wp_ajax_zl_start_background',    array( $this, 'ajax_start_background' ) );
		// v2.0.0 — Cron relay enrichment (self-spawning loopback chain)
		add_action( 'wp_ajax_nopriv_zl_bg_enrich_chunk', array( $this, 'ajax_bg_enrich_chunk' ) );
		add_action( 'wp_ajax_zl_bg_enrich_chunk',        array( $this, 'ajax_bg_enrich_chunk' ) );
		add_action( 'wp_ajax_nopriv_zl_bg_finalize',     array( $this, 'ajax_bg_finalize' ) );
		add_action( 'wp_ajax_zl_bg_finalize',            array( $this, 'ajax_bg_finalize' ) );
		add_action( 'wp_ajax_zl_poll_batch_progress',  array( $this, 'ajax_poll_batch_progress' ) );

		// v1.8.0 — Speed release: structured progress (heartbeat + stage + warnings)
		add_action( 'wp_ajax_zlg_get_batch_progress', array( $this, 'ajax_zlg_get_batch_progress' ) );

		// Widget full-parity AJAX (v1.5.0)
		add_action( 'wp_ajax_zl_widget_batches',     array( $this, 'ajax_widget_batches' ) );
		add_action( 'wp_ajax_zl_save_permissions',    array( $this, 'ajax_save_permissions' ) );
		add_action( 'wp_ajax_zl_get_permissions',     array( $this, 'ajax_get_permissions' ) );

		// Enqueue assets
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	// ── Menu ──────────────────────────────────────────────────────────

	/**
	 * Registers the dashboard page under the main ZL Settings menu.
	 * Uses 'read' capability so Zorderz custom roles (ts_admin, ts_operator)
	 * can access the dashboard via the SPA inline widget or iframe bridge.
	 * Settings page remains 'manage_options' (admin-only).
	 */
	public function add_dashboard_page() {
		add_submenu_page(
			'zl-settings',
			'Lead Dashboard',
			'Lead Dashboard',
			'read',
			'zl-dashboard',
			array( $this, 'render_dashboard' )
		);
	}

	// ── Enqueue ───────────────────────────────────────────────────────

	/**
	 * Enqueues CSS and JS specifically for the dashboard page.
	 * Localizes the AJAX URL and security nonce for frontend usage.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( strpos( $hook, 'zl-dashboard' ) === false ) {
			return;
		}
		wp_enqueue_style( 'zl-dashboard-css', ZL_PLUGIN_URL . 'assets/css/dashboard.css', array(), ZL_VERSION );
		wp_enqueue_script( 'zl-dashboard-js', ZL_PLUGIN_URL . 'assets/js/dashboard.js', array( 'jquery' ), ZL_VERSION, true );
		wp_localize_script( 'zl-dashboard-js', 'tslData', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'zl_nonce' ),
		) );
	}

	// ── Dashboard HTML ────────────────────────────────────────────────

	/**
	 * Renders the main HTML for the Lead Dashboard.
	 * Displays generation controls (salesperson, filters, lookback) and the batch history table.
	 */
	public function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'zdz_access_app' ) ) {
			return;
		}

		// Detect if loaded inside Zorderz SPA iframe (bridge appends ?zdz_mobile=1)
		$is_mobile = isset( $_GET['zdz_mobile'] ) && $_GET['zdz_mobile'] === '1';

		if ( $is_mobile ) {
			echo '<style>
				#wpadminbar, #adminmenuwrap, #adminmenuback,
				#wpfooter, .update-nag, .notice { display: none !important; }
				#wpcontent { margin-left: 0 !important; padding-top: 0 !important; }
				html { margin-top: 0 !important; }
			</style>';
		}

		global $wpdb;

		// Fetch configured salespeople or fallback to defaults
		$salespeople = json_decode( get_option( 'zl_salespeople', '[]' ), true );
		if ( ! is_array( $salespeople ) || empty( $salespeople ) ) {
			$salespeople = zl_salespeople();
		}

		// Extended lookback options (up to ~26 years for deep historical mining)
		$lookback_options = array(
			180  => '6 Months',
			365  => '1 Year',
			730  => '2 Years',
			1095 => '3 Years',
			1825 => '5 Years',
			3650 => '10 Years',
			5475 => '15 Years',
			9500 => 'Since 2000 (~26 years)',
		);

		// Get up to 50 recent batches and their lead counts
		$batches = $wpdb->get_results(
			"SELECT b.*,
			        (SELECT COUNT(*) FROM {$wpdb->prefix}zl_leads l WHERE l.batch_id = b.id) AS lead_count,
			        (SELECT COUNT(*) FROM {$wpdb->prefix}zl_leads l WHERE l.batch_id = b.id AND l.contact_status = 'contacted') AS contacted_count
			 FROM {$wpdb->prefix}zl_batches b
			 ORDER BY b.created_at DESC
			 LIMIT 50",
			ARRAY_A
		);
		?>
		<div class="wrap zl-dashboard-wrap">
			<h1>Leads — Dashboard</h1>
			<p class="description">v<?php echo esc_html( ZL_VERSION ); ?></p>

			<!-- ── Generation Panel ─────────────────────────────── -->
			<div class="zl-gen-panel">
				<h2>Generate Leads</h2>
				<div class="zl-gen-controls">
					<div class="zl-gen-row">
						<label for="zl-salesperson"><strong>Salesperson:</strong></label>
						<select id="zl-salesperson">
							<?php foreach ( $salespeople as $sp ) : ?>
								<option value="<?php echo esc_attr( $sp['code'] ); ?>">
									<?php echo esc_html( $sp['name'] . ' (' . $sp['code'] . ')' ); ?>
								</option>
							<?php endforeach; ?>
						</select>

						<label for="zl-lookback"><strong>Lookback:</strong></label>
						<select id="zl-lookback">
							<?php foreach ( $lookback_options as $days => $label ) : ?>
								<option value="<?php echo esc_attr( $days ); ?>"<?php selected( $days, 730 ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="zl-gen-row">
						<label for="zl-product-filter"><strong>Product Filter:</strong></label>
						<input type="text" id="zl-product-filter" placeholder="e.g. a product or service keyword (leave blank for all)" class="zl-wide-input" />
					</div>

					<div class="zl-gen-row">
						<label for="zl-city-zip">Cities / Zip Codes:</label>
						<input type="text" id="zl-city-zip" class="zl-wide-input" placeholder="e.g. city or ZIP" />
						<span class="zl-filter-hint">Comma-separated cities or zip codes to restrict results</span>
					</div>

					<div class="zl-gen-row">
						<label>Spend Range:</label>
						<span style="white-space:nowrap;">$<input type="number" id="zl-spend-min" min="0" step="50" value="" style="width:100px;" placeholder="Min" /></span>
						<span>to</span>
						<span style="white-space:nowrap;">$<input type="number" id="zl-spend-max" min="0" step="50" value="" style="width:100px;" placeholder="Max" /></span>
						<span class="zl-filter-hint">Filter by total invoice amount (leave blank for no limit)</span>
					</div>

					<div class="zl-gen-row">
						<label for="zl-demographic">Customer Demographic:</label>
						<select id="zl-demographic">
							<option value="both" selected>Both (no preference)</option>
							<option value="male">Male</option>
							<option value="female">Female</option>
						</select>
						<span class="zl-filter-hint">Filter by likely customer gender (uses AI inference from names)</span>
					</div>

					<div class="zl-gen-row zl-gen-buttons">
						<button type="button" id="zl-btn-test" class="button button-secondary">
							🧪 Generate 3 Test Leads
						</button>
						<button type="button" id="zl-btn-full" class="button button-primary">
							🚀 Generate Full Batch
						</button>
						<span class="zl-filter-hint">💡 Product filter narrows results to customers who bought matching products</span>
					</div>
				</div>

				<div id="zl-progress" style="display:none;">
					<div class="zl-progress-bar-wrap">
						<div class="zl-progress-bar" id="zl-progress-bar"></div>
					</div>
					<div id="zl-progress-log"></div>
				</div>
			</div>

			<!-- ── Sync Panel ────────────────────────────────── -->
			<div class="zl-sync-panel" style="margin-bottom: 20px;">
				<button type="button" id="zl-btn-sync-nutshell" class="button button-secondary">
					🔄 Sync with Nutshell
				</button>
				<span id="zl-sync-status" style="margin-left: 10px; font-weight: bold;"></span>
			</div>

			<!-- ── Batch History ────────────────────────────────── -->
			<div class="zl-batches-panel">
				<h2>Batch History</h2>
				<?php if ( empty( $batches ) ) : ?>
					<p class="zl-empty-state">No batches generated yet. Click a generation button above to get started.</p>
				<?php else : ?>
					<table class="widefat zl-batches-table" id="zl-batches-table">
						<thead>
							<tr>
								<th></th>
								<th>Batch</th>
								<th>Assigned To</th>
								<th>Leads</th>
								<th>Contacted</th>
								<th>Status</th>
								<th>Created</th>
								<th>Actions</th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $batches as $b ) :
							$sp_label = $b['assigned_to'] ?: '—';
							foreach ( $salespeople as $sp ) {
								if ( $sp['code'] === $b['assigned_to'] ) {
									$sp_label = $sp['name'] . ' (' . $sp['code'] . ')';
									break;
								}
							}
							$status_class = 'zl-status-' . sanitize_html_class( $b['status'] );
							$is_test      = (int) $b['is_test'];

							// Retrieve batch options for filter display
							// Transients might expire, so we fallback to DB columns if needed
							$batch_opts        = get_transient( "zl_batch_{$b['id']}_options" ) ?: array();
							$b_city_zip        = isset( $batch_opts['city_zip_filter'] ) ? $batch_opts['city_zip_filter'] : '';
							$b_spend_min       = isset( $batch_opts['spend_min'] ) ? (float) $batch_opts['spend_min'] : 0;
							$b_spend_max       = isset( $batch_opts['spend_max'] ) ? (float) $batch_opts['spend_max'] : 0;
							$b_demographic     = isset( $batch_opts['demographic_filter'] ) ? $batch_opts['demographic_filter'] : 'both';

							// Also check DB columns if transient expired
							if ( empty( $b_city_zip ) && ! empty( $b['city_zip_filter'] ) ) {
								$b_city_zip = $b['city_zip_filter'];
							}
							if ( $b_spend_min <= 0 && ! empty( $b['spend_min'] ) ) {
								$b_spend_min = (float) $b['spend_min'];
							}
							if ( $b_spend_max <= 0 && ! empty( $b['spend_max'] ) ) {
								$b_spend_max = (float) $b['spend_max'];
							}
							if ( $b_demographic === 'both' && ! empty( $b['demographic_filter'] ) && $b['demographic_filter'] !== 'both' ) {
								$b_demographic = $b['demographic_filter'];
							}
							?>
							<tr class="zl-batch-row" data-batch-id="<?php echo (int) $b['id']; ?>">
								<td class="zl-toggle-cell">
									<button type="button" class="zl-toggle-btn" data-batch-id="<?php echo (int) $b['id']; ?>" title="Expand leads">▶</button>
								</td>
								<td>
									<?php echo esc_html( $b['batch_tag'] ); ?>
									<?php if ( $is_test ) : ?><span class="zl-badge-test">TEST</span><?php endif; ?>
									<?php if ( ! empty( $b['product_filter'] ) ) : ?>
										<br><small>Filter: <?php echo esc_html( $b['product_filter'] ); ?></small>
									<?php endif; ?>
									<?php if ( ! empty( $b_city_zip ) ) : ?>
										<br><small>City/Zip: <?php echo esc_html( $b_city_zip ); ?></small>
									<?php endif; ?>
									<?php if ( $b_spend_min > 0 || $b_spend_max > 0 ) : ?>
										<br><small>Spend: $<?php echo esc_html( number_format( $b_spend_min, 0 ) ); ?><?php if ( $b_spend_max > 0 ) : ?> – $<?php echo esc_html( number_format( $b_spend_max, 0 ) ); ?><?php else : ?>+<?php endif; ?></small>
									<?php endif; ?>
									<?php if ( ! empty( $b_demographic ) && $b_demographic !== 'both' ) : ?>
										<br><small>Demographic: <?php echo esc_html( ucfirst( $b_demographic ) ); ?></small>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $sp_label ); ?></td>
								<td><?php echo (int) $b['lead_count']; ?></td>
								<td><?php echo (int) $b['contacted_count']; ?> / <?php echo (int) $b['lead_count']; ?></td>
								<td><span class="zl-status <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( ucfirst( $b['status'] ) ); ?></span></td>
								<td><?php echo esc_html( date( 'M j, Y g:ia', strtotime( $b['created_at'] ) ) ); ?></td>
								<td>
									<?php if ( $is_test ) : ?>
										<button type="button" class="button button-small button-primary zl-send-to-nutshell" data-batch-id="<?php echo (int) $b['id']; ?>">📤 Send to Nutshell</button>
									<?php endif; ?>
									<button type="button" class="button button-small zl-delete-batch" data-batch-id="<?php echo (int) $b['id']; ?>" data-lead-count="<?php echo (int) $b['lead_count']; ?>" data-batch-tag="<?php echo esc_attr( $b['batch_tag'] ); ?>">🗑️ Delete</button>
								</td>
							</tr>
							<tr class="zl-leads-row" id="zl-leads-<?php echo (int) $b['id']; ?>" style="display:none;">
								<td colspan="8">
									<div class="zl-leads-container" id="zl-leads-container-<?php echo (int) $b['id']; ?>">
										<?php if ( ! empty( $b['ai_summary'] ) ) : ?>
											<div class="zl-ai-summary">
												<strong>AI Summary:</strong> <?php echo esc_html( $b['ai_summary'] ); ?>
											</div>
										<?php endif; ?>
										<div class="zl-leads-loading">Loading leads...</div>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	// ═══════════════════════════════════════════════════════════════════
	// AJAX — Generation steps
	// ═══════════════════════════════════════════════════════════════════

	/**
	 * Step 1: Create batch record.
	 * Initializes the database record for the new batch and stores generation
	 * options in a transient to be used by subsequent AJAX steps.
	 */
	public function ajax_start_batch() {
		check_ajax_referer( 'zl_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'zdz_access_app' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		// v1.6.0 — Rule 5: Transient-based session lock.
		// Prevents duplicate batches from double-clicks or concurrent tabs.
		$user_id  = get_current_user_id();
		$lock_key = 'zl_batch_lock_' . $user_id;
		if ( get_transient( $lock_key ) ) {
			wp_send_json_error( 'A batch is already in progress. Please wait for it to complete.' );
		}
		set_transient( $lock_key, time(), 600 ); // 10 min TTL

		$sp_code            = sanitize_text_field( $_POST['salesperson'] ?? '' );
		$is_test            = (int) ( $_POST['is_test'] ?? 0 );
		$lookback_days      = (int) ( $_POST['lookback_days'] ?? get_option( 'zl_lookback_days', 730 ) );
		$product_filter     = sanitize_text_field( $_POST['product_filter'] ?? '' );
		$city_zip_filter    = sanitize_text_field( $_POST['city_zip_filter'] ?? '' );
		$demographic_filter = sanitize_text_field( $_POST['demographic_filter'] ?? 'both' );
		$spend_min          = floatval( $_POST['spend_min'] ?? 0 );
		$spend_max          = floatval( $_POST['spend_max'] ?? 0 );

		// v2.0.0 FIX: Handle "All Salespeople" and auto-resolve for sales users.
		// Previously crashed because empty $sp_code failed validation, and the
		// downstream territory-matching logic in ajax_enrich_chunk() also required
		// a non-empty code. Now: admins can run cross-territory batches with '_ALL_',
		// and sales users auto-resolve to their own territory code.
		if ( empty( $sp_code ) || $sp_code === 'all' || $sp_code === '_ALL_' ) {
			if ( current_user_can( 'manage_options' )
				|| in_array( 'zdz_owner', (array) wp_get_current_user()->roles, true )
				|| in_array( 'zdz_admin', (array) wp_get_current_user()->roles, true ) ) {
				// Admin/owner can run "all territories" batch
				$sp_code = '_ALL_';
			} else {
				// Sales user — auto-resolve from their username/meta
				$resolved = '';
				if ( class_exists( 'ZL_Lead_Interaction' ) ) {
					$resolved = ZL_Lead_Interaction::resolve_salesperson_code( $user_id );
				}
				if ( ! empty( $resolved ) ) {
					$sp_code = $resolved;
				} else {
					delete_transient( $lock_key );
					wp_send_json_error( 'Could not determine your territory. Please contact an admin to set your salesperson code.' );
				}
			}
		}

		global $wpdb;
		// Generate a unique batch tag
		$tag = ( $is_test ? 'TEST-' : '' ) . strtoupper( $sp_code ) . '-' . gmdate( 'Ymd-His' );
		if ( ! empty( $product_filter ) ) {
			$tag .= '-FILTER';
		}

		$wpdb->insert(
			$wpdb->prefix . 'zl_batches',
			array(
				'batch_tag'          => $tag,
				'status'             => 'generating',
				'is_test'            => $is_test,
				'assigned_to'        => $sp_code,
				'lookback_days'      => $lookback_days,
				'product_filter'     => $product_filter,
				'city_zip_filter'    => $city_zip_filter,
				'demographic_filter' => $demographic_filter,
				'spend_min'          => $spend_min,
				'spend_max'          => $spend_max,
			),
			array( '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%f', '%f' )
		);

		$batch_id = $wpdb->insert_id;

		// Store generation options in transient for subsequent steps (15 min TTL)
		set_transient( "zl_batch_{$batch_id}_options", array(
			'lookback_days'      => $lookback_days,
			'product_filter'     => $product_filter,
			'is_test'            => $is_test,
			'city_zip_filter'    => $city_zip_filter,
			'demographic_filter' => $demographic_filter,
			'spend_min'          => $spend_min,
			'spend_max'          => $spend_max,
		), ZL_TRANSIENT_TTL );

		// v1.6.0 — Audit log: batch started
		if ( class_exists( 'ZDZ_Admin_Dashboard' ) && method_exists( 'ZDZ_Admin_Dashboard', 'log_action' ) ) {
			$mode = $is_test ? 'test' : 'full';
			ZDZ_Admin_Dashboard::log_action( 'lead_generator', "Batch started: {$tag} ({$mode}, salesperson={$sp_code})" );
		}

		// v1.8.0 — Initialise structured progress for this batch.
		// Frontend begins polling zlg_get_batch_progress immediately after
		// this response. If we don't start a record here the poller will
		// briefly see "No record" and render the optimistic idle state.
		if ( class_exists( 'ZL_Progress' ) ) {
			ZL_Progress::start( $batch_id, 'Batch started — preparing…' );
		}

		wp_send_json_success( array(
			'batch_id'  => $batch_id,
			'batch_tag' => $tag,
		) );
	}

	/**
	 * Step 2: Fetch invoices from FreshBooks — ONE PAGE at a time.
	 *
	 * v1.2.3: Paginated fetch — each AJAX call fetches a single page of 100 invoices
	 * from FreshBooks (~2-3s per call). JavaScript loops until all pages are fetched.
	 * This prevents web server proxy timeouts (502 Bad Gateway) that occurred when
	 * fetching 15+ years of invoices in a single request.
	 *
	 * v1.2.1: AI filter expansion was split into a separate AJAX step (ajax_expand_filter).
	 *
	 * Accepts POST param 'page' (default 1). On page 1, clears previous data.
	 * Each page's invoices are grouped by customer and merged into the transient.
	 * When the last page is reached, returns done=true with final counts.
	 */
	public function ajax_fetch_invoices() {
		@set_time_limit( 300 );
		check_ajax_referer( 'zl_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'zdz_access_app' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		// v2.17.0 (5C): Theme-level revenue gate — invoice data contains dollar amounts.
		if ( class_exists( 'TS_Data_Permissions' ) && ! TS_Data_Permissions::can( get_current_user_id(), 'view_company_revenue' ) ) {
			wp_send_json_error( 'Revenue data access restricted.' );
		}

		$batch_id = (int) ( $_POST['batch_id'] ?? 0 );
		if ( ! $batch_id ) {
			wp_send_json_error( 'Missing batch_id' );
		}

		$page = (int) ( $_POST['page'] ?? 1 );
		if ( $page < 1 ) {
			$page = 1;
		}

		try {
			$gen = new ZL_Lead_Generator();
			$gen->init_clients();

			$options        = get_transient( "zl_batch_{$batch_id}_options" ) ?: array();
			$lookback       = isset( $options['lookback_days'] ) ? (int) $options['lookback_days'] : 730;
			$product_filter = isset( $options['product_filter'] ) ? $options['product_filter'] : '';

			// v1.5.3 — Check if a previous page determined include[]=lines fails
			$skip_lines = ! empty( $options['_fb_no_lines'] );

			// Fetch a single page of invoices from FreshBooks
			$result = $gen->fetch_invoices_page( $lookback, $page, 100, $skip_lines );

			// v1.5.3 — If this page triggered the fallback, persist for subsequent pages
			if ( ! empty( $result['_fallback_used'] ) && empty( $options['_fb_no_lines'] ) ) {
				$options['_fb_no_lines'] = true;
				set_transient( "zl_batch_{$batch_id}_options", $options, ZL_TRANSIENT_TTL );
			}

			// On first page, initialize empty grouped data
			if ( $page === 1 ) {
				$grouped      = array();
				$unique_names = array();
			} else {
				$grouped      = $this->get_compressed_transient( "zl_batch_{$batch_id}_customers" ) ?: array();
				$unique_names = get_transient( "zl_batch_{$batch_id}_unique_names" ) ?: array();
			}

			// Group this page's invoices by customer ID and merge.
			// IMPORTANT: We slim each invoice to only the fields needed by
			// parse_purchase_history() to avoid transient size blowouts.
			// FreshBooks returns many extra fields (payment details, owner info, etc.)
			// that can push serialized data past MySQL's max_allowed_packet when
			// accumulating thousands of invoices across 50+ pages.
			foreach ( $result['invoices'] as $inv ) {
				$cid = isset( $inv['customerid'] ) ? (string) $inv['customerid'] : '';
				if ( empty( $cid ) ) {
					continue;
				}
				if ( ! isset( $grouped[ $cid ] ) ) {
					$grouped[ $cid ] = array();
				}
				// Slim invoice — keep only fields used by parse_purchase_history()
				$slim_lines = array();
				if ( ! empty( $inv['lines'] ) && is_array( $inv['lines'] ) ) {
					foreach ( $inv['lines'] as $line ) {
						$slim_lines[] = array(
							'name'        => isset( $line['name'] )        ? $line['name']        : '',
							'description' => isset( $line['description'] ) ? $line['description'] : '',
							'qty'         => isset( $line['qty'] )         ? $line['qty']         : 1,
							'amount'      => isset( $line['amount'] )      ? $line['amount']      : 0,
						);
						// Track unique line item names for AI filter expansion (v1.2.9).
						// Pre-extracting here avoids decompressing the full customer data
						// later in ajax_expand_filter(), which caused PHP memory exhaustion.
						$ln_name = isset( $line['name'] )        ? trim( $line['name'] )        : '';
						$ln_desc = isset( $line['description'] ) ? trim( $line['description'] ) : '';
						if ( ! empty( $ln_name ) ) {
							$unique_names[ strtolower( $ln_name ) ] = $ln_name;
						}
						if ( ! empty( $ln_desc ) && $ln_desc !== $ln_name ) {
							$unique_names[ strtolower( $ln_desc ) ] = $ln_desc;
						}
					}
				}
				$grouped[ $cid ][] = array(
					'customerid'  => $cid,
					'create_date' => isset( $inv['create_date'] ) ? $inv['create_date'] : '',
					'amount'      => isset( $inv['amount'] )      ? $inv['amount']      : 0,
					'lines'       => $slim_lines,
				);
			}

			// Save accumulated data (compressed to avoid MySQL max_allowed_packet overflow)
			$this->set_compressed_transient( "zl_batch_{$batch_id}_customers", $grouped, ZL_TRANSIENT_TTL );

			// Save unique line item names separately (small data, no compression needed).
			// v1.2.9: ajax_expand_filter reads this instead of decompressing the full
			// customer data — prevents PHP memory exhaustion on 15-year lookbacks.
			set_transient( "zl_batch_{$batch_id}_unique_names", $unique_names, ZL_TRANSIENT_TTL );

			$is_done     = ( $page >= $result['total_pages'] );
			$page_count  = count( $result['invoices'] );
			$total_so_far = array_sum( array_map( 'count', $grouped ) );

			// On final page, also initialize candidates transient
			if ( $is_done ) {
				$this->set_compressed_transient( "zl_batch_{$batch_id}_candidates", array(), ZL_TRANSIENT_TTL );

				// ── v2.0.0: Split monolithic customer data into chunked transients ──
				// The full $grouped array can be 15-30MB for large lookbacks (e.g. "Since 2000"
				// with 3,000+ customers). Loading this on every 25-customer enrichment chunk
				// causes PHP to hit WP Engine's 256MB memory_limit and crash.
				// Fix: split into chunk transients of 200 customers each. The enrichment step
				// loads only the chunk(s) it needs, reducing peak memory from ~30MB to ~3MB.
				$all_customer_ids = array_keys( $grouped );
				$chunk_size       = 200;
				$chunk_count      = (int) ceil( count( $all_customer_ids ) / $chunk_size );

				for ( $ci = 0; $ci < $chunk_count; $ci++ ) {
					$chunk_ids  = array_slice( $all_customer_ids, $ci * $chunk_size, $chunk_size );
					$chunk_data = array();
					foreach ( $chunk_ids as $c_id ) {
						if ( isset( $grouped[ $c_id ] ) ) {
							$chunk_data[ $c_id ] = $grouped[ $c_id ];
						}
					}
					$this->set_compressed_transient(
						"zl_batch_{$batch_id}_cchunk_{$ci}",
						$chunk_data,
						ZL_TRANSIENT_TTL
					);
				}

				// Save a lightweight meta transient with just the customer IDs and chunk info.
				// This is typically <50KB even for 3,000+ customers.
				set_transient( "zl_batch_{$batch_id}_cmeta", array(
					'total'       => count( $all_customer_ids ),
					'chunk_size'  => $chunk_size,
					'chunk_count' => $chunk_count,
					'ids'         => $all_customer_ids,
				), ZL_TRANSIENT_TTL );

				// Keep the monolithic transient as fallback for ajax_expand_filter
				// which reads all unique line item names (already extracted to _unique_names).
				// It will NOT be loaded during enrichment (the chunked path is used instead).
			}

			$ai_model = get_option( 'zl_ai_model', 'Gemini-3.1-Pro' );

			// v1.5.3 — Surface fallback and diagnostic info from FreshBooks
			$fb_fallback = ! empty( $result['_fallback_used'] );
			$fb_diag     = isset( $result['_diag_info'] ) ? $result['_diag_info'] : array();

			wp_send_json_success( array(
				'done'               => $is_done,
				'page'               => $page,
				'total_pages'        => $result['total_pages'],
				'page_invoice_count' => $page_count,
				'invoice_count'      => $total_so_far,
				'customer_count'     => count( $grouped ),
				'has_product_filter' => ! empty( $product_filter ),
				'ai_available'       => $is_done ? $gen->has_ai() : false,
				'ai_model'           => $is_done ? $ai_model : '',
				'fb_fallback'        => $fb_fallback,
				'fb_diag'            => $fb_diag,
			) );
		} catch ( \Throwable $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	/**
	 * Step 2b: AI-expand product filter (separate AJAX call).
	 *
	 * Added in v1.2.1 — Split out of ajax_fetch_invoices to avoid web server
	 * proxy timeouts (502 Bad Gateway). The FreshBooks fetch + AI expansion
	 * combined often exceeded 60s (especially with "Since 2000" lookback),
	 * causing the web server to kill the PHP process.
	 *
	 * Sends all unique line-item names from the already-fetched invoices to
	 * Gemini-3.1-Pro to identify which items match the user's product filter.
	 */
	public function ajax_expand_filter() {
		@set_time_limit( 300 ); // Allow up to 5 min for Gemini AI thinking (thinking_budget=32768)
		check_ajax_referer( 'zl_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'zdz_access_app' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$batch_id = (int) ( $_POST['batch_id'] ?? 0 );
		if ( ! $batch_id ) {
			wp_send_json_error( 'Missing batch_id' );
		}

		try {
			$options        = get_transient( "zl_batch_{$batch_id}_options" ) ?: array();
			$product_filter = isset( $options['product_filter'] ) ? $options['product_filter'] : '';

			if ( empty( $product_filter ) ) {
				wp_send_json_success( array( 'skipped' => true ) );
			}

			// v1.2.9: Load only the lightweight unique names transient (~50 KB)
			// instead of decompressing the full customer data (~4+ MB).
			// The old approach caused PHP memory exhaustion on 15-year lookbacks
			// with 9,000+ customers, crashing the AJAX handler with no HTTP response.
			$unique_names = get_transient( "zl_batch_{$batch_id}_unique_names" );
			if ( ! is_array( $unique_names ) || empty( $unique_names ) ) {
				wp_send_json_error( 'Line item data expired. Please restart generation.' );
			}

			$gen = new ZL_Lead_Generator();
			$gen->init_clients();

			$filter_info = $gen->ai_expand_product_filter_from_names( array_values( $unique_names ), $product_filter );
			set_transient( "zl_batch_{$batch_id}_expanded_filter", $filter_info, ZL_TRANSIENT_TTL );

			wp_send_json_success( array(
				'filter_expanded'      => true,
				'filter_ai_used'       => ! empty( $filter_info['ai_used'] ),
				'filter_matches'       => ! empty( $filter_info['matched_names'] ) ? count( $filter_info['matched_names'] ) : 0,
				'filter_keywords'      => ! empty( $filter_info['keywords'] ) ? implode( ', ', $filter_info['keywords'] ) : '',
				'filter_matched_names' => ! empty( $filter_info['matched_names'] ) ? implode( ' | ', array_slice( $filter_info['matched_names'], 0, 10 ) ) : '',
				'filter_error'         => ! empty( $filter_info['error'] ) ? $filter_info['error'] : '',
			) );
		} catch ( \Throwable $e ) {
			error_log( 'ZL ajax_expand_filter fatal: ' . get_class( $e ) . ': ' . $e->getMessage() );
			wp_send_json_error( get_class( $e ) . ': ' . $e->getMessage() );
		}
	}

	/**
	 * Step 3: Enrich a chunk of customers (10 at a time).
	 * Pulls full client details from FreshBooks, checks Nutshell for existing contacts,
	 * verifies cooldown periods, and applies basic filters.
	 */
	public function ajax_enrich_chunk() {
		@set_time_limit( 300 ); // FreshBooks + Nutshell API calls per chunk
		check_ajax_referer( 'zl_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'zdz_access_app' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$batch_id = (int) ( $_POST['batch_id'] ?? 0 );
		$offset   = (int) ( $_POST['offset'] ?? 0 );
		$chunk    = 25; // v1.2.10: up from 10 — reduces AJAX round-trips by 60%

		// Refresh transient TTLs before reading — prevents expiry during long
		// enrichment runs (9,000+ customers can take 90+ min at ~6s per 10).
		// This updates only the timeout option, not the actual data.
		$this->touch_transient_ttl( "zl_batch_{$batch_id}_options" );
		$this->touch_transient_ttl( "zl_batch_{$batch_id}_expanded_filter" );
		$this->touch_transient_ttl( "zl_batch_{$batch_id}_candidates" );

		// Get batch options (product filter, is_test, new filters)
		$batch_options   = get_transient( "zl_batch_{$batch_id}_options" ) ?: array();
		$product_filter  = isset( $batch_options['product_filter'] ) ? $batch_options['product_filter'] : '';
		$is_test         = isset( $batch_options['is_test'] ) ? (int) $batch_options['is_test'] : 0;
		$expanded_filter = ! empty( $product_filter ) ? ( get_transient( "zl_batch_{$batch_id}_expanded_filter" ) ?: null ) : null;

		// ── v2.0.0: CHUNKED TRANSIENT LOADING ──────────────────────────
		// Instead of loading the monolithic 15-30MB customer transient on
		// every 25-customer AJAX call (which caused PHP to hit memory_limit
		// around customer ~1,000), we now:
		//   1. Load the lightweight _cmeta transient (~50KB) to get IDs and total
		//   2. Calculate which chunk transient(s) contain offset..offset+25
		//   3. Load ONLY those chunk transients (~1.5-3MB each)
		// Peak memory drops from ~30MB to ~3MB.

		$cmeta = get_transient( "zl_batch_{$batch_id}_cmeta" );
		$use_chunked = is_array( $cmeta ) && ! empty( $cmeta['ids'] );

		if ( $use_chunked ) {
			// ── Chunked path (v2.0.0) ──
			$customer_ids = $cmeta['ids'];
			$total        = (int) $cmeta['total'];
			$meta_csz     = (int) $cmeta['chunk_size'];
			$meta_cc      = (int) $cmeta['chunk_count'];

			// Touch TTLs on the chunk transients we'll need
			$first_chunk_idx = (int) floor( $offset / $meta_csz );
			$last_chunk_idx  = (int) floor( min( $offset + $chunk - 1, $total - 1 ) / $meta_csz );
			for ( $tci = $first_chunk_idx; $tci <= $last_chunk_idx && $tci < $meta_cc; $tci++ ) {
				$this->touch_transient_ttl( "zl_batch_{$batch_id}_cchunk_{$tci}" );
			}

			$slice = array_slice( $customer_ids, $offset, $chunk );
			$chunk_invoices = array();

			// Load only the chunk transient(s) that contain our slice
			$loaded_chunks = array();
			foreach ( $slice as $s_cid ) {
				// Find which chunk index this customer lives in
				$global_pos = array_search( $s_cid, $customer_ids, true );
				if ( $global_pos === false ) { continue; }
				$ci = (int) floor( $global_pos / $meta_csz );

				if ( ! isset( $loaded_chunks[ $ci ] ) ) {
					$loaded_chunks[ $ci ] = $this->get_compressed_transient( "zl_batch_{$batch_id}_cchunk_{$ci}" );
					if ( ! is_array( $loaded_chunks[ $ci ] ) ) {
						$loaded_chunks[ $ci ] = array();
					}
				}

				if ( isset( $loaded_chunks[ $ci ][ $s_cid ] ) ) {
					$chunk_invoices[ $s_cid ] = $loaded_chunks[ $ci ][ $s_cid ];
				}
			}
			unset( $loaded_chunks ); // Free chunk data immediately

		} else {
			// ── Fallback: monolithic transient (pre-v2.0.0 batches) ──
			$this->touch_transient_ttl( "zl_batch_{$batch_id}_customers" );

			$grouped = $this->get_compressed_transient( "zl_batch_{$batch_id}_customers" );
			if ( ! is_array( $grouped ) || empty( $grouped ) ) {
				wp_send_json_error( 'Customer data expired. Please restart generation.' );
			}

			$customer_ids = array_keys( $grouped );
			$total        = count( $customer_ids );

			$slice = array_slice( $customer_ids, $offset, $chunk );
			$chunk_invoices = array();
			foreach ( $slice as $cid ) {
				if ( isset( $grouped[ $cid ] ) ) {
					$chunk_invoices[ $cid ] = $grouped[ $cid ];
				}
			}
			unset( $grouped, $customer_ids ); // Free ~15-30MB immediately
		}

		$candidates = $this->get_compressed_transient( "zl_batch_{$batch_id}_candidates" ) ?: array();

		// ── Look up salesperson territory codes for early filtering ──
		// By filtering territory during enrichment (not just selection), we avoid
		// wasting the candidate pool on leads in other salesperson territories.
		global $wpdb;
		$batch_row      = $wpdb->get_row( $wpdb->prepare(
			"SELECT assigned_to FROM {$wpdb->prefix}zl_batches WHERE id = %d", $batch_id
		), ARRAY_A );
		$sp_code        = $batch_row ? $batch_row['assigned_to'] : '';
		$sp_territories = array();
		// v2.0.0 FIX: When $sp_code is '_ALL_', skip territory filtering entirely.
		// This allows admin/owner to generate cross-territory batches. Each lead's
		// territory is still recorded in the DB for post-generation filtering.
		// Coverage gating only applies when a service-area map is configured. With the empty
		// (allow-all) default, $sp_territories stays empty and the early territory check below
		// is skipped entirely — no lead is dropped for being "out of area".
		if ( ! empty( $sp_code ) && $sp_code !== '_ALL_'
			&& function_exists( 'zl_coverage_configured' ) && zl_coverage_configured() ) {
			$salespeople = function_exists( 'zl_salespeople' ) ? zl_salespeople() : array();
			foreach ( $salespeople as $sp_item ) {
				if ( strtoupper( $sp_item['code'] ) === strtoupper( $sp_code ) ) {
					$sp_territories = array_filter( array_map( 'trim', explode( ',', strtoupper( $sp_item['territories'] ) ) ) );
					break;
				}
			}
		}
		// When $sp_code is '_ALL_' or coverage is unconfigured, $sp_territories stays empty →
		// the territory check is skipped (allow-all).

		// ── Early-stop: if we already have enough candidates, skip remaining ──
		// When a product filter is active, AI strict validation rejects many candidates
		// (often 70-80%), so we need a much larger pool to end up with enough leads.
		// Without product filter: test=10, full=3× leads_per_batch
		// With product filter:    test=30, full=5× leads_per_batch
		$leads_per_batch = (int) get_option( 'zl_leads_per_batch', 50 );
		$batch_options   = get_transient( "zl_batch_{$batch_id}_options" ) ?: array();
		$has_pf          = ! empty( $batch_options['product_filter'] );
		$early_stop_cap  = $is_test
			? ( $has_pf ? 30 : 10 )
			: ( $leads_per_batch * ( $has_pf ? 5 : 3 ) );

		if ( count( $candidates ) >= $early_stop_cap ) {
			wp_send_json_success( array(
				'processed'       => min( $offset, $total ),
				'total'           => $total,
				'done'            => true,
				'candidate_count' => count( $candidates ),
				'early_stopped'   => true,
			) );
			return;
		}

		if ( empty( $slice ) ) {
			wp_send_json_success( array(
				'processed'       => $total,
				'total'           => $total,
				'done'            => true,
				'candidate_count' => count( $candidates ),
			) );
			return;
		}

		try {
			$gen = new ZL_Lead_Generator();
			$gen->init_clients();
			// v1.8.0: Reset per-chunk caches + restore POE concurrency cap.
			$gen->clear_batch_caches();

			// v1.8.0: Install a progress callback so bulk-Nutshell and
			// parallel-AI heartbeats keep the frontend's progress bar
			// moving even though we're inside one long AJAX call.
			$gen->set_progress_callback( function ( $stage, $cur, $tot ) use ( $batch_id ) {
				if ( class_exists( 'ZL_Progress' ) ) {
					ZL_Progress::heartbeat( $batch_id );
				}
			} );

			// v1.8.0 — IMPROVEMENT A: Batched Nutshell dedup pre-fetch.
			// Pass over this chunk's customer IDs once to fetch FB client
			// records (cached internally) and collect emails; then bulk-
			// search Nutshell in parallel so the per-customer enrich loop
			// below hits a local cache instead of issuing N serial HTTPs.
			if ( class_exists( 'ZL_Progress' ) ) {
				ZL_Progress::stage( $batch_id, 'Looking up contacts in Nutshell…', 'nutshell_bulk', count( $slice ) );
			}
			$emails_for_bulk = array();
			foreach ( $slice as $pre_cid ) {
				$fb_client = $gen->get_fb_client_cached( $pre_cid );
				if ( is_array( $fb_client ) && ! empty( $fb_client['email'] ) ) {
					$emails_for_bulk[] = trim( (string) $fb_client['email'] );
				}
				if ( class_exists( 'ZL_Progress' ) ) {
					ZL_Progress::advance( $batch_id, 1 );
				}
			}
			if ( ! empty( $emails_for_bulk ) ) {
				// v2.1.0: tunable concurrency (default ZL_NUTSHELL_PARALLEL=10).
				$ns_cap = (int) apply_filters( 'zlg_nutshell_parallel',
					defined( 'ZL_NUTSHELL_PARALLEL' ) ? ZL_NUTSHELL_PARALLEL : 8 );
				$gen->prime_nutshell_cache_from_emails( $emails_for_bulk, max( 1, $ns_cap ) );
			}

			if ( class_exists( 'ZL_Progress' ) ) {
				ZL_Progress::stage( $batch_id, 'Enriching customers…', 'enrich', count( $slice ) );
			}

			$enriched       = 0;
			$skip_cooldown  = 0;
			$skip_enrich    = 0;
			$skip_product   = 0;
			$skip_territory = 0;
			$errors         = 0;

			// Enrichment sub-category counters for detailed diagnostics
			$skip_fb_api    = 0;  // FreshBooks API returned null
			$skip_excluded  = 0;  // Excluded company match
			$skip_commercial = 0; // Commercial entity auto-detection

			foreach ( $slice as $cid ) {
				try {
					$invoices = isset( $chunk_invoices[ $cid ] ) ? $chunk_invoices[ $cid ] : array();

					// Cooldown check (default 90 days)
					if ( $gen->is_within_cooldown( $cid ) ) {
						$skip_cooldown++;
						continue;
					}

					// ── Pre-enrichment product filter (v1.3.0 optimization) ──────
					// Check product match from raw invoice data BEFORE calling
					// FreshBooks/Nutshell APIs.  This skips 80%+ of expensive API
					// calls when a product filter is active.
					if ( ! empty( $product_filter ) ) {
						$filter_data   = $expanded_filter ?: $product_filter;
						$raw_purchase  = $gen->parse_purchase_history( $invoices );
						$temp_candidate = array(
							'purchase_summary'  => $raw_purchase['summary'],
							'purchase_history'  => wp_json_encode( $raw_purchase['items'] ),
							'nutshell_interests' => '',
						);
						if ( ! $gen->matches_product_filter( $temp_candidate, $filter_data ) ) {
							$skip_product++;
							continue;
						}
					}

					$fail_reason = null;
					$candidate   = $gen->enrich_customer( $cid, $invoices, $fail_reason );
					if ( ! $candidate ) {
						$skip_enrich++;
						// Track sub-category
						if ( 'freshbooks_api' === $fail_reason ) {
							$skip_fb_api++;
						} elseif ( 'excluded_company' === $fail_reason ) {
							$skip_excluded++;
						} elseif ( 'commercial_entity' === $fail_reason ) {
							$skip_commercial++;
						}
						continue;
					}

					// Territory check — skip candidates outside the salesperson's territory
					// so the early-stop pool only counts leads the salesperson can actually use.
					if ( ! empty( $sp_territories ) ) {
						$cand_territory = strtoupper( trim( $candidate['territory'] ?? '' ) );
						if ( empty( $cand_territory ) || ! in_array( $cand_territory, $sp_territories, true ) ) {
							$skip_territory++;
							continue;
						}
					}

					// Avoid duplicate candidates (safe for retry-after-timeout scenarios
					// where the first request may have completed server-side).
					$cand_cid = $candidate['customer_id'] ?? $cid;
					$is_dup   = false;
					foreach ( $candidates as $existing ) {
						if ( ( $existing['customer_id'] ?? '' ) === $cand_cid ) {
							$is_dup = true;
							break;
						}
					}
					if ( ! $is_dup ) {
						$candidates[] = $candidate;
						$enriched++;
					}

					// v1.8.0: Heartbeat per customer so the frontend bar
					// keeps ticking — never more than a handful of seconds
					// between advances even on slow upstreams.
					if ( class_exists( 'ZL_Progress' ) ) {
						ZL_Progress::advance( $batch_id, 1 );
					}
				} catch ( \Throwable $e ) {
					// Per-customer failure is non-fatal — skip and continue
					$errors++;
					error_log( 'ZL: Enrichment error for customer ' . $cid . ': ' . $e->getMessage() );
					// Still advance so a crash in one customer doesn't look
					// like a stall to the watchdog.
					if ( class_exists( 'ZL_Progress' ) ) {
						ZL_Progress::advance( $batch_id, 1 );
					}
				}
			}

			// Per-filter skip counters for post-loop filters (city/zip, spend)
			$skip_cityzip = 0;
			$skip_spend   = 0;

			// Apply city/zip filter
			$city_zip_filter = $batch_options['city_zip_filter'] ?? '';
			$pre_cityzip     = count( $candidates );
			if ( ! empty( $city_zip_filter ) ) {
				$candidates  = $gen->filter_by_city_zip( $candidates, $city_zip_filter );
				$skip_cityzip = $pre_cityzip - count( $candidates );
			}

			// Apply spend filter
			$spend_min     = (float) ( $batch_options['spend_min'] ?? 0 );
			$spend_max     = (float) ( $batch_options['spend_max'] ?? 0 );
			$pre_spend     = count( $candidates );
			if ( $spend_min > 0 || $spend_max > 0 ) {
				$candidates = $gen->filter_by_spend( $candidates, $spend_min, $spend_max );
				$skip_spend = $pre_spend - count( $candidates );
			}

			$this->set_compressed_transient( "zl_batch_{$batch_id}_candidates", $candidates, ZL_TRANSIENT_TTL );

			$new_offset = $offset + $chunk;

			// Check early-stop again after this chunk
			$done = ( $new_offset >= $total ) || ( count( $candidates ) >= $early_stop_cap );

			// Route the enrichment-time coverage drops through the platform disposition log
			// (in addition to returning the counts below) so nothing is silent.
			if ( $skip_territory > 0 && function_exists( 'zl_log_disposition' ) ) {
				zl_log_disposition( 'territory_out_of_area', array(
					'stage'       => 'enrich',
					'batch_id'    => $batch_id,
					'salesperson' => $sp_code,
					'held'        => (int) $skip_territory,
				) );
			}

			wp_send_json_success( array(
				'processed'       => min( $new_offset, $total ),
				'total'           => $total,
				'enriched'        => $enriched,
				'errors'          => $errors,
				'candidate_count' => count( $candidates ),
				'done'            => $done,
				'early_stopped'   => ( count( $candidates ) >= $early_stop_cap && $new_offset < $total ),
				'next_offset'     => $new_offset,
				// Per-filter skip counters (this chunk only — JS accumulates)
				'skip_cooldown'   => $skip_cooldown,
				'skip_enrich'     => $skip_enrich,
				'skip_product'    => $skip_product,
				'skip_territory'  => $skip_territory,
				'skip_cityzip'    => $skip_cityzip,
				'skip_spend'      => $skip_spend,
				// Enrichment sub-categories (this chunk only — JS accumulates)
				'skip_fb_api'     => $skip_fb_api,
				'skip_excluded'   => $skip_excluded,
				'skip_commercial' => $skip_commercial,
			) );
		} catch ( \Throwable $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	/**
	 * Step 4: Filter by territory, score, select top N, save to DB.
	 * Enforces strict territory rules (by territory code) based on zip codes.
	 */
	public function ajax_select_leads() {
		@set_time_limit( 300 ); // Scoring + filtering can be heavy with many candidates
		check_ajax_referer( 'zl_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'zdz_access_app' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$batch_id = (int) ( $_POST['batch_id'] ?? 0 );
		$is_test  = (int) ( $_POST['is_test'] ?? 0 );

		global $wpdb;
		$batch = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}zl_batches WHERE id = %d",
			$batch_id
		), ARRAY_A );

		if ( ! $batch ) {
			wp_send_json_error( 'Batch not found.' );
		}

		$sp_code    = $batch['assigned_to'];
		$candidates = $this->get_compressed_transient( "zl_batch_{$batch_id}_candidates" ) ?: array();

		if ( empty( $candidates ) ) {
			wp_send_json_error( 'No eligible candidates found. Try adjusting lookback or cooldown settings.' );
		}

		try {
			$gen = new ZL_Lead_Generator();
			$gen->init_clients();

			// Filter by territory
			$filtered = $gen->filter_by_territory( $candidates, $sp_code );

			if ( empty( $filtered ) ) {
				wp_send_json_error( 'No candidates match the territory for ' . $sp_code . '. Check Nutshell Territory custom fields.' );
			}

			// Score each lead
			$scored = array();
			foreach ( $filtered as $c ) {
				$score    = $gen->score_lead( $c );
				$c['_score'] = $score;
				$scored[] = $c;
			}

			// Sort by score descending
			usort( $scored, function( $a, $b ) {
				return $b['_score'] <=> $a['_score'];
			} );

			// Select top N (3 for test, configured amount for full)
			// When a product filter is active, AI strict validation can reject 70-80% of leads
			// (a broad product keyword can also catch unrelated item types)
			// So over-select by 5× instead of 3× to ensure enough survive validation.
			$settings       = $gen->get_settings();
			$batch_options  = get_transient( "zl_batch_{$batch_id}_options" ) ?: array();
			$product_filter = isset( $batch_options['product_filter'] ) ? $batch_options['product_filter'] : '';
			$desired_limit  = $is_test ? 3 : $settings['leads_per_batch'];
			$limit          = ! empty( $product_filter )
				? min( $desired_limit * 5, count( $scored ) )
				: $desired_limit;
			$leads = array_slice( $scored, 0, $limit );

			// Apply demographic filter (uses AI name classification)
			$demographic_filter = $batch_options['demographic_filter'] ?? 'both';
			if ( ! empty( $demographic_filter ) && $demographic_filter !== 'both' ) {
				$leads = $gen->filter_by_demographic( $leads, $demographic_filter );
			}

			// Save to DB + update lead history
			$saved = 0;
			foreach ( $leads as $lead ) {
				$score = $lead['_score'];
				$gen->save_lead_to_db( $batch_id, $lead, $score );

				// Track in lead history (skip for test to avoid polluting cooldown)
				if ( ! $is_test ) {
					$gen->update_lead_history( $lead['freshbooks_client_id'], $lead['email'], $batch_id );
				}
				$saved++;
			}

			// Update batch totals
			$wpdb->update(
				$wpdb->prefix . 'zl_batches',
				array( 'total_leads' => $saved ),
				array( 'id' => $batch_id ),
				array( '%d' ),
				array( '%d' )
			);

			// Clean up transients
			delete_transient( "zl_batch_{$batch_id}_customers" );
			delete_transient( "zl_batch_{$batch_id}_candidates" );
			delete_transient( "zl_batch_{$batch_id}_unique_names" );
			// v2.0.0: Clean up chunked transients
			delete_transient( "zl_batch_{$batch_id}_cmeta" );
			for ( $dci = 0; $dci < 50; $dci++ ) {
				if ( get_transient( "zl_batch_{$batch_id}_cchunk_{$dci}" ) === false ) { break; }
				delete_transient( "zl_batch_{$batch_id}_cchunk_{$dci}" );
			}

			wp_send_json_success( array(
				'lead_count'      => $saved,
				'total_candidates' => count( $filtered ),
			) );
		} catch ( \Throwable $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	/**
	 * Step 4.5: AI strict validation — verify each selected lead against the product filter.
	 * Uses Gemini 3.1 Pro with high reasoning to reject false positives.
	 * This prevents sending irrelevant leads to Nutshell.
	 */
	public function ajax_ai_validate() {
		@set_time_limit( 300 ); // Gemini strict validation per lead (AI calls)
		check_ajax_referer( 'zl_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'zdz_access_app' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$batch_id = (int) ( $_POST['batch_id'] ?? 0 );
		$is_test  = (int) ( $_POST['is_test'] ?? 0 );

		global $wpdb;

		// Get the batch's product filter
		$batch = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}zl_batches WHERE id = %d",
			$batch_id
		), ARRAY_A );

		if ( ! $batch ) {
			wp_send_json_error( 'Batch not found.' );
		}

		$product_filter = $batch['product_filter'] ?? '';

		// If no filter, skip validation — all leads are valid
		if ( empty( $product_filter ) ) {
			wp_send_json_success( array(
				'skipped'  => true,
				'reason'   => 'No product filter — all leads pass',
				'passed'   => 0,
				'rejected' => 0,
			) );
			return;
		}

		// Get leads for this batch
		$leads = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}zl_leads WHERE batch_id = %d ORDER BY score DESC",
			$batch_id
		), ARRAY_A );

		if ( empty( $leads ) ) {
			wp_send_json_success( array( 'skipped' => true, 'reason' => 'No leads to validate' ) );
			return;
		}

		try {
			$gen = new ZL_Lead_Generator();
			$gen->init_clients();

			if ( ! $gen->has_ai() ) {
				wp_send_json_success( array(
					'skipped' => true,
					'reason'  => 'No AI configured — keeping all leads',
				) );
				return;
			}

			$result = $gen->ai_strict_validate( $leads, $product_filter );

			// Delete rejected leads from DB
			$rejected_ids = array_column( $result['rejected'], 'id' );
			if ( ! empty( $rejected_ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $rejected_ids ), '%d' ) );
				$wpdb->query( $wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}zl_leads WHERE id IN ({$placeholders})",
					...$rejected_ids
				) );
			}

			// Trim remaining leads to desired limit (over-selection trim-back)
			$desired_limit  = $is_test ? 3 : (int) get_option( 'zl_leads_per_batch', 50 );
			$remaining      = $wpdb->get_results( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}zl_leads WHERE batch_id = %d ORDER BY score DESC",
				$batch_id
			), ARRAY_A );

			if ( count( $remaining ) > $desired_limit ) {
				$excess_ids = array_column( array_slice( $remaining, $desired_limit ), 'id' );
				if ( ! empty( $excess_ids ) ) {
					$placeholders = implode( ',', array_fill( 0, count( $excess_ids ), '%d' ) );
					$wpdb->query( $wpdb->prepare(
						"DELETE FROM {$wpdb->prefix}zl_leads WHERE id IN ({$placeholders})",
						...$excess_ids
					) );
				}
			}

			// Update batch total
			$final_count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}zl_leads WHERE batch_id = %d",
				$batch_id
			) );
			$wpdb->update(
				$wpdb->prefix . 'zl_batches',
				array( 'total_leads' => $final_count ),
				array( 'id' => $batch_id ),
				array( '%d' ),
				array( '%d' )
			);

			wp_send_json_success( array(
				'passed'      => count( $result['passed'] ),
				'rejected'    => count( $result['rejected'] ),
				'trimmed'     => max( 0, count( $remaining ) - $desired_limit - count( $rejected_ids ) ),
				'final_count' => $final_count,
				'details'     => $result['rejected'],
				'ai_used'     => $result['ai_used'],
			) );
		} catch ( \Throwable $e ) {
			// Non-fatal — if validation fails, keep all leads
			wp_send_json_success( array(
				'passed'   => count( $leads ),
				'rejected' => 0,
				'error'    => $e->getMessage(),
			) );
		}
	}

	/**
	 * Step 5: AI refine purchase descriptions.
	 * Rewrites descriptions to be <101 characters to meet Nutshell API constraints.
	 */
	public function ajax_ai_refine() {
		@set_time_limit( 300 ); // Gemini AI rewriting descriptions (<101 chars)
		check_ajax_referer( 'zl_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'zdz_access_app' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$batch_id = (int) ( $_POST['batch_id'] ?? 0 );
		$offset   = (int) ( $_POST['offset'] ?? 0 );
		$chunk    = 10;

		global $wpdb;
		$leads = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, purchase_summary FROM {$wpdb->prefix}zl_leads WHERE batch_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
			$batch_id,
			$chunk,
			$offset
		), ARRAY_A );

		if ( empty( $leads ) ) {
			wp_send_json_success( array( 'refined' => 0, 'done' => true, 'skipped' => true ) );
			return;
		}

		try {
			$gen = new ZL_Lead_Generator();
			$gen->init_clients();

			if ( ! $gen->has_ai() ) {
				wp_send_json_success( array( 'refined' => 0, 'done' => true, 'skipped' => true, 'reason' => 'No AI configured' ) );
				return;
			}

			$refined = $gen->ai_refine_descriptions( $leads );

			// Update DB
			$count = 0;
			foreach ( $refined as $r ) {
				$wpdb->update(
					$wpdb->prefix . 'zl_leads',
					array( 'purchase_summary' => $r['purchase_summary'] ),
					array( 'id' => $r['id'] ),
					array( '%s' ),
					array( '%d' )
				);
				$count++;
			}

			$total_leads = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}zl_leads WHERE batch_id = %d",
				$batch_id
			) );

			$new_offset = $offset + $chunk;
			$done       = $new_offset >= $total_leads;

			wp_send_json_success( array(
				'refined'     => $count,
				'done'        => $done,
				'next_offset' => $new_offset,
			) );
		} catch ( \Throwable $e ) {
			// Non-fatal — continue to next chunk even on error
			wp_send_json_success( array( 'refined' => 0, 'error' => $e->getMessage(), 'done' => false, 'next_offset' => $offset + $chunk ) );
		}
	}

	/**
	 * Step 6: Create Nutshell leads (chunked, 5 at a time). Skipped for test batches.
	 * Creates the Contact, Lead, and attaches Notes in Nutshell CRM.
	 */
	public function ajax_create_nutshell() {
		@set_time_limit( 300 ); // Nutshell CRM API calls (create leads + contacts)
		check_ajax_referer( 'zl_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'zdz_access_app' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$batch_id = (int) ( $_POST['batch_id'] ?? 0 );
		$offset   = (int) ( $_POST['offset'] ?? 0 );
		$is_test  = (int) ( $_POST['is_test'] ?? 0 );
		$chunk    = 5;

		// Skip entirely for test batches
		if ( $is_test ) {
			wp_send_json_success( array( 'created' => 0, 'done' => true, 'skipped' => true ) );
			return;
		}

		global $wpdb;

		// v1.6.0 — Rule 3: Server-side email dedup.
		// Track which emails have already been pushed to Nutshell in THIS batch
		// to prevent duplicate contacts from chunked AJAX retries.
		$dedup_key    = "zl_batch_{$batch_id}_ns_emails";
		$sent_emails  = get_transient( $dedup_key );
		if ( ! is_array( $sent_emails ) ) {
			$sent_emails = array();
		}

		$leads = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}zl_leads WHERE batch_id = %d AND (nutshell_lead_id IS NULL OR nutshell_lead_id = '') ORDER BY id ASC LIMIT %d OFFSET %d",
			$batch_id,
			$chunk,
			$offset
		), ARRAY_A );

		if ( empty( $leads ) ) {
			wp_send_json_success( array( 'created' => 0, 'done' => true ) );
			return;
		}

		// Get salesperson name and product filter for notes
		$batch = $wpdb->get_row( $wpdb->prepare(
			"SELECT assigned_to, product_filter FROM {$wpdb->prefix}zl_batches WHERE id = %d",
			$batch_id
		), ARRAY_A );

		$salespeople    = json_decode( get_option( 'zl_salespeople', '[]' ), true ) ?: zl_salespeople();
		$sp_name        = $batch['assigned_to'] ?? '';
		$product_filter = $batch['product_filter'] ?? '';
		foreach ( $salespeople as $sp ) {
			if ( $sp['code'] === $sp_name ) {
				$sp_name = $sp['name'];
				break;
			}
		}

		try {
			$gen = new ZL_Lead_Generator();
			$gen->init_clients();

			$created    = 0;
			$last_error = '';
			$skipped_dedup = 0;
			foreach ( $leads as $lead ) {
				// v1.6.0 — Rule 3: Skip if this email was already sent to Nutshell
				$lead_email = strtolower( trim( $lead['email'] ?? '' ) );
				if ( ! empty( $lead_email ) && in_array( $lead_email, $sent_emails, true ) ) {
					$skipped_dedup++;
					error_log( 'ZL Create Nutshell: Skipped duplicate email ' . $lead_email . ' (lead #' . $lead['id'] . ')' );
					continue;
				}

				try {
					$ns_lead_id = $gen->create_nutshell_lead( $lead, $sp_name, $product_filter );
				} catch ( \Throwable $lead_err ) {
					$last_error = $lead_err->getMessage();
					error_log( 'ZL Create Nutshell: Failed lead #' . $lead['id'] . ': ' . $last_error );
					continue;
				}
				if ( ! empty( $ns_lead_id ) ) {
					$wpdb->update(
						$wpdb->prefix . 'zl_leads',
						array(
							'nutshell_lead_id' => $ns_lead_id,
							'status'           => 'Lead Created',
						),
						array( 'id' => $lead['id'] ),
						array( '%s', '%s' ),
						array( '%d' )
					);
					$created++;

					// Track this email as sent
					if ( ! empty( $lead_email ) ) {
						$sent_emails[] = $lead_email;
					}
				}
			}

			// Persist dedup list for subsequent chunks
			set_transient( $dedup_key, $sent_emails, ZL_TRANSIENT_TTL );

			// If NONE succeeded in this chunk, stop to prevent infinite retry loop
			if ( $created === 0 && $skipped_dedup === 0 && count( $leads ) > 0 ) {
				$msg = 'Failed to create Nutshell leads.';
				if ( ! empty( $last_error ) ) {
					$msg .= ' Error: ' . $last_error;
				}
				wp_send_json_error( $msg );
				return;
			}

			// Check if more remain
			$remaining = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}zl_leads WHERE batch_id = %d AND (nutshell_lead_id IS NULL OR nutshell_lead_id = '')",
				$batch_id
			) );

			wp_send_json_success( array(
				'created'     => $created,
				'done'        => (int) $remaining === 0,
				'next_offset' => 0, // Always offset 0 since we query un-created ones
			) );
		} catch ( \Throwable $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	/**
	 * Step 7: Finalize — AI summary + mark complete.
	 * Generates a brief AI summary of the generated batch and marks it complete.
	 */
	public function ajax_finalize() {
		@set_time_limit( 300 ); // Gemini AI batch summary generation
		check_ajax_referer( 'zl_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'zdz_access_app' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$batch_id = (int) ( $_POST['batch_id'] ?? 0 );

		// v1.6.0 — Release the batch lock (Rule 5) now that the pipeline is complete.
		$user_id  = get_current_user_id();
		$lock_key = 'zl_batch_lock_' . $user_id;
		delete_transient( $lock_key );

		global $wpdb;

		try {
			$gen = new ZL_Lead_Generator();
			$gen->init_clients();

			$summary = $gen->ai_batch_summary( $batch_id );

			// Get final counts
			$lead_count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}zl_leads WHERE batch_id = %d",
				$batch_id
			) );

			$wpdb->update(
				$wpdb->prefix . 'zl_batches',
				array(
					'status'      => 'complete',
					'total_leads' => $lead_count,
					'ai_summary'  => $summary,
				),
				array( 'id' => $batch_id ),
				array( '%s', '%d', '%s' ),
				array( '%d' )
			);

			// v1.6.0 — Audit log: batch finalized
			if ( class_exists( 'ZDZ_Admin_Dashboard' ) && method_exists( 'ZDZ_Admin_Dashboard', 'log_action' ) ) {
				$batch_tag = $wpdb->get_var( $wpdb->prepare(
					"SELECT batch_tag FROM {$wpdb->prefix}zl_batches WHERE id = %d", $batch_id
				) );
				ZDZ_Admin_Dashboard::log_action( 'lead_generator', "Batch finalized: {$batch_tag} — {$lead_count} leads" );
			}

			// v1.8.0 — Mark structured progress complete so the frontend
			// stops polling. ZL_Progress record expires naturally.
			if ( class_exists( 'ZL_Progress' ) ) {
				ZL_Progress::complete( $batch_id );
			}

			wp_send_json_success( array(
				'summary'    => $summary,
				'lead_count' => $lead_count,
			) );
		} catch ( \Throwable $e ) {
			// Still mark as complete even if AI fails
			$wpdb->update(
				$wpdb->prefix . 'zl_batches',
				array( 'status' => 'complete' ),
				array( 'id' => $batch_id ),
				array( '%s' ),
				array( '%d' )
			);
			if ( class_exists( 'ZL_Progress' ) ) {
				ZL_Progress::complete( $batch_id );
			}
			wp_send_json_success( array(
				'summary'    => 'Batch complete (AI summary unavailable).',
				'lead_count' => 0,
			) );
		}
	}

	// ═══════════════════════════════════════════════════════════════════
	// AJAX — Lead management
	// ═══════════════════════════════════════════════════════════════════

	/**
	 * Get leads for a batch (expand row).
	 * Fetches leads from the DB to render the expanded view in the UI.
	 */
	public function ajax_get_batch_leads() {
		check_ajax_referer( 'zl_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'zdz_access_app' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$batch_id = (int) ( $_POST['batch_id'] ?? 0 );

		global $wpdb;
		$leads = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}zl_leads WHERE batch_id = %d ORDER BY score DESC",
			$batch_id
		), ARRAY_A );

		$batch = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}zl_batches WHERE id = %d",
			$batch_id
		), ARRAY_A );

		// v1.5.0: Permission-aware field scrubbing
		if ( class_exists( 'ZL_Permissions' ) ) {
			foreach ( $leads as &$lead ) {
				$lead = ZL_Permissions::maybe_scrub_lead( $lead );
				$lead = ZL_Permissions::maybe_scrub_contact_info( $lead );
			}
			unset( $lead );
		}

		wp_send_json_success( array(
			'leads'   => $leads,
			'batch'   => $batch,
		) );
	}

	/**
	 * Update a lead's contact status.
	 * Allows users to manually mark a lead as contacted and add notes.
	 */
	public function ajax_update_contact_status() {
		check_ajax_referer( 'zl_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'zdz_access_app' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$lead_id        = (int) ( $_POST['lead_id'] ?? 0 );

		// ── OWNERSHIP GATE (v2.4.0) ─────────────────────────────────────────
		// zdz_access_app alone is not enough: a salesperson may act ONLY on a
		// lead assigned to them; admins/operators may act on any. Kiosk users
		// are denied (no per-user acting on the shared device). This closes the
		// prior "any app user could update any lead" gap.
		if ( class_exists( 'ZL_Lead_Assignment' )
			&& ! ZL_Lead_Assignment::current_user_can_act_on_lead( $lead_id ) ) {
			wp_send_json_error( 'You can only act on leads assigned to you.' );
		}

		$status         = sanitize_text_field( $_POST['contact_status'] ?? 'pending' );
		$notes          = sanitize_textarea_field( $_POST['contact_notes'] ?? '' );
		// 'phone' (default) or 'email' — lets the same handler serve both
		// "✓ Contacted" (a call) and "email marks contacted via email".
		$channel        = sanitize_key( $_POST['contact_channel'] ?? 'phone' );
		if ( ! in_array( $channel, array( 'phone', 'email' ), true ) ) {
			$channel = 'phone';
		}

		global $wpdb;
		// Fetch the row up front: we need batch_id (for the count) and the
		// Nutshell lead id (for write-back) and contacted_at (idempotent time).
		$lead = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, batch_id, nutshell_lead_id, contacted_at
			 FROM {$wpdb->prefix}zl_leads WHERE id = %d",
			$lead_id
		), ARRAY_A );

		if ( ! $lead ) {
			wp_send_json_error( 'Lead not found' );
		}

		$update_data = array(
			'contact_status' => $status,
			'contact_notes'  => $notes,
		);

		// Stamp contacted_at once (UTC). Reuse an existing stamp on re-contact so
		// the Nutshell activity time matches what the rep first saw.
		$occurred_at = $lead['contacted_at'] ?? '';
		if ( $status === 'contacted' ) {
			if ( empty( $occurred_at ) ) {
				$occurred_at                 = current_time( 'mysql', true );
				$update_data['contacted_at'] = $occurred_at;
			}
		}

		// ── LOCAL SAVE (always succeeds — never blocked by a CRM hiccup) ──
		$wpdb->update(
			$wpdb->prefix . 'zl_leads',
			$update_data,
			array( 'id' => $lead_id ),
			// formats track $update_data key order; the optional contacted_at is
			// always a trailing %s when present.
			array_fill( 0, count( $update_data ), '%s' ),
			array( '%d' )
		);

		// Update the batch's contacted count
		$batch_id = (int) ( $lead['batch_id'] ?? 0 );
		if ( $batch_id ) {
			$contacted = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}zl_leads WHERE batch_id = %d AND contact_status = 'contacted'",
				$batch_id
			) );
			$wpdb->update(
				$wpdb->prefix . 'zl_batches',
				array( 'leads_contacted' => $contacted ),
				array( 'id' => $batch_id ),
				array( '%d' ),
				array( '%d' )
			);
		}

		// ── NUTSHELL WRITE-BACK (best-effort; queues + retries on failure) ──
		// Only "contacted" propagates an activity. Skip/pending stay local
		// (a user: skip is lighter-weight). The seam guarantees the local
		// save above is never undone by a CRM problem.
		$writeback = array( 'posted' => false, 'queued' => false, 'reason' => 'not_applicable' );
		if ( $status === 'contacted' && class_exists( 'ZL_Nutshell_Writeback' ) ) {
			$rep      = wp_get_current_user();
			$rep_name = $rep && ! empty( $rep->display_name ) ? $rep->display_name : '';

			$writeback = ZL_Nutshell_Writeback::record_contact( array(
				'local_lead_id'    => $lead_id,
				'nutshell_lead_id' => (int) ( $lead['nutshell_lead_id'] ?? 0 ),
				'channel'          => $channel,
				'note'             => $notes,
				'rep_name'         => $rep_name,
				'occurred_at'      => $occurred_at ?: current_time( 'mysql', true ),
			) );
		}

		// v2.5.0: notify so the lead owner's cached dashboard counts refresh.
		// The owner is the assigned user (the rep), not necessarily the actor
		// (an admin could update on their behalf).
		if ( class_exists( 'ZL_Lead_Assignment' ) ) {
			$owner_id = ZL_Lead_Assignment::get_lead_owner( $lead_id );
			if ( $owner_id > 0 ) {
				do_action( 'zl_lead_status_changed', $owner_id, $lead_id, $status );
			}
		}

		wp_send_json_success( array(
			'updated'   => true,
			'writeback' => $writeback,  // { posted, queued, reason } — JS may show a soft note
		) );
	}

	/**
	 * v2.4.0 — Admin/operator assigns one or more leads to a TS user.
	 * Authoritative, explicit, audited (see ZL_Lead_Assignment::assign).
	 * Salespeople cannot call this (only admins/operators assign).
	 */
	public function ajax_assign_leads() {
		check_ajax_referer( 'zl_nonce', 'nonce' );
		if ( ! class_exists( 'ZL_Lead_Assignment' ) || ! ZL_Lead_Assignment::is_lead_admin() ) {
			wp_send_json_error( 'Only admins or operators can assign leads.' );
		}

		$lead_ids    = isset( $_POST['lead_ids'] ) ? (array) $_POST['lead_ids'] : array();
		$lead_ids    = array_map( 'intval', $lead_ids );
		$assignee_id = (int) ( $_POST['assignee_id'] ?? 0 ); // 0 = unassign

		$result = ZL_Lead_Assignment::assign( $lead_ids, $assignee_id, get_current_user_id() );
		if ( empty( $result['success'] ) ) {
			wp_send_json_error( $result['error'] ?: 'Assignment failed.' );
		}
		wp_send_json_success( array(
			'assigned' => (int) $result['assigned'],
		) );
	}

	/**
	 * v2.4.0 — List users an admin can assign leads to (Leads-app users).
	 * Returns [{ id, name, code, has_nutshell }] for the assign dropdown.
	 */
	public function ajax_get_assignable_users() {
		check_ajax_referer( 'zl_nonce', 'nonce' );
		if ( ! class_exists( 'ZL_Lead_Assignment' ) || ! ZL_Lead_Assignment::is_lead_admin() ) {
			wp_send_json_error( 'Unauthorized' );
		}

		// Candidate roles that work leads. (ts_general/kiosk excluded by design.)
		$candidates = get_users( array(
			'role__in' => array( 'zdz_owner', 'zdz_admin', 'zdz_operator', 'zdz_sales' ),
			'orderby'  => 'display_name',
			'order'    => 'ASC',
			'number'   => 200,
		) );

		$out = array();
		foreach ( $candidates as $u ) {
			$uid          = (int) $u->ID;
			$code         = (string) get_user_meta( $uid, 'zl_salesperson_code', true );
			$has_nutshell = false;
			if ( class_exists( 'ZDZ_Core_Settings' ) && method_exists( 'ZDZ_Core_Settings', 'get_nutshell_user_id' ) ) {
				$has_nutshell = ZDZ_Core_Settings::get_nutshell_user_id( $uid ) > 0;
			} else {
				$has_nutshell = (string) get_user_meta( $uid, 'zl_nutshell_user_id', true ) !== '';
			}
			$out[] = array(
				'id'           => $uid,
				'name'         => $u->display_name,
				'code'         => $code,
				'has_nutshell' => $has_nutshell,
			);
		}
		wp_send_json_success( array( 'users' => $out ) );
	}

	/**
	 * v2.4.0 — The current user's assigned-lead counts, for the dashboard tile.
	 * Returns { new_today, open_pending, total } scoped to the signed-in user.
	 * Admins get their own assigned counts too (they may also be assigned leads).
	 */
	public function ajax_my_leads_count() {
		check_ajax_referer( 'zl_nonce', 'nonce' );
		if ( ! current_user_can( 'zdz_access_app' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		if ( ! class_exists( 'ZL_Lead_Assignment' ) ) {
			wp_send_json_success( array( 'new_today' => 0, 'open_pending' => 0, 'total' => 0 ) );
		}
		$uid = get_current_user_id();
		wp_send_json_success( array(
			'new_today'    => ZL_Lead_Assignment::count_assigned( $uid, array( 'new_today' => true, 'pending_only' => true ) ),
			'open_pending' => ZL_Lead_Assignment::count_assigned( $uid, array( 'pending_only' => true ) ),
			'total'        => ZL_Lead_Assignment::count_assigned( $uid ),
		) );
	}

	/**
	 * v2.5.0 — Return the current user's ASSIGNED leads for rep mode.
	 *
	 * Server-scoped: a salesperson gets only leads where assigned_user_id is
	 * them; an admin/operator may optionally pass a target user_id to view a
	 * rep's queue (defaults to self). Kiosk is denied. Results are permission-
	 * scrubbed exactly like ajax_get_batch_leads, and ordered actionable-first
	 * (pending before contacted/skipped, then by score) so the rep's next action
	 * is at the top.
	 *
	 * Filters (optional):
	 *   - only: 'pending' | 'all'   (default 'all'; the banner deep-link uses 'pending')
	 */
	public function ajax_my_leads() {
		check_ajax_referer( 'zl_nonce', 'nonce' );
		if ( ! current_user_can( 'zdz_access_app' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		if ( ! class_exists( 'ZL_Lead_Assignment' ) ) {
			wp_send_json_error( 'Assignment unavailable' );
		}

		$current = get_current_user_id();

		// Kiosk never gets a per-user lead list.
		if ( ZL_Lead_Assignment::is_kiosk( $current ) ) {
			wp_send_json_error( 'Not available on the shared device.' );
		}

		// Whose leads? Default self. An admin/operator may view another rep's
		// queue by passing user_id; a salesperson is hard-scoped to themselves.
		$target = $current;
		$req_uid = (int) ( $_POST['user_id'] ?? 0 );
		if ( $req_uid > 0 && $req_uid !== $current ) {
			if ( ZL_Lead_Assignment::is_lead_admin( $current ) ) {
				$target = $req_uid;
			} else {
				wp_send_json_error( 'You can only view your own leads.' );
			}
		}

		$only = sanitize_key( $_POST['only'] ?? 'all' );

		global $wpdb;
		$where = array( 'assigned_user_id = %d' );
		$args  = array( $target );
		if ( $only === 'pending' ) {
			$where[] = "contact_status = 'pending'";
		}

		// Actionable-first ordering: pending(0) above contacted/skipped(1), then
		// newest assignment, then score. Deterministic + repeatable.
		$sql = "SELECT * FROM {$wpdb->prefix}zl_leads
		        WHERE " . implode( ' AND ', $where ) . "
		        ORDER BY (contact_status <> 'pending') ASC, assigned_at DESC, score DESC
		        LIMIT 500";
		$leads = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		$leads = is_array( $leads ) ? $leads : array();

		// Permission-aware field scrubbing (same as batch leads).
		if ( class_exists( 'ZL_Permissions' ) ) {
			foreach ( $leads as &$lead ) {
				$lead = ZL_Permissions::maybe_scrub_lead( $lead );
				$lead = ZL_Permissions::maybe_scrub_contact_info( $lead );
			}
			unset( $lead );
		}

		$counts = class_exists( 'ZL_Dashboard_Tile' )
			? ZL_Dashboard_Tile::get_counts( $target )
			: array( 'new_today' => 0, 'open_pending' => 0, 'total' => 0 );

		wp_send_json_success( array(
			'leads'  => $leads,
			'counts' => $counts,
			'scope'  => ( $target === $current ) ? 'self' : 'admin_view',
		) );
	}

	/**
	 * Send test batch leads to Nutshell CRM (retroactive).
	 * Creates real Nutshell leads for test-batch leads that haven't been sent yet.
	 */
	public function ajax_send_test_to_nutshell() {
		@set_time_limit( 300 ); // Nutshell CRM API calls
		check_ajax_referer( 'zl_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'zdz_access_app' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$batch_id = (int) ( $_POST['batch_id'] ?? 0 );
		$chunk    = 5;

		global $wpdb;

		// Verify this is a test batch
		$batch = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}zl_batches WHERE id = %d AND is_test = 1",
			$batch_id
		), ARRAY_A );

		if ( ! $batch ) {
			wp_send_json_error( 'Batch not found or not a test batch.' );
		}

		// Get leads that haven't been sent to Nutshell yet
		$leads = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}zl_leads WHERE batch_id = %d AND (nutshell_lead_id IS NULL OR nutshell_lead_id = '') ORDER BY id ASC LIMIT %d",
			$batch_id,
			$chunk
		), ARRAY_A );

		if ( empty( $leads ) ) {
			wp_send_json_success( array( 'created' => 0, 'done' => true ) );
			return;
		}

		// Get salesperson name and product filter
		$salespeople    = json_decode( get_option( 'zl_salespeople', '[]' ), true ) ?: zl_salespeople();
		$sp_name        = $batch['assigned_to'] ?? '';
		$product_filter = $batch['product_filter'] ?? '';
		foreach ( $salespeople as $sp ) {
			if ( $sp['code'] === $sp_name ) {
				$sp_name = $sp['name'];
				break;
			}
		}

		try {
			$gen = new ZL_Lead_Generator();
			// Only init Nutshell — we don't need FreshBooks for sending leads
			$gen->init_nutshell_only();

			error_log( 'ZL Send to Nutshell: Processing ' . count( $leads ) . ' leads for batch #' . $batch_id . ', salesperson: ' . $sp_name );

			$created     = 0;
			$last_error  = '';
			foreach ( $leads as $lead ) {
				error_log( 'ZL Send to Nutshell: Creating lead for ' . $lead['first_name'] . ' ' . $lead['last_name'] . ' (ID: ' . $lead['id'] . ')' );

				try {
					$ns_lead_id = $gen->create_nutshell_lead( $lead, $sp_name, $product_filter );
				} catch ( \Throwable $lead_err ) {
					$last_error = $lead_err->getMessage();
					error_log( 'ZL Send to Nutshell: Failed to create lead #' . $lead['id'] . ': ' . $last_error );
					continue; // Skip this lead, try the rest
				}

				if ( ! empty( $ns_lead_id ) ) {
					$wpdb->update(
						$wpdb->prefix . 'zl_leads',
						array(
							'nutshell_lead_id' => $ns_lead_id,
							'status'           => 'Lead Created',
						),
						array( 'id' => $lead['id'] ),
						array( '%s', '%s' ),
						array( '%d' )
					);

					// Track in lead history for cooldown
					$gen->update_lead_history( $lead['freshbooks_client_id'], $lead['email'], $batch_id );
					$created++;
					error_log( 'ZL Send to Nutshell: Created Nutshell lead #' . $ns_lead_id . ' for lead #' . $lead['id'] );
				} else {
					error_log( 'ZL Send to Nutshell: create_nutshell_lead returned empty for lead #' . $lead['id'] );
				}
			}

			// If NONE succeeded in this chunk, stop to prevent infinite retry loop
			if ( $created === 0 && count( $leads ) > 0 ) {
				$msg = 'Failed to create any Nutshell leads in this batch.';
				if ( ! empty( $last_error ) ) {
					$msg .= ' Last error: ' . $last_error;
				}
				wp_send_json_error( $msg );
				return;
			}

			// Check if more remain
			$remaining = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}zl_leads WHERE batch_id = %d AND (nutshell_lead_id IS NULL OR nutshell_lead_id = '')",
				$batch_id
			) );

			// v1.6.0 — Audit log: test batch sent to Nutshell
			if ( (int) $remaining === 0 && class_exists( 'ZDZ_Admin_Dashboard' ) && method_exists( 'ZDZ_Admin_Dashboard', 'log_action' ) ) {
				ZDZ_Admin_Dashboard::log_action( 'lead_generator', "Test batch #{$batch_id} sent to Nutshell ({$created} leads)" );
			}

			wp_send_json_success( array(
				'created'     => $created,
				'done'        => (int) $remaining === 0,
				'next_offset' => 0, // Always 0 since we query un-created ones
			) );
		} catch ( \Throwable $e ) {
			error_log( 'ZL Send to Nutshell FATAL: ' . $e->getMessage() );
			wp_send_json_error( $e->getMessage() );
		}
	}

	/**
	 * Delete a test batch and its leads.
	 * Only allows deletion if the batch is marked as a test batch.
	 */
	public function ajax_delete_batch() {
		check_ajax_referer( 'zl_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'zdz_access_app' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$batch_id = (int) ( $_POST['batch_id'] ?? 0 );

		global $wpdb;

		// Verify the batch exists
		$batch = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}zl_batches WHERE id = %d",
			$batch_id
		) );

		if ( ! $batch ) {
			wp_send_json_error( 'Batch not found.' );
		}

		// Count leads being deleted (for response)
		$lead_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}zl_leads WHERE batch_id = %d",
			$batch_id
		) );

		// Clear cooldown history entries that reference this batch,
		// so these customers can be re-generated in future runs.
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}zl_lead_history WHERE last_batch_id = %d",
			$batch_id
		) );

		// Delete leads and the batch itself
		$wpdb->delete( $wpdb->prefix . 'zl_leads', array( 'batch_id' => $batch_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'zl_batches', array( 'id' => $batch_id ), array( '%d' ) );

		// Clean up any lingering transients for this batch
		delete_transient( "zl_batch_{$batch_id}_customers" );
		delete_transient( "zl_batch_{$batch_id}_candidates" );
		delete_transient( "zl_batch_{$batch_id}_unique_names" );
		delete_transient( "zl_batch_{$batch_id}_options" );
		delete_transient( "zl_batch_{$batch_id}_expanded_filter" );
		delete_transient( "zl_batch_{$batch_id}_ns_emails" ); // v1.6.0 dedup cleanup
		// v2.0.0: Clean up chunked transients
		delete_transient( "zl_batch_{$batch_id}_cmeta" );
		for ( $dci = 0; $dci < 50; $dci++ ) {
			if ( get_transient( "zl_batch_{$batch_id}_cchunk_{$dci}" ) === false ) { break; }
			delete_transient( "zl_batch_{$batch_id}_cchunk_{$dci}" );
		}

		error_log( "ZL: Deleted batch #{$batch_id} ({$batch->batch_tag}) with {$lead_count} leads" );

		// v1.6.0 — Audit log: batch deleted
		if ( class_exists( 'ZDZ_Admin_Dashboard' ) && method_exists( 'ZDZ_Admin_Dashboard', 'log_action' ) ) {
			ZDZ_Admin_Dashboard::log_action( 'lead_generator', "Batch deleted: {$batch->batch_tag} ({$lead_count} leads)" );
		}

		wp_send_json_success( array(
			'deleted'    => true,
			'lead_count' => $lead_count,
		) );
	}

	/**
	 * Sync Nutshell status and notes for all leads.
	 */
	public function ajax_sync_nutshell() {
		@set_time_limit( 300 );
		check_ajax_referer( 'zl_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'zdz_access_app' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		global $wpdb;
		$leads = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}zl_leads WHERE nutshell_lead_id IS NOT NULL AND nutshell_lead_id != ''", ARRAY_A );

		if ( empty( $leads ) ) {
			wp_send_json_success( array( 'synced' => 0, 'freed' => 0, 'message' => 'No leads to sync.' ) );
		}

		try {
			$gen = new ZL_Lead_Generator();
			$gen->init_clients();

			$synced = 0;
			$freed  = 0; // Count of deleted leads whose cooldown was cleared
			foreach ( $leads as $lead ) {
				$sync_data = $gen->sync_lead_from_nutshell( $lead );
				if ( $sync_data ) {
					$wpdb->update(
						$wpdb->prefix . 'zl_leads',
						array(
							'nutshell_status'    => $sync_data['status'],
							'salesperson_notes'  => $sync_data['notes'],
							'nutshell_synced_at' => current_time( 'mysql' ),
						),
						array( 'id' => $lead['id'] ),
						array( '%s', '%s', '%s' ),
						array( '%d' )
					);
					$synced++;

					// If the lead was deleted in Nutshell, clear the cooldown
					// so this customer becomes available for future lead searches.
					if ( 'Deleted' === $sync_data['status'] && ! empty( $lead['freshbooks_client_id'] ) ) {
						$deleted_rows = $wpdb->delete(
							$wpdb->prefix . 'zl_lead_history',
							array( 'freshbooks_client_id' => $lead['freshbooks_client_id'] ),
							array( '%s' )
						);
						if ( $deleted_rows > 0 ) {
							$freed++;
							error_log( 'ZL Sync: Cleared cooldown for deleted Nutshell lead — FreshBooks CID ' . $lead['freshbooks_client_id'] );
						}
					}
				}
			}

			$msg = "Synced {$synced} leads.";
			if ( $freed > 0 ) {
				$msg .= " {$freed} deleted leads freed up for future searches.";
			}

			wp_send_json_success( array( 'synced' => $synced, 'freed' => $freed, 'message' => $msg ) );
		} catch ( \Throwable $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	// ═══════════════════════════════════════════════════════════════════
	// AJAX — Widget summary (for inline dashboard widget)
	// ═══════════════════════════════════════════════════════════════════

	/**
	 * Widget summary endpoint.
	 * Returns lightweight aggregate data for the inline dashboard widget:
	 * total batches, total leads, Nutshell-created count, and recent batches.
	 *
	 * @since 1.3.1
	 */
	public function ajax_widget_summary() {
		check_ajax_referer( 'zl_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'zdz_access_app' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		global $wpdb;

		// v2.1.0 FIX: Use the SAME salesperson resolution as ajax_widget_batches
		// (resolve_salesperson_code → initials fallback). Previously this method
		// scoped only by zdz_salesperson_initials meta, so the stats bar could
		// disagree with the batch list below it for users whose batches were
		// created by an admin. Now both surfaces are scoped identically.
		$ws_uid          = get_current_user_id();
		$can_see_others  = true;
		if ( class_exists( 'TS_Data_Permissions' ) ) {
			$can_see_others = TS_Data_Permissions::can( $ws_uid, 'view_others_data' );
		}
		$is_admin_user = current_user_can( 'manage_options' )
			|| in_array( 'zdz_owner', (array) wp_get_current_user()->roles, true )
			|| in_array( 'zdz_admin', (array) wp_get_current_user()->roles, true );

		// Resolve the scope code (empty = see everything).
		$scope_code = '';
		if ( ! $is_admin_user || ! $can_see_others ) {
			if ( class_exists( 'ZL_Lead_Interaction' ) ) {
				$scope_code = ZL_Lead_Interaction::resolve_salesperson_code( $ws_uid );
			}
			if ( empty( $scope_code ) ) {
				$scope_code = (string) get_user_meta( $ws_uid, 'zdz_salesperson_initials', true );
			}
		}

		// ── v2.5.0 SCOPING (assignment-authoritative) ───────────────────────
		// The SOURCE OF TRUTH for "whose lead is this" is now the per-lead
		// assigned_user_id (Phase 1), NOT the fuzzy batch salesperson code. For a
		// non-admin we scope the leads queries directly by assigned_user_id. We
		// keep the legacy batch-code join ONLY as a fallback for rows that predate
		// assignment and haven't been backfilled (assigned_user_id IS NULL but the
		// batch code matches this rep). This keeps a rep's stats correct during the
		// transition and exact afterward.
		$scoped       = ( ! $is_admin_user || ! $can_see_others );
		$batch_where  = '';   // batch-table WHERE (for the completed-batches stat)
		$lead_join    = '';   // leads-table scoping fragment (joined/where)
		if ( $scoped ) {
			// Leads owned by this user OR (legacy) in a batch with their code and
			// not yet individually assigned. Built as a WHERE-style fragment that
			// every COUNT below already appends after its own first WHERE clause.
			if ( ! empty( $scope_code ) ) {
				$lead_join = $wpdb->prepare(
					" AND ( l.assigned_user_id = %d
					        OR ( l.assigned_user_id IS NULL AND EXISTS (
					              SELECT 1 FROM {$wpdb->prefix}zl_batches b3
					              WHERE b3.id = l.batch_id AND b3.assigned_to = %s ) ) )",
					$ws_uid, $scope_code
				);
				// Batch-list stat: batches assigned to this rep's code (unchanged
				// concept — batches don't carry a per-user owner).
				$batch_where = $wpdb->prepare( " AND b.assigned_to = %s", $scope_code );
			} else {
				// No code on file → scope strictly by direct assignment.
				$lead_join   = $wpdb->prepare( " AND l.assigned_user_id = %d", $ws_uid );
				// No code → the batch-assigned stat can't match; force empty.
				$batch_where = " AND 1=0";
			}
		}

		// NOTE: $lead_join is now a WHERE-fragment (begins with " AND ..."), not a
		// JOIN. The COUNT queries below were written as
		//   "... FROM zl_leads l" . $lead_join . " WHERE <first-clause> ..."
		// so we must ensure $lead_join lands inside the WHERE, not before it. The
		// queries below are adjusted to append $lead_join AFTER their WHERE.

		// 7-day window (server-local time, matching created_at/contacted_at).
		$week_ago = gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) );

		// ── v2.1.0 ACTION-ORIENTED METRICS ──────────────────────────────
		// 1) Ready to Email: has an email + not yet contacted + not skipped.
		//    These are the leads a salesperson can act on RIGHT NOW.
		$ready_to_email = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}zl_leads l" .
			" WHERE l.contact_status = 'pending'
			   AND l.email IS NOT NULL AND l.email != ''" . $lead_join
		);

		// 2) Not Yet Contacted: pending (excludes contacted + skipped).
		$not_contacted = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}zl_leads l" .
			" WHERE l.contact_status = 'pending'" . $lead_join
		);

		// 3) New This Week: leads generated in the last 7 days.
		$new_this_week = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}zl_leads l" .
			" WHERE l.created_at >= %s",
			$week_ago
		) . $lead_join );

		// 4) Contacted This Week: marked contacted in the last 7 days
		//    (progress signal). Falls back to created_at if contacted_at null.
		$contacted_this_week = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}zl_leads l" .
			" WHERE l.contact_status = 'contacted'
			   AND COALESCE(l.contacted_at, l.created_at) >= %s",
			$week_ago
		) . $lead_join );

		// ── Legacy aggregates (kept for back-compat with any other caller) ──
		$total_batches = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}zl_batches b WHERE status = 'complete'" . $batch_where
		);
		$total_leads = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}zl_leads l WHERE 1=1" . $lead_join
		);
		$in_nutshell = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}zl_leads l" .
			" WHERE l.nutshell_lead_id IS NOT NULL AND l.nutshell_lead_id != ''" . $lead_join
		);
		$contacted = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}zl_leads l" .
			" WHERE l.contact_status = 'contacted'" . $lead_join
		);

		// Recent batches (last 5)
		$recent = $wpdb->get_results(
			"SELECT id, batch_tag, status, total_leads, assigned_to, created_at
			 FROM {$wpdb->prefix}zl_batches b
			 WHERE 1=1" . $batch_where . "
			 ORDER BY created_at DESC
			 LIMIT 5",
			ARRAY_A
		);

		wp_send_json_success( array(
			// v2.1.0 metrics (consumed by the widget stat tiles)
			'ready_to_email'      => $ready_to_email,
			'not_contacted'       => $not_contacted,
			'new_this_week'       => $new_this_week,
			'contacted_this_week' => $contacted_this_week,
			// legacy fields (back-compat)
			'total_batches'       => $total_batches,
			'total_leads'         => $total_leads,
			'in_nutshell'         => $in_nutshell,
			'contacted'           => $contacted,
			'recent_batches'      => $recent ?: array(),
		) );
	}

	/**
	 * Widget batch history endpoint.
	 * Returns paginated batch data with lead counts, filter info, and
	 * permission-aware field access for the full-parity widget.
	 *
	 * @since 1.5.0
	 */
	public function ajax_widget_batches() {
		check_ajax_referer( 'zl_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'zdz_access_app' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$page     = max( 1, (int) ( $_POST['page'] ?? 1 ) );
		$per_page = 20;
		$offset   = ( $page - 1 ) * $per_page;

		global $wpdb;

		// v2.0.0 FIX (BUG-3): Scope batches by salesperson code resolved from
		// the user's profile, not just zdz_salesperson_initials meta. The old
		// approach failed when batches were created by an admin — the admin's
		// initials didn't match the batch's assigned_to field.
		$batch_scope  = '';
		$can_see_others  = true;
		$can_see_revenue = true;
		$wb_uid = get_current_user_id();

		// Check theme-level data permissions
		if ( class_exists( 'TS_Data_Permissions' ) ) {
			$can_see_others  = TS_Data_Permissions::can( $wb_uid, 'view_others_data' );
			$can_see_revenue = TS_Data_Permissions::can( $wb_uid, 'view_company_revenue' );
		}

		// Non-admin users see only batches assigned to their salesperson code
		$is_admin_user = current_user_can( 'manage_options' )
			|| in_array( 'zdz_owner', (array) wp_get_current_user()->roles, true )
			|| in_array( 'zdz_admin', (array) wp_get_current_user()->roles, true );

		if ( ! $is_admin_user || ! $can_see_others ) {
			$sp_code = '';
			if ( class_exists( 'ZL_Lead_Interaction' ) ) {
				$sp_code = ZL_Lead_Interaction::resolve_salesperson_code( $wb_uid );
			}
			if ( ! empty( $sp_code ) ) {
				$batch_scope = $wpdb->prepare( " WHERE b.assigned_to = %s", $sp_code );
			} else {
				// Fallback: try initials meta
				$user_initials = get_user_meta( $wb_uid, 'zdz_salesperson_initials', true );
				if ( ! empty( $user_initials ) ) {
					$batch_scope = $wpdb->prepare( " WHERE b.assigned_to = %s", $user_initials );
				}
			}
		}

		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}zl_batches b" . ( $batch_scope ? $batch_scope : '' )
		);

		// Fetch salespeople for label resolution
		$salespeople = json_decode( get_option( 'zl_salespeople', '[]' ), true );
		if ( ! is_array( $salespeople ) || empty( $salespeople ) ) {
			$salespeople = zl_salespeople();
		}

		$batches = $wpdb->get_results( $wpdb->prepare(
			"SELECT b.*,
			        (SELECT COUNT(*) FROM {$wpdb->prefix}zl_leads l WHERE l.batch_id = b.id) AS lead_count,
			        (SELECT COUNT(*) FROM {$wpdb->prefix}zl_leads l WHERE l.batch_id = b.id AND l.contact_status = 'contacted') AS contacted_count
			 FROM {$wpdb->prefix}zl_batches b"
			. ( $batch_scope ? $batch_scope : '' ) . "
			 ORDER BY b.created_at DESC
			 LIMIT %d OFFSET %d",
			$per_page,
			$offset
		), ARRAY_A );

		// Resolve salesperson labels and include filter metadata
		$result = array();
		foreach ( $batches as $b ) {
			$sp_label = $b['assigned_to'] ?: '—';
			foreach ( $salespeople as $sp ) {
				if ( $sp['code'] === $b['assigned_to'] ) {
					$sp_label = $sp['name'] . ' (' . $sp['code'] . ')';
					break;
				}
			}

			// Retrieve batch options for filter display
			$batch_opts = get_transient( "zl_batch_{$b['id']}_options" ) ?: array();

			$row = array(
				'id'                => (int) $b['id'],
				'batch_tag'         => $b['batch_tag'],
				'status'            => $b['status'],
				'is_test'           => (int) $b['is_test'],
				'assigned_to'       => $b['assigned_to'],
				'assigned_label'    => $sp_label,
				'total_leads'       => (int) $b['lead_count'],
				'contacted_count'   => (int) $b['contacted_count'],
				'product_filter'    => $b['product_filter'] ?: '',
				'city_zip_filter'   => $b['city_zip_filter'] ?: ( $batch_opts['city_zip_filter'] ?? '' ),
				'demographic_filter' => $b['demographic_filter'] ?: ( $batch_opts['demographic_filter'] ?? '' ),
				'ai_summary'        => $b['ai_summary'] ?: '',
				'created_at'        => $b['created_at'],
			);

			// v2.17.0 (5C): Only include spend fields when user can see revenue.
			if ( $can_see_revenue ) {
				$row['spend_min'] = (float) ( $b['spend_min'] ?: 0 );
				$row['spend_max'] = (float) ( $b['spend_max'] ?: 0 );
			}

			$result[] = $row;
		}

		wp_send_json_success( array(
			'batches'    => $result,
			'total'      => $total,
			'page'       => $page,
			'per_page'   => $per_page,
			'total_pages' => ceil( $total / $per_page ),
		) );
	}

	/**
	 * Save permission configuration. Admin-only.
	 *
	 * @since 1.5.0
	 */
	public function ajax_save_permissions() {
		check_ajax_referer( 'zl_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Only administrators can manage permissions.' );
		}

		$config_json = isset( $_POST['config'] ) ? wp_unslash( $_POST['config'] ) : '';
		$config      = json_decode( $config_json, true );

		if ( ! is_array( $config ) || ! isset( $config['roles'] ) ) {
			wp_send_json_error( 'Invalid permission configuration.' );
		}

		// Validate structure
		if ( ! isset( $config['users'] ) || ! is_array( $config['users'] ) ) {
			$config['users'] = array();
		}

		// Validate feature keys
		$valid_keys = ZL_Permissions::get_all_feature_keys();
		foreach ( $config['roles'] as $role => $features ) {
			if ( ! is_array( $features ) ) {
				$config['roles'][ $role ] = array();
				continue;
			}
			// Filter to valid keys (plus 'all')
			$config['roles'][ $role ] = array_values( array_filter( $features, function( $f ) use ( $valid_keys ) {
				return $f === 'all' || in_array( $f, $valid_keys, true );
			} ) );
		}

		foreach ( $config['users'] as $username => $features ) {
			if ( ! is_array( $features ) ) {
				unset( $config['users'][ $username ] );
				continue;
			}
			// Filter to valid keys (with optional ! prefix)
			$config['users'][ $username ] = array_values( array_filter( $features, function( $f ) use ( $valid_keys ) {
				$clean = ltrim( $f, '!' );
				return in_array( $clean, $valid_keys, true );
			} ) );
			// Remove empty arrays
			if ( empty( $config['users'][ $username ] ) ) {
				unset( $config['users'][ $username ] );
			}
		}

		ZL_Permissions::save_config( $config );

		wp_send_json_success( array( 'saved' => true ) );
	}

	/**
	 * Get current permission configuration. Admin-only.
	 *
	 * @since 1.5.0
	 */
	public function ajax_get_permissions() {
		check_ajax_referer( 'zl_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Only administrators can view permissions.' );
		}

		$config      = ZL_Permissions::get_config();
		$definitions = ZL_Permissions::get_feature_definitions();

		wp_send_json_success( array(
			'config'      => $config,
			'definitions' => $definitions,
		) );
	}

	// ═══════════════════════════════════════════════════════════════════
	// v1.7.0 — Background Generation (flush-and-continue)
	// ═══════════════════════════════════════════════════════════════════

	/**
	 * Flush the JSON response to the client and continue PHP execution.
	 *
	 * Uses fastcgi_finish_request() on Nginx/php-fpm, litespeed_finish_request()
	 * on LiteSpeed, and a manual ob_end_flush()+flush() fallback on Apache.
	 * This is the same pattern used by the analytics module.
	 *
	 * @since 1.7.0
	 * @param array $data  Data to send as the success response.
	 */
	private function flush_json_and_continue( $data ) {
		ignore_user_abort( true );

		// IMPORTANT: Do NOT use wp_send_json_success() here — it calls wp_die()
		// which terminates PHP execution. We must manually output the JSON so
		// the script can continue running the background pipeline afterwards.
		$json = wp_json_encode( array( 'success' => true, 'data' => $data ) );

		// Discard ALL existing output buffers (PHP notices, plugin output, etc.)
		// Using ob_end_clean() — NOT ob_end_flush() — to prevent stale content
		// from being prepended to our JSON response and breaking parsing.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		if ( ! headers_sent() ) {
			header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
			header( 'Connection: close' );
			header( 'Content-Length: ' . strlen( $json ) );
		}

		echo $json;
		flush();

		// Detach from the client — PHP continues executing afterwards.
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
		// Apache fallback: headers already sent with Connection: close +
		// Content-Length, so the browser knows the response is complete.

		// Give the background pipeline generous execution time.
		@set_time_limit( 900 );
		if ( ! defined( 'DOING_CRON' ) ) {
			define( 'DOING_CRON', true );
		}
	}

	/**
	 * Update the batch progress transient for polling.
	 *
	 * v1.8.0: Also mirrors into ZL_Progress so the new frontend watchdog
	 * (heartbeat-aware) picks up the update. Old transient key is kept
	 * for backward compatibility with in-flight widget sessions.
	 *
	 * @since 1.7.0
	 * @param int    $batch_id Batch ID.
	 * @param string $step     Current pipeline step label.
	 * @param int    $pct      Progress percentage (0-100).
	 * @param string $message  Human-readable status message.
	 * @param string $status   One of 'running', 'complete', 'error'.
	 */
	private function update_batch_progress( $batch_id, $step, $pct, $message, $status = 'running' ) {
		set_transient( "zl_batch_progress_{$batch_id}", array(
			'batch_id' => $batch_id,
			'step'     => $step,
			'pct'      => max( 0, min( 100, (int) $pct ) ),
			'message'  => $message,
			'status'   => $status,
			'updated'  => time(),
		), 3600 ); // 1 hour TTL (was 30 min — too short for large batches)

		// v2.0.0: Touch updated_at on the batch row for stale detection cron
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'zl_batches',
			array( 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => $batch_id ),
			array( '%s' ),
			array( '%d' )
		);

		// v1.8.0 — mirror into ZL_Progress (heartbeat-aware). This is
		// additive; old widget polls still see the above transient.
		if ( class_exists( 'ZL_Progress' ) ) {
			$existing = ZL_Progress::get( $batch_id );
			if ( ! is_array( $existing ) ) {
				// First call — bootstrap the tracker.
				ZL_Progress::start( $batch_id, $message );
			}
			if ( $status === 'complete' ) {
				ZL_Progress::complete( $batch_id );
			} elseif ( $status === 'error' ) {
				ZL_Progress::fail( $batch_id, $message );
			} else {
				// Running — update stage and heartbeat.
				ZL_Progress::stage( $batch_id, $message, $step, 0 );
			}
		}
	}

	/**
	 * AJAX: Start background generation.
	 *
	 * Creates the batch, immediately flushes the batch_id to the client,
	 * then runs the full 8-step pipeline server-side.
	 *
	 * @since 1.7.0
	 */
	public function ajax_start_background() {
		check_ajax_referer( 'zl_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'zdz_access_app' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$is_test  = (int) ( $_POST['is_test'] ?? 0 );
		$sp_code  = sanitize_text_field( $_POST['salesperson'] ?? '' );

		$filters = array(
			'salesperson'        => $sp_code,
			'lookback_days'      => (int) ( $_POST['lookback_days'] ?? 0 ),
			'product_filter'     => sanitize_text_field( $_POST['product_filter'] ?? '' ),
			'city_zip_filter'    => sanitize_text_field( $_POST['city_zip_filter'] ?? '' ),
			'spend_min'          => (float) ( $_POST['spend_min'] ?? 0 ),
			'spend_max'          => (float) ( $_POST['spend_max'] ?? 0 ),
			'demographic_filter' => sanitize_text_field( $_POST['demographic_filter'] ?? 'both' ),
			'is_test'            => $is_test,
		);

		// Session lock
		$user_id  = get_current_user_id();
		$lock_key = 'zl_batch_lock_' . $user_id;
		$existing = get_transient( $lock_key );
		if ( $existing ) {
			wp_send_json_error( 'A generation is already running (batch #' . $existing . '). Please wait for it to finish.' );
			return;
		}

		// Build batch tag
		$salespeople = json_decode( get_option( 'zl_salespeople', '[]' ), true ) ?: zl_salespeople();
		$sp_name     = $sp_code;
		foreach ( $salespeople as $sp ) {
			if ( $sp['code'] === $sp_code ) {
				$sp_name = $sp['name'];
				break;
			}
		}
		$date_str  = current_time( 'M j' );
		$type_str  = $is_test ? 'TEST' : 'FULL';
		$batch_tag = "{$type_str} — {$sp_name} — {$date_str}";

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'zl_batches',
			array(
				'batch_tag'          => $batch_tag,
				'status'             => 'running',
				'is_test'            => $is_test,
				'assigned_to'        => $sp_code,
				'product_filter'     => $filters['product_filter'],
				'city_zip_filter'    => $filters['city_zip_filter'],
				'demographic_filter' => $filters['demographic_filter'],
				'spend_min'          => $filters['spend_min'],
				'spend_max'          => $filters['spend_max'],
				'created_at'         => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%f', '%f', '%s' )
		);

		$batch_id = $wpdb->insert_id;
		if ( ! $batch_id ) {
			wp_send_json_error( 'Failed to create batch record.' );
			return;
		}

		$lookback = $filters['lookback_days'] > 0 ? $filters['lookback_days'] : (int) get_option( 'zl_lookback_days', 730 );
		set_transient( "zl_batch_{$batch_id}_options", array(
			'is_test'            => $is_test,
			'salesperson'        => $sp_code,
			'lookback_days'      => $lookback,
			'product_filter'     => $filters['product_filter'],
			'city_zip_filter'    => $filters['city_zip_filter'],
			'spend_min'          => $filters['spend_min'],
			'spend_max'          => $filters['spend_max'],
			'demographic_filter' => $filters['demographic_filter'],
		), ZL_TRANSIENT_TTL );

		set_transient( $lock_key, $batch_id, 600 );

		$this->update_batch_progress( $batch_id, 'starting', 2, 'Batch created. Starting background pipeline...', 'running' );

		// Flush response to client immediately — pipeline runs after
		$this->flush_json_and_continue( array(
			'batch_id'   => $batch_id,
			'batch_tag'  => $batch_tag,
			'background' => true,
		) );

		// ── Background pipeline ──
		$this->run_background_pipeline( $batch_id, $filters, $is_test );
	}

	/**
	 * Run the full 8-step pipeline server-side (after flush).
	 *
	 * @since 1.7.0
	 */
	private function run_background_pipeline( $batch_id, $filters, $is_test ) {
		global $wpdb;
		$user_id  = get_current_user_id();
		$lock_key = 'zl_batch_lock_' . $user_id;

		try {
			// ── Step 2: Fetch Invoices ──
			// v2.0.0: Reset execution timer before EACH major step.
			// WP Engine's PHP-FPM may kill processes after ~120-300s regardless
			// of set_time_limit. Resetting per-step gives each one a fresh budget.
			@set_time_limit( 300 );
			$this->update_batch_progress( $batch_id, 'fetch_invoices', 5, 'Fetching invoices from FreshBooks...' );

			$gen = new ZL_Lead_Generator();
			$gen->init_clients();

			$batch_options = get_transient( "zl_batch_{$batch_id}_options" ) ?: array();
			$lookback_days = $batch_options['lookback_days'] ?? 730;
			$sp_code       = $batch_options['salesperson'] ?? '';

			// v2.0.0: Normalize salesperson code — 'all' → '_ALL_' for cross-territory batches
			if ( empty( $sp_code ) || $sp_code === 'all' || $sp_code === 'All Salespeople' ) {
				$sp_code = '_ALL_';
			}

			$page         = 1;
			$total_pages  = 1;
			$all_invoices = array();
			do {
				@set_time_limit( 120 ); // Reset per-page too (50+ pages × 1.5s each = 80s+)
				$result = $gen->fetch_invoices_page( $lookback_days, $page );
				if ( ! $result || empty( $result['invoices'] ) ) {
					error_log( "ZL Background: Page {$page} returned 0 invoices (lookback={$lookback_days}d)." );
					break;
				}
				$all_invoices = array_merge( $all_invoices, $result['invoices'] );
				$total_pages  = max( $total_pages, (int) ( $result['total_pages'] ?? 1 ) );
				$page++;

				$pct_fetch = 5 + (int) ( ( $page / max( 1, $total_pages ) ) * 10 );
				$this->update_batch_progress( $batch_id, 'fetch_invoices', min( 15, $pct_fetch ),
					"Fetching invoices (page {$page} of {$total_pages})..." );
			} while ( $page <= $total_pages );

			error_log( "ZL Background: Fetched " . count( $all_invoices ) . " invoices across {$total_pages} pages (lookback={$lookback_days}d)." );

			$grouped = array();
			foreach ( $all_invoices as $inv ) {
				$cid = $inv['customerid'] ?? ( $inv['customer_id'] ?? '' );
				if ( ! empty( $cid ) ) {
					$grouped[ $cid ][] = $inv;
				}
			}
			$customer_ids    = array_keys( $grouped );
			$total_customers = count( $customer_ids );

			$this->set_compressed_transient( "zl_batch_{$batch_id}_customers", $grouped, ZL_TRANSIENT_TTL );

			// v2.0.0: Also save chunked transients for AJAX resume compatibility
			$total_invoices_count = count( $all_invoices ); // save before we free $all_invoices later
			$bg_chunk_size  = 200;
			$bg_chunk_count = (int) ceil( $total_customers / $bg_chunk_size );
			for ( $bci = 0; $bci < $bg_chunk_count; $bci++ ) {
				$bc_ids  = array_slice( $customer_ids, $bci * $bg_chunk_size, $bg_chunk_size );
				$bc_data = array();
				foreach ( $bc_ids as $bc_id ) {
					if ( isset( $grouped[ $bc_id ] ) ) { $bc_data[ $bc_id ] = $grouped[ $bc_id ]; }
				}
				$this->set_compressed_transient( "zl_batch_{$batch_id}_cchunk_{$bci}", $bc_data, ZL_TRANSIENT_TTL );
			}
			set_transient( "zl_batch_{$batch_id}_cmeta", array(
				'total' => $total_customers, 'chunk_size' => $bg_chunk_size,
				'chunk_count' => $bg_chunk_count, 'ids' => $customer_ids,
			), ZL_TRANSIENT_TTL );

			// v2.0.0: FREE the massive $grouped array now that chunk transients are saved.
			// $grouped holds ~25-40MB for large lookbacks (5133 invoices / 3766 customers).
			// Keeping it alive during the enrichment loop pushed PHP past 256MB.
			// The enrichment loop below now reads from chunk transients instead.
			// NOTE: $all_invoices is kept alive for Step 2b filter expansion below,
			// then freed after that step completes.
			unset( $grouped );

			$inv_count = $total_invoices_count; // saved before unset
			if ( 0 === $inv_count ) {
				$this->update_batch_progress( $batch_id, 'error', 0,
					"No invoices found (lookback={$lookback_days}d). Check FreshBooks connection in Settings.", 'error' );
				$wpdb->update( $wpdb->prefix . 'zl_batches',
					array( 'status' => 'failed' ), array( 'id' => $batch_id ), array( '%s' ), array( '%d' ) );
				delete_transient( $lock_key );
				return;
			}

			$this->update_batch_progress( $batch_id, 'fetch_invoices', 15,
				"Fetched {$inv_count} invoices across {$total_customers} customers." );

			// ── Step 2b: Expand Filter ──
			$product_filter  = $batch_options['product_filter'] ?? '';
			$expanded_filter = '';
			if ( ! empty( $product_filter ) && $gen->has_ai() ) {
				$this->update_batch_progress( $batch_id, 'expand_filter', 18,
					"AI expanding filter \"{$product_filter}\"..." );
				try {
					$unique_names = array();
					foreach ( $all_invoices as $inv ) {
						foreach ( ( $inv['lines'] ?? array() ) as $line ) {
							$name = $line['name'] ?? ( $line['description'] ?? '' );
							if ( ! empty( $name ) ) {
								$unique_names[ strtolower( trim( $name ) ) ] = true;
							}
						}
					}
					// CRITICAL: arg order must be (names_array, filter_string) — matches legacy pipeline (line 648)
					$expanded_filter = $gen->ai_expand_product_filter_from_names( array_values( array_keys( $unique_names ) ), $product_filter );
					if ( ! empty( $expanded_filter ) ) {
						set_transient( "zl_batch_{$batch_id}_expanded_filter", $expanded_filter, ZL_TRANSIENT_TTL );
						$mn_count = count( $expanded_filter['matched_names'] ?? array() );
						$kw_count = count( $expanded_filter['keywords'] ?? array() );
						error_log( "ZL Background: AI expanded \"{$product_filter}\" → {$mn_count} matched names, {$kw_count} keywords" );
					} else {
						error_log( 'ZL Background: AI filter expansion returned empty for "' . $product_filter . '"' );
					}
				} catch ( \Throwable $e ) {
					error_log( 'ZL Background: Filter expansion error: ' . $e->getMessage() );
				}
				$mn = is_array( $expanded_filter ) ? count( $expanded_filter['matched_names'] ?? array() ) : 0;
				$kw = is_array( $expanded_filter ) ? count( $expanded_filter['keywords'] ?? array() ) : 0;
				$this->update_batch_progress( $batch_id, 'expand_filter', 20,
					"AI found {$mn} product matches + {$kw} keywords from " . count( $unique_names ) . ' line items.' );
			}

			// v2.0.0: Free $all_invoices now that filter expansion is done (~10-15MB)
			unset( $all_invoices, $unique_names );

			// ══════════════════════════════════════════════════════════════════
			// v2.0.0: CRON RELAY ENRICHMENT
			// ══════════════════════════════════════════════════════════════════
			// WP Engine's PHP-FPM kills background processes at ~120s wall-clock
			// regardless of set_time_limit(). The previous inline enrichment loop
			// (3766 customers × 25/chunk = 150 iterations) ran as a single PHP
			// process that invariably died after chunk 1.
			//
			// Fix: each enrichment chunk is dispatched as a separate self-spawning
			// loopback HTTP request via wp_remote_post(). Each request gets its
			// own ~120s execution budget. The chain:
			//
			//   bg_start_generation → zl_bg_enrich_chunk (offset=0) →
			//   zl_bg_enrich_chunk (offset=25) → ... →
			//   zl_bg_enrich_chunk (final) → zl_bg_finalize
			//
			// State between requests is persisted in transients:
			//   _candidates: accumulated enrichment candidates
			//   _enrich_state: offset, processed count, config
			//   _cmeta + _cchunk_*: customer invoice data (already saved)
			//   _options + _expanded_filter: batch config (already saved)
			// ══════════════════════════════════════════════════════════════════

			$leads_per_batch = (int) get_option( 'zl_leads_per_batch', 50 );
			$has_pf          = ! empty( $product_filter );
			$early_stop      = $is_test
				? ( $has_pf ? 30 : 10 )
				: ( $leads_per_batch * ( $has_pf ? 5 : 3 ) );

			$sp_territories = array();
			if ( ! empty( $sp_code ) && $sp_code !== '_ALL_' ) {
				$salespeople = json_decode( get_option( 'zl_salespeople', '[]' ), true ) ?: zl_salespeople();
				foreach ( $salespeople as $sp_item ) {
					if ( strtoupper( $sp_item['code'] ) === strtoupper( $sp_code ) ) {
						$sp_territories = array_filter( array_map( 'trim', explode( ',', strtoupper( $sp_item['territories'] ) ) ) );
						break;
					}
				}
			}

			// Save enrichment state for the relay chain
			set_transient( "zl_batch_{$batch_id}_enrich_state", array(
				'offset'          => 0,
				'processed'       => 0,
				'total_customers' => $total_customers,
				'chunk_size'      => 25,
				'bg_chunk_size'   => $bg_chunk_size,
				'early_stop'      => $early_stop,
				'sp_code'         => $sp_code,
				'sp_territories'  => $sp_territories,
				'inv_count'       => $total_invoices_count,
				'product_filter'  => $product_filter,
				'is_test'         => $is_test,
				'customer_ids'    => $customer_ids,
				'lock_key'        => $lock_key,
			), ZL_TRANSIENT_TTL );

			// Initialize empty candidates transient
			$this->set_compressed_transient( "zl_batch_{$batch_id}_candidates", array(), ZL_TRANSIENT_TTL );

			$this->update_batch_progress( $batch_id, 'enrich', 20,
				"Enriching {$total_customers} customers" . ( $has_pf ? " (filter: \"{$product_filter}\")" : '' ) . '...' );

			// Dispatch first enrichment chunk via loopback
			error_log( "ZL Background: Dispatching enrichment relay for batch #{$batch_id} ({$total_customers} customers, early_stop={$early_stop})" );
			$this->dispatch_enrich_chunk( $batch_id );

			// This background process is done — the relay chain continues in separate requests.
			return;

		} catch ( \Throwable $e ) {
			error_log( 'ZL Background: Pipeline fatal error: ' . $e->getMessage() );
			$this->update_batch_progress( $batch_id, 'error', 0,
				'Pipeline error: ' . $e->getMessage(), 'error' );
			global $wpdb;
			$wpdb->update( $wpdb->prefix . 'zl_batches',
				array( 'status' => 'failed' ), array( 'id' => $batch_id ), array( '%s' ), array( '%d' ) );
			if ( isset( $lock_key ) ) { delete_transient( $lock_key ); }
		}
		// NOTE: Transient cleanup is NOT done here — the relay chain still needs
		// _cmeta, _cchunk_*, _options, _expanded_filter, _candidates, _enrich_state.
		// Cleanup happens in ajax_bg_finalize() after Steps 4-7 complete.
	}

	// ═══════════════════════════════════════════════════════════════════
	// v2.0.0: CRON RELAY — Self-spawning enrichment chain
	// ═══════════════════════════════════════════════════════════════════

	/**
	 * Dispatch a non-blocking loopback request to process the next enrichment chunk.
	 *
	 * Uses wp_remote_post with blocking=false so the current request returns
	 * immediately while the next chunk starts processing in a fresh PHP process.
	 *
	 * @param int    $batch_id
	 * @param string $action   AJAX action to call (default: zl_bg_enrich_chunk)
	 */
	private function dispatch_enrich_chunk( $batch_id, $action = 'zl_bg_enrich_chunk' ) {
		// Generate or reuse a batch-specific relay token (not a WP nonce, which
		// is user-tied and fails on cookieless loopback requests).
		$token_key = "zl_batch_{$batch_id}_relay_token";
		$token     = get_transient( $token_key );
		if ( ! $token ) {
			$token = wp_generate_password( 32, false );
			set_transient( $token_key, $token, ZL_TRANSIENT_TTL );
		}

		$url = admin_url( 'admin-ajax.php' );
		wp_remote_post( $url, array(
			'timeout'   => 1,
			'blocking'  => false,
			'sslverify' => false,
			'body'      => array(
				'action'      => $action,
				'batch_id'    => $batch_id,
				'relay_token' => $token,
			),
		) );
	}

	/**
	 * AJAX: Process one enrichment chunk (25 customers) and dispatch the next.
	 *
	 * This handler is called via non-blocking loopback from dispatch_enrich_chunk().
	 * Each invocation is a fresh HTTP request with its own ~120s execution budget,
	 * solving the WP Engine process kill issue.
	 *
	 * State is persisted in transients between invocations:
	 *   _enrich_state: offset, processed count, config
	 *   _candidates: accumulated enrichment candidates
	 *   _cmeta + _cchunk_*: customer invoice data
	 *   _options + _expanded_filter: batch config
	 *
	 * @since 2.0.0
	 */
	public function ajax_bg_enrich_chunk() {
		// Verify relay token (not a WP nonce — those fail on cookieless loopback)
		$batch_id = (int) ( $_POST['batch_id'] ?? 0 );
		if ( ! $batch_id ) { wp_die( 'No batch_id' ); }

		$token     = $_POST['relay_token'] ?? '';
		$token_key = "zl_batch_{$batch_id}_relay_token";
		$expected  = get_transient( $token_key );
		if ( empty( $token ) || $token !== $expected ) {
			error_log( "ZL Relay: Invalid relay token for batch #{$batch_id}" );
			wp_die( 'Invalid relay token' );
		}

		@set_time_limit( 120 );
		ignore_user_abort( true );

		global $wpdb;

		// Load enrichment state
		$state = get_transient( "zl_batch_{$batch_id}_enrich_state" );
		if ( ! is_array( $state ) ) {
			error_log( "ZL Relay: No enrich_state transient for batch #{$batch_id} — aborting." );
			wp_die();
		}

		$offset          = (int) $state['offset'];
		$processed       = (int) $state['processed'];
		$total_customers = (int) $state['total_customers'];
		$chunk_size      = (int) $state['chunk_size'];
		$bg_chunk_size   = (int) $state['bg_chunk_size'];
		$early_stop      = (int) $state['early_stop'];
		$sp_code         = $state['sp_code'] ?? '';
		$sp_territories  = $state['sp_territories'] ?? array();
		$product_filter  = $state['product_filter'] ?? '';
		$is_test         = (int) ( $state['is_test'] ?? 0 );
		$customer_ids    = $state['customer_ids'] ?? array();
		$lock_key        = $state['lock_key'] ?? '';

		// Load accumulated candidates
		$candidates = $this->get_compressed_transient( "zl_batch_{$batch_id}_candidates" );
		if ( ! is_array( $candidates ) ) { $candidates = array(); }

		// Check if we're done
		if ( $offset >= $total_customers || count( $candidates ) >= $early_stop ) {
			error_log( "ZL Relay: Enrichment complete for batch #{$batch_id} — {$processed}/{$total_customers} processed, " . count( $candidates ) . ' candidates. Dispatching finalize.' );

			// Save final candidates and dispatch finalize
			$this->set_compressed_transient( "zl_batch_{$batch_id}_candidates", $candidates, ZL_TRANSIENT_TTL );
			$this->dispatch_enrich_chunk( $batch_id, 'zl_bg_finalize' );
			wp_die();
		}

		$chunk_num = (int) floor( $offset / $chunk_size ) + 1;
		error_log( "ZL Relay: Chunk {$chunk_num} starting (offset={$offset}, processed={$processed}/{$total_customers}, candidates=" . count( $candidates ) . ')' );

		// Load batch options
		$batch_options   = get_transient( "zl_batch_{$batch_id}_options" ) ?: array();
		$expanded_filter = ! empty( $product_filter ) ? ( get_transient( "zl_batch_{$batch_id}_expanded_filter" ) ?: null ) : null;

		// Initialize the lead generator
		$gen = new ZL_Lead_Generator();
		$gen->init_clients();
		if ( method_exists( $gen, 'clear_batch_caches' ) ) {
			$gen->clear_batch_caches();
		}

		// Get the slice of customer IDs for this chunk
		$slice = array_slice( $customer_ids, $offset, $chunk_size );
		$chunk_invoices = array();

		// Load customer data from chunk transients
		$cmeta = get_transient( "zl_batch_{$batch_id}_cmeta" );
		foreach ( $slice as $cid ) {
			$global_pos = array_search( $cid, $customer_ids, true );
			if ( $global_pos === false ) { continue; }
			$ci = (int) floor( $global_pos / $bg_chunk_size );
			// Load chunk transient (we load fresh each time — no caching across requests)
			$cchunk = $this->get_compressed_transient( "zl_batch_{$batch_id}_cchunk_{$ci}" );
			if ( is_array( $cchunk ) && isset( $cchunk[ $cid ] ) ) {
				$chunk_invoices[ $cid ] = $cchunk[ $cid ];
			}
		}
		unset( $cchunk );

		// Heartbeat
		if ( class_exists( 'ZL_Progress' ) ) {
			ZL_Progress::heartbeat( $batch_id );
		}

		// Nutshell bulk pre-fetch for this chunk
		if ( method_exists( $gen, 'prime_nutshell_cache_from_emails' ) ) {
			$chunk_emails = array();
			foreach ( $slice as $cid ) {
				$fb_client = $gen->get_fb_client_cached( $cid );
				if ( is_array( $fb_client ) && ! empty( $fb_client['email'] ) ) {
					$chunk_emails[] = trim( (string) $fb_client['email'] );
				}
			}
			if ( ! empty( $chunk_emails ) ) {
				try {
					// v2.1.0: tunable concurrency (default ZL_NUTSHELL_PARALLEL=10).
					$ns_cap = (int) apply_filters( 'zlg_nutshell_parallel',
						defined( 'ZL_NUTSHELL_PARALLEL' ) ? ZL_NUTSHELL_PARALLEL : 8 );
					$gen->prime_nutshell_cache_from_emails( $chunk_emails, max( 1, $ns_cap ) );
				} catch ( \Throwable $e ) {
					error_log( 'ZL Relay: Nutshell pre-fetch failed (serial fallback): ' . $e->getMessage() );
				}
			}
		}

		// Process each customer in the chunk
		foreach ( $slice as $cid ) {
			$processed++;
			try {
				$invoices = $chunk_invoices[ $cid ] ?? array();

				if ( $gen->is_within_cooldown( $cid ) ) { continue; }

				if ( ! empty( $product_filter ) ) {
					$filter_data  = $expanded_filter ?: $product_filter;
					$raw_purchase = $gen->parse_purchase_history( $invoices );
					$temp = array(
						'purchase_summary'   => $raw_purchase['summary'],
						'purchase_history'   => wp_json_encode( $raw_purchase['items'] ),
						'nutshell_interests' => '',
					);
					if ( ! $gen->matches_product_filter( $temp, $filter_data ) ) { continue; }
				}

				$fail_reason = null;
				$candidate   = $gen->enrich_customer( $cid, $invoices, $fail_reason );
				if ( ! $candidate ) { continue; }

				if ( ! empty( $sp_territories ) ) {
					$ct = strtoupper( trim( $candidate['territory'] ?? '' ) );
					if ( empty( $ct ) || ! in_array( $ct, $sp_territories, true ) ) { continue; }
				}

				$is_dup   = false;
				$cand_cid = $candidate['customer_id'] ?? $cid;
				foreach ( $candidates as $existing ) {
					if ( ( $existing['customer_id'] ?? '' ) === $cand_cid ) { $is_dup = true; break; }
				}
				if ( ! $is_dup ) { $candidates[] = $candidate; }
			} catch ( \Throwable $e ) {
				error_log( 'ZL Relay: Enrich error for ' . $cid . ': ' . $e->getMessage() );
			}
		}

		// Post-chunk filters
		$city_zip = $batch_options['city_zip_filter'] ?? '';
		if ( ! empty( $city_zip ) ) { $candidates = $gen->filter_by_city_zip( $candidates, $city_zip ); }
		$s_min = (float) ( $batch_options['spend_min'] ?? 0 );
		$s_max = (float) ( $batch_options['spend_max'] ?? 0 );
		if ( $s_min > 0 || $s_max > 0 ) { $candidates = $gen->filter_by_spend( $candidates, $s_min, $s_max ); }

		// Update progress
		$pct = 20 + (int) ( ( $processed / max( 1, $total_customers ) ) * 25 );
		$this->update_batch_progress( $batch_id, 'enrich', min( 45, $pct ),
			"Enriched {$processed}/{$total_customers} (" . count( $candidates ) . ' candidates)...' );

		error_log( "ZL Relay: Chunk {$chunk_num} done — processed={$processed}/{$total_customers}, candidates=" . count( $candidates ) . ', mem=' . round( memory_get_usage() / 1048576, 1 ) . 'MB' );

		// Save state for next chunk
		$state['offset']    = $offset + $chunk_size;
		$state['processed'] = $processed;
		set_transient( "zl_batch_{$batch_id}_enrich_state", $state, ZL_TRANSIENT_TTL );
		$this->set_compressed_transient( "zl_batch_{$batch_id}_candidates", $candidates, ZL_TRANSIENT_TTL );

		// Dispatch next chunk
		$this->dispatch_enrich_chunk( $batch_id );
		wp_die();
	}

	/**
	 * AJAX: Finalize batch — Steps 4-7 (Score, AI Validate, AI Refine, Nutshell, Summary).
	 *
	 * Called via non-blocking loopback after the enrichment relay chain completes.
	 * Runs as a single request since Steps 4-7 are typically fast (< 60s total).
	 *
	 * @since 2.0.0
	 */
	public function ajax_bg_finalize() {
		$batch_id = (int) ( $_POST['batch_id'] ?? 0 );
		if ( ! $batch_id ) { wp_die( 'No batch_id' ); }

		$token     = $_POST['relay_token'] ?? '';
		$token_key = "zl_batch_{$batch_id}_relay_token";
		$expected  = get_transient( $token_key );
		if ( empty( $token ) || $token !== $expected ) {
			error_log( "ZL Relay: Invalid relay token for finalize batch #{$batch_id}" );
			wp_die( 'Invalid relay token' );
		}

		@set_time_limit( 300 );
		ignore_user_abort( true );

		$batch_id = (int) ( $_POST['batch_id'] ?? 0 );
		if ( ! $batch_id ) { wp_die( 'No batch_id' ); }

		global $wpdb;
		error_log( "ZL Relay: Finalize starting for batch #{$batch_id}" );

		$state      = get_transient( "zl_batch_{$batch_id}_enrich_state" );
		$candidates = $this->get_compressed_transient( "zl_batch_{$batch_id}_candidates" );
		if ( ! is_array( $candidates ) ) { $candidates = array(); }

		$batch_options  = get_transient( "zl_batch_{$batch_id}_options" ) ?: array();
		$sp_code        = $state['sp_code'] ?? '';
		$product_filter = $state['product_filter'] ?? '';
		$is_test        = (int) ( $state['is_test'] ?? 0 );
		$lock_key       = $state['lock_key'] ?? '';
		$inv_count      = (int) ( $state['inv_count'] ?? 0 );
		$processed      = (int) ( $state['processed'] ?? 0 );
		$total_customers = (int) ( $state['total_customers'] ?? 0 );

		try {
			if ( empty( $candidates ) ) {
				$diag = "Scanned {$processed}/{$total_customers} customers from {$inv_count} invoices.";
				if ( ! empty( $product_filter ) ) {
					$diag .= " Filter \"{$product_filter}\" matched 0.";
				}
				$diag .= ' Try broadening filters or lookback.';
				$this->update_batch_progress( $batch_id, 'error', 0, $diag, 'error' );
				error_log( 'ZL Relay: No candidates — ' . $diag );
				$wpdb->update( $wpdb->prefix . 'zl_batches',
					array( 'status' => 'failed' ), array( 'id' => $batch_id ), array( '%s' ), array( '%d' ) );
				delete_transient( $lock_key );
				$this->cleanup_batch_transients( $batch_id );
				wp_die();
			}

			$gen = new ZL_Lead_Generator();
			$gen->init_clients();

			// ── Step 4: Score + Select ──
			$this->update_batch_progress( $batch_id, 'select', 50, 'Scoring and selecting leads...' );

			// v2.0.0: Skip territory filtering for cross-territory (_ALL_) batches
			if ( $sp_code !== '_ALL_' ) {
				$filtered = $gen->filter_by_territory( $candidates, $sp_code );
				if ( empty( $filtered ) ) { $filtered = $candidates; }
			} else {
				$filtered = $candidates;
			}

			$scored = array();
			foreach ( $filtered as $c ) {
				$c['_score'] = $gen->score_lead( $c );
				$scored[]    = $c;
			}
			usort( $scored, function( $a, $b ) { return $b['_score'] <=> $a['_score']; } );

			$settings      = $gen->get_settings();
			$desired_limit = $is_test ? 3 : $settings['leads_per_batch'];
			$limit         = ! empty( $product_filter )
				? min( $desired_limit * 5, count( $scored ) )
				: $desired_limit;
			$leads = array_slice( $scored, 0, $limit );

			$demo_filter = $batch_options['demographic_filter'] ?? 'both';
			if ( ! empty( $demo_filter ) && $demo_filter !== 'both' ) {
				$leads = $gen->filter_by_demographic( $leads, $demo_filter );
			}

			$saved = 0;
			foreach ( $leads as $lead ) {
				$gen->save_lead_to_db( $batch_id, $lead, $lead['_score'] );
				if ( ! $is_test ) {
					$gen->update_lead_history( $lead['freshbooks_client_id'], $lead['email'], $batch_id );
				}
				$saved++;
			}
			$wpdb->update( $wpdb->prefix . 'zl_batches',
				array( 'total_leads' => $saved ), array( 'id' => $batch_id ), array( '%d' ), array( '%d' ) );

			// ── Step 4.5: AI Validate ──
			if ( ! empty( $product_filter ) && $gen->has_ai() ) {
				@set_time_limit( 120 );
				$this->update_batch_progress( $batch_id, 'ai_validate', 60, 'AI strict validation...' );
				try {
					$db_leads = $wpdb->get_results( $wpdb->prepare(
						"SELECT * FROM {$wpdb->prefix}zl_leads WHERE batch_id = %d ORDER BY score DESC", $batch_id
					), ARRAY_A );
					if ( ! empty( $db_leads ) ) {
						$result       = $gen->ai_strict_validate( $db_leads, $product_filter );
						$rejected_ids = array_column( $result['rejected'], 'id' );
						if ( ! empty( $rejected_ids ) ) {
							$ph = implode( ',', array_fill( 0, count( $rejected_ids ), '%d' ) );
							$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}zl_leads WHERE id IN ({$ph})", ...$rejected_ids ) );
						}
						$remaining = $wpdb->get_results( $wpdb->prepare(
							"SELECT id FROM {$wpdb->prefix}zl_leads WHERE batch_id = %d ORDER BY score DESC", $batch_id
						), ARRAY_A );
						if ( count( $remaining ) > $desired_limit ) {
							$excess = array_column( array_slice( $remaining, $desired_limit ), 'id' );
							if ( ! empty( $excess ) ) {
								$ph = implode( ',', array_fill( 0, count( $excess ), '%d' ) );
								$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}zl_leads WHERE id IN ({$ph})", ...$excess ) );
							}
						}
						$saved = (int) $wpdb->get_var( $wpdb->prepare(
							"SELECT COUNT(*) FROM {$wpdb->prefix}zl_leads WHERE batch_id = %d", $batch_id
						) );
						$wpdb->update( $wpdb->prefix . 'zl_batches',
							array( 'total_leads' => $saved ), array( 'id' => $batch_id ), array( '%d' ), array( '%d' ) );
					}
				} catch ( \Throwable $e ) {
					error_log( 'ZL Relay: AI validation error: ' . $e->getMessage() );
				}
			}

			// ── Step 5: AI Refine ──
			if ( $gen->has_ai() ) {
				@set_time_limit( 120 );
				$this->update_batch_progress( $batch_id, 'ai_refine', 70, 'AI description refinement...' );
				try {
					$db_leads = $wpdb->get_results( $wpdb->prepare(
						"SELECT * FROM {$wpdb->prefix}zl_leads WHERE batch_id = %d", $batch_id
					), ARRAY_A );
					if ( ! empty( $db_leads ) ) {
						$result = $gen->ai_refine_descriptions( $db_leads );
						if ( is_array( $result ) ) {
							foreach ( $result as $refined ) {
								$wpdb->update( $wpdb->prefix . 'zl_leads', array(
									'salesperson_notes' => $refined['salesperson_notes'] ?? '',
								), array( 'id' => $refined['id'] ), array( '%s' ), array( '%d' ) );
							}
						}
					}
				} catch ( \Throwable $e ) {
					error_log( 'ZL Relay: AI refine error: ' . $e->getMessage() );
				}
			}

			// ── Step 6: Nutshell Create ──
			// v2.0.0: When sp_code is '_ALL_', pass empty string so Nutshell
			// creates the lead unassigned (rather than looking up '_ALL_' as a salesperson)
			$ns_sp = ( $sp_code === '_ALL_' ) ? '' : $sp_code;
			if ( class_exists( 'ZL_Nutshell' ) && ! empty( $ns_sp ) ) {
				@set_time_limit( 120 );
				$this->update_batch_progress( $batch_id, 'nutshell', 85, 'Creating Nutshell leads...' );
				try {
					$ns = new ZL_Nutshell();
					$db_leads = $wpdb->get_results( $wpdb->prepare(
						"SELECT * FROM {$wpdb->prefix}zl_leads WHERE batch_id = %d", $batch_id
					), ARRAY_A );
					foreach ( $db_leads as $dl ) {
						$ns_id = $ns->create_lead_from_candidate( $dl, $ns_sp, $batch_id );
						if ( $ns_id ) {
							$wpdb->update( $wpdb->prefix . 'zl_leads',
								array( 'nutshell_lead_id' => $ns_id ), array( 'id' => $dl['id'] ), array( '%s' ), array( '%d' ) );
						}
					}
				} catch ( \Throwable $e ) {
					error_log( 'ZL Relay: Nutshell create error: ' . $e->getMessage() );
				}
			} elseif ( $sp_code === '_ALL_' ) {
				$this->update_batch_progress( $batch_id, 'nutshell', 85, 'Skipping Nutshell — no specific salesperson assigned.' );
			}

			// ── Step 7: AI Summary ──
			if ( $gen->has_ai() ) {
				@set_time_limit( 120 );
				$this->update_batch_progress( $batch_id, 'ai_summary', 90, 'Generating AI summary...' );
				try {
					$db_leads = $wpdb->get_results( $wpdb->prepare(
						"SELECT * FROM {$wpdb->prefix}zl_leads WHERE batch_id = %d ORDER BY score DESC", $batch_id
					), ARRAY_A );
					if ( ! empty( $db_leads ) ) {
						$summary = $gen->generate_batch_summary( $db_leads );
						if ( ! empty( $summary ) ) {
							$wpdb->update( $wpdb->prefix . 'zl_batches',
								array( 'ai_summary' => $summary ), array( 'id' => $batch_id ), array( '%s' ), array( '%d' ) );
						}
					}
				} catch ( \Throwable $e ) {
					error_log( 'ZL Relay: AI summary error: ' . $e->getMessage() );
				}
			}

			// ── Step 8: Finalize ──
			$final_leads = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}zl_leads WHERE batch_id = %d", $batch_id
			) );
			$wpdb->update( $wpdb->prefix . 'zl_batches', array(
				'status'      => 'complete',
				'total_leads' => $final_leads,
			), array( 'id' => $batch_id ), array( '%s', '%d' ), array( '%d' ) );

			if ( class_exists( 'ZDZ_Admin_Dashboard' ) && method_exists( 'ZDZ_Admin_Dashboard', 'log_action' ) ) {
				$bt = $wpdb->get_var( $wpdb->prepare(
					"SELECT batch_tag FROM {$wpdb->prefix}zl_batches WHERE id = %d", $batch_id ) );
				ZDZ_Admin_Dashboard::log_action( 'lead_generator', "Relay batch finalized: {$bt} — {$final_leads} leads" );
			}

			delete_transient( $lock_key );
			$this->update_batch_progress( $batch_id, 'complete', 100,
				"Complete! {$final_leads} leads created.", 'complete' );
			error_log( "ZL Relay: Batch #{$batch_id} finalized — {$final_leads} leads." );

		} catch ( \Throwable $e ) {
			error_log( 'ZL Relay: Finalize fatal error: ' . $e->getMessage() );
			$this->update_batch_progress( $batch_id, 'error', 0,
				'Finalize error: ' . $e->getMessage(), 'error' );
			$wpdb->update( $wpdb->prefix . 'zl_batches',
				array( 'status' => 'failed' ), array( 'id' => $batch_id ), array( '%s' ), array( '%d' ) );
			delete_transient( $lock_key );
		}

		$this->cleanup_batch_transients( $batch_id );
		wp_die();
	}

	/**
	 * Clean up all transients for a completed/failed batch.
	 *
	 * @since 2.0.0
	 */
	private function cleanup_batch_transients( $batch_id ) {
		delete_transient( "zl_batch_{$batch_id}_customers" );
		delete_transient( "zl_batch_{$batch_id}_candidates" );
		delete_transient( "zl_batch_{$batch_id}_options" );
		delete_transient( "zl_batch_{$batch_id}_expanded_filter" );
		delete_transient( "zl_batch_{$batch_id}_unique_names" );
		delete_transient( "zl_batch_{$batch_id}_ns_emails" );
		delete_transient( "zl_batch_{$batch_id}_enrich_state" );
		delete_transient( "zl_batch_{$batch_id}_relay_token" );
		delete_transient( "zl_batch_{$batch_id}_cmeta" );
		for ( $dci = 0; $dci < 50; $dci++ ) {
			if ( get_transient( "zl_batch_{$batch_id}_cchunk_{$dci}" ) === false ) { break; }
			delete_transient( "zl_batch_{$batch_id}_cchunk_{$dci}" );
		}
	}

	/**
	 * AJAX: Poll batch progress.
	 *
	 * @since 1.7.0
	 */
	public function ajax_poll_batch_progress() {
		check_ajax_referer( 'zl_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'zdz_access_app' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$batch_id = (int) ( $_POST['batch_id'] ?? 0 );
		if ( ! $batch_id ) {
			wp_send_json_error( 'Missing batch_id' );
		}

		$progress = get_transient( "zl_batch_progress_{$batch_id}" );

		// v1.8.0 — attach ZL_Progress-derived stall detection.
		// Existing frontends that ignore these extra fields keep working;
		// the upgraded widget surfaces a banner when `stalled` is true.
		$stall_info = array( 'stalled' => false, 'stall_threshold_s' => 120 );
		if ( class_exists( 'ZL_Progress' ) ) {
			$tp = ZL_Progress::get( $batch_id );
			if ( is_array( $tp ) ) {
				$stall_info = array(
					'stalled'           => (bool) ( $tp['stalled'] ?? false ),
					'stall_threshold_s' => (int) ( $tp['stall_threshold_s'] ?? 120 ),
					'elapsed_s'         => (int) ( $tp['elapsed_s'] ?? 0 ),
					'last_heartbeat_s'  => time() - (int) ( $tp['updated_at'] ?? time() ),
					'warnings'          => $tp['warnings'] ?? array(),
				);
			}
		}

		if ( ! $progress ) {
			global $wpdb;
			$batch = $wpdb->get_row( $wpdb->prepare(
				"SELECT status, total_leads FROM {$wpdb->prefix}zl_batches WHERE id = %d", $batch_id
			), ARRAY_A );

			if ( $batch && $batch['status'] === 'complete' ) {
				wp_send_json_success( array_merge( array(
					'batch_id' => $batch_id, 'step' => 'complete', 'pct' => 100,
					'message' => 'Complete! ' . $batch['total_leads'] . ' leads created.', 'status' => 'complete',
				), $stall_info ) );
			} elseif ( $batch && $batch['status'] === 'failed' ) {
				wp_send_json_success( array_merge( array(
					'batch_id' => $batch_id, 'step' => 'error', 'pct' => 0,
					'message' => 'Generation failed.', 'status' => 'error',
				), $stall_info ) );
			} elseif ( $batch && in_array( $batch['status'], array( 'generating', 'running' ), true ) ) {
				// v2.0.0: Batch is still running but progress transient expired.
				// This happens when the user returns after the 1-hour transient TTL.
				// Tell the frontend it's still running so polling continues.
				wp_send_json_success( array_merge( array(
					'batch_id' => $batch_id, 'step' => 'running', 'pct' => 0,
					'message' => 'Batch is running — progress data expired, waiting for next update...', 'status' => 'running',
				), $stall_info ) );
			} else {
				wp_send_json_success( array_merge( array(
					'batch_id' => $batch_id, 'step' => 'unknown', 'pct' => 0,
					'message' => 'No progress data.', 'status' => 'unknown',
				), $stall_info ) );
			}
			return;
		}

		wp_send_json_success( array_merge( (array) $progress, $stall_info ) );
	}

	// ── Compressed Transient Helpers ──────────────────────────────────

	/**
	 * Store large data in a WordPress transient using JSON + gzip compression.
	 *
	 * A 15-year lookback produces 10,000+ invoices grouped by customer. When
	 * serialized by PHP this exceeds MySQL's max_allowed_packet (typically 4 MB),
	 * causing set_transient() to silently fail. Compressing with gzip reduces
	 * the data ~10× so it always fits.
	 *
	 * Format: the stored value is a string prefixed with "zl_gz:" followed by
	 * base64-encoded gzip-compressed JSON.
	 *
	 * @param string $key  Transient name (e.g. "zl_batch_42_customers").
	 * @param mixed  $data PHP array/value to store.
	 * @param int    $ttl  Expiration in seconds (default 3600).
	 * @return bool  Whether set_transient reported success.
	 */
	/**
	 * Refresh a transient's TTL without re-writing the value.
	 *
	 * WordPress stores transient timeouts in wp_options as
	 * '_transient_timeout_{key}'. By updating just that option we reset
	 * the clock without the cost of re-serialising / re-compressing
	 * the actual data. This is critical for the _customers transient
	 * which can be several hundred KB after compression.
	 *
	 * @since 1.2.10
	 *
	 * @param string $key Transient name (without _transient_ prefix).
	 * @param int    $ttl New TTL in seconds (default ZL_TRANSIENT_TTL).
	 */
	private function touch_transient_ttl( $key, $ttl = 0 ) {
		if ( $ttl <= 0 ) {
			$ttl = defined( 'ZL_TRANSIENT_TTL' ) ? ZL_TRANSIENT_TTL : 14400;
		}
		// Only works for DB-backed transients (not object cache).
		// Check if the timeout option exists before updating.
		$timeout_key = '_transient_timeout_' . $key;
		$existing    = get_option( $timeout_key );
		if ( $existing !== false ) {
			update_option( $timeout_key, time() + $ttl, true );
		}
	}

	private function set_compressed_transient( $key, $data, $ttl = 3600 ) {
		$json = wp_json_encode( $data );
		if ( function_exists( 'gzcompress' ) ) {
			$value = 'zl_gz:' . base64_encode( gzcompress( $json, 9 ) );
		} else {
			// Fallback: store raw PHP array (no compression available)
			$value = $data;
		}
		$result = set_transient( $key, $value, $ttl );

		// Verify the write actually persisted — silent failures from
		// max_allowed_packet leave no trace unless we check.
		$verify = get_transient( $key );
		if ( $verify === false || ( is_string( $value ) && $verify !== $value ) ) {
			$raw_bytes = is_string( $json ) ? strlen( $json ) : 0;
			$stored_bytes = is_string( $value ) ? strlen( $value ) : 0;
			error_log( "ZL WARNING: Transient write may have failed for {$key} "
				. "(raw JSON: {$raw_bytes} bytes, stored: {$stored_bytes} bytes)" );
		}

		return $result;
	}

	/**
	 * Retrieve large data from a compressed transient.
	 *
	 * Handles both compressed (zl_gz: prefix) and legacy uncompressed formats
	 * for backward compatibility during the upgrade from v1.2.6 → v1.2.7.
	 *
	 * @param string $key Transient name.
	 * @return array|false The decoded array, or false if not found / corrupt.
	 */
	private function get_compressed_transient( $key ) {
		$raw = get_transient( $key );
		if ( $raw === false ) {
			return false;
		}

		// New compressed format: "zl_gz:<base64-gzip-json>"
		if ( is_string( $raw ) && strpos( $raw, 'zl_gz:' ) === 0 ) {
			$decoded = base64_decode( substr( $raw, 7 ) );
			if ( $decoded === false ) {
				error_log( "ZL ERROR: base64_decode failed for transient {$key}" );
				return false;
			}
			$json = gzuncompress( $decoded );
			if ( $json === false ) {
				error_log( "ZL ERROR: gzuncompress failed for transient {$key}" );
				return false;
			}
			$result = json_decode( $json, true );
			if ( $result === null && json_last_error() !== JSON_ERROR_NONE ) {
				error_log( "ZL ERROR: json_decode failed for transient {$key}: " . json_last_error_msg() );
				return false;
			}
			return $result;
		}

		// Legacy format: PHP array stored directly by set_transient
		if ( is_array( $raw ) ) {
			return $raw;
		}

		return false;
	}

	/**
	 * v1.8.0 — Structured progress endpoint for the speed release.
	 *
	 * Returns ZL_Progress payload (stage, current/total, elapsed_s,
	 * warnings, stall detection). Frontend polls every 3s; if the
	 * payload indicates `stalled=true` the frontend shows a dedicated
	 * stall banner instead of fabricating a percentage.
	 *
	 * @since 1.8.0
	 */
	public function ajax_zlg_get_batch_progress() {
		check_ajax_referer( 'zl_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'zdz_access_app' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$batch_id = (int) ( $_POST['batch_id'] ?? 0 );
		if ( ! $batch_id ) {
			wp_send_json_error( 'Missing batch_id' );
		}

		if ( ! class_exists( 'ZL_Progress' ) ) {
			wp_send_json_error( 'Progress subsystem unavailable' );
		}

		$payload = ZL_Progress::get( $batch_id );
		if ( ! $payload ) {
			// No record — maybe the batch started before v1.8.0, or is fully
			// complete and the record has expired. Tell the frontend "done"
			// so it stops polling cleanly.
			wp_send_json_success( array(
				'batch_id'  => $batch_id,
				'stage'     => 'Complete',
				'stage_key' => 'complete',
				'current'   => 0,
				'total'     => 0,
				'elapsed_s' => 0,
				'warnings'  => array(),
				'done'      => true,
				'failed'    => false,
				'stalled'   => false,
			) );
		}

		wp_send_json_success( $payload );
	}
}

new ZL_Dashboard();