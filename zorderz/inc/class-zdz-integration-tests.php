<?php
/**
 * ZDZ_Integration_Tests — Admin-only health check panel.
 *
 * Validates all cross-plugin communication channels documented in
 * Zorderz-Ecosystem-Architecture-v7.md Section 3.5.
 *
 * Access: WP Admin → Zorderz → Integration Health
 * Runs ONLY when manually triggered — never on page load, never on cron.
 *
 * NOTE: Uses REST API (not wp_ajax_) per Coding Convention #3:
 * "No wp_ajax_ in theme — REST API only; AJAX is plugin-only"
 *
 * @package Zorderz
 * @since   2.17.0 (Prompt Group 7B)
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ZDZ_Integration_Tests {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_admin_page' ] );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function add_admin_page() {
		// Register under Tools menu (reliable across all hosting/caching setups)
		// Access: Tools → TS Integration Health
		add_management_page(
			'TS Integration Health',
			'TS Integration Health',
			'manage_options',
			'zdz-integration-health-tools',
			[ $this, 'render_page' ]
		);
	}

	public function register_routes() {
		register_rest_route( 'zorderz/v1', '/integration-health', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'run_checks' ],
			'permission_callback' => function() {
				return current_user_can( 'manage_options' );
			},
		] );
	}

	/* ==================================================================
	 * ADMIN PAGE
	 * ================================================================== */

	public function render_page() {
		?>
		<div class="wrap">
			<h1>Zorderz Integration Health</h1>
			<p>Run all cross-plugin health checks to verify the system is operating correctly after changes.</p>
			<button id="zdz-run-checks" class="button button-primary button-hero">Run All Checks</button>
			<div id="zdz-check-results" style="margin-top:20px"></div>
		</div>
		<script>
		document.getElementById('zdz-run-checks').addEventListener('click', async function() {
			this.disabled = true;
			this.textContent = 'Running…';
			var results = document.getElementById('zdz-check-results');
			results.innerHTML = '<p>Running health checks…</p>';

			try {
				var resp = await fetch(
					'<?php echo esc_url( rest_url( ZDZ_REST_NS . '/integration-health' ) ); ?>',
					{ headers: { 'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>' } }
				);
				var data = await resp.json();

				if (data.checks) {
					var passed = data.checks.filter(function(c) { return c.pass; }).length;
					var total  = data.checks.length;
					var html   = '<h3>' + passed + '/' + total + ' checks passed</h3>';
					html += '<table class="widefat striped"><thead><tr><th>Channel</th><th>Status</th><th>Details</th></tr></thead><tbody>';
					data.checks.forEach(function(c) {
						var icon = c.pass ? '\u2705' : '\u274c';
						html += '<tr><td>' + c.name + '</td><td>' + icon + ' ' + (c.pass ? 'PASS' : 'FAIL') + '</td><td>' + c.detail + '</td></tr>';
					});
					html += '</tbody></table>';
					results.innerHTML = html;
				} else {
					results.innerHTML = '<p style="color:red">Error: ' + (data.message || 'Unknown') + '</p>';
				}
			} catch(e) {
				results.innerHTML = '<p style="color:red">Connection error: ' + e.message + '</p>';
			} finally {
				this.disabled = false;
				this.textContent = 'Run All Checks';
			}
		});
		</script>
		<?php
	}

	/* ==================================================================
	 * REST ENDPOINT — Runs all checks and returns results.
	 * ================================================================== */

	public function run_checks( $request ) {

		$checks = [];

		// ── Channel 1: Credential Cascade ──
		$checks[] = $this->check_credential_cascade();

		// ── Channel 2: Plugin Discovery ──
		$checks[] = $this->check_plugin_discovery();

		// ── Channel 3: Cross-Plugin DB Reads ──
		$checks[] = $this->check_cross_db_reads();

		// ── Channel 4: Bridge Navigate ──
		$checks[] = $this->check_bridge_js();

		// ── Channel 5: Game Embed API ──
		$checks[] = $this->check_game_embed();

		// ── Channel 6: Knowledge Vault Bridge ──
		$checks[] = $this->check_vault_bridge();

		// ── Channel 7: Prep → Receipts ──
		$checks[] = $this->check_cutter_receipt();

		// ── Channel 8: Messaging → Analytics Preview ──
		$checks[] = $this->check_messaging_preview();

		// ── Channel 9: KPI Metrics Filter ──
		$checks[] = $this->check_kpi_metrics();

		// ── Channel 10: Permission System (Group 5) ──
		$checks[] = $this->check_permissions();

		// ── Channel 11: Pricing Oracle (Group 6B) ──
		$checks[] = $this->check_pricing_oracle();

		return rest_ensure_response( [ 'checks' => $checks ] );
	}

	/* ==================================================================
	 * INDIVIDUAL CHECK METHODS
	 * All checks are read-only — no external API calls, no data mutations.
	 * ================================================================== */

	private function check_credential_cascade() {
		$prefixes = [ 'tscc_', 'tsec_', 'zdz_surveys_', 'tsa_', 'tsl_', 'zdz_core_' ];
		$found = 0;
		foreach ( $prefixes as $p ) {
			if ( ! empty( get_option( $p . 'fb_access_token', '' ) ) ) {
				$found++;
			}
		}
		return [
			'name'   => 'Credential Cascade (FreshBooks)',
			'pass'   => $found >= 2,
			'detail' => "{$found}/" . count( $prefixes ) . " prefixes have access tokens",
		];
	}

	private function check_plugin_discovery() {
		$apps     = apply_filters( 'zdz_register_apps', [] );
		$expected = 12;
		$count    = count( $apps );
		return [
			'name'   => 'Plugin Discovery (zdz_register_apps)',
			'pass'   => $count >= 10,
			'detail' => "{$count} apps registered (expected ~{$expected})",
		];
	}

	private function check_cross_db_reads() {
		global $wpdb;
		$has_table = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}zdz_survey_leads'" ) !== null;
		return [
			'name'   => 'Cross-Plugin DB (Analytics → Surveys)',
			'pass'   => $has_table,
			'detail' => $has_table ? 'wp_zdz_survey_leads table exists' : 'Table not found',
		];
	}

	private function check_bridge_js() {
		$bridge = file_exists( get_template_directory() . '/assets/js/bridge.js' );
		return [
			'name'   => 'Bridge.js (SPA Navigation)',
			'pass'   => $bridge,
			'detail' => $bridge ? 'bridge.js present' : 'bridge.js MISSING',
		];
	}

	private function check_game_embed() {
		$has_game = class_exists( 'TSG_App' );
		return [
			'name'   => 'Game Embed API',
			'pass'   => $has_game,
			'detail' => $has_game ? 'TSG_App class available' : 'Game plugin not active',
		];
	}

	private function check_vault_bridge() {
		$has_bridge = class_exists( 'TSKV_TSA_Bridge' );
		$has_methods = $has_bridge
			&& method_exists( 'TSKV_TSA_Bridge', 'get_inventory' )
			&& method_exists( 'TSKV_TSA_Bridge', 'get_context' );
		return [
			'name'   => 'Knowledge Vault Bridge',
			'pass'   => $has_bridge && $has_methods,
			'detail' => $has_bridge
				? ( $has_methods ? 'Bridge active, get_inventory + get_context available' : 'Bridge active but missing expected methods' )
				: 'TSKV_TSA_Bridge class not found',
		];
	}

	private function check_cutter_receipt() {
		$prep     = class_exists( 'ZPREP_App' );
		$receipts = class_exists( 'ZRCPT_App' );
		return [
			'name'   => 'Prep → Receipts Handoff',
			'pass'   => $prep && $receipts,
			'detail' => 'Prep: ' . ( $prep ? "✓" : "✗" ) . ', Receipts: ' . ( $receipts ? "✓" : "✗" ),
		];
	}

	private function check_messaging_preview() {
		$server    = rest_get_server();
		$routes    = $server->get_routes();
		$has_route = false;
		foreach ( array_keys( $routes ) as $route ) {
			if ( strpos( $route, 'freshbooks-preview' ) !== false ) {
				$has_route = true;
				break;
			}
		}
		return [
			'name'   => 'Messaging → Analytics Preview',
			'pass'   => $has_route,
			'detail' => $has_route ? 'REST route registered' : 'freshbooks-preview route NOT found',
		];
	}

	private function check_kpi_metrics() {
		$metrics = apply_filters( 'zdz_kpi_metrics', [] );
		$count   = count( $metrics );
		$preview = $count > 0 ? ' (' . implode( ', ', array_slice( array_keys( $metrics ), 0, 5 ) ) . ')' : '';
		return [
			'name'   => 'KPI Metrics Filter (zdz_kpi_metrics)',
			'pass'   => $count > 0,
			'detail' => "{$count} metrics registered{$preview}",
		];
	}

	private function check_permissions() {
		$has_class = class_exists( 'ZDZ_Data_Permissions' );
		if ( ! $has_class ) {
			return [
				'name'   => 'Permission System (Group 5)',
				'pass'   => false,
				'detail' => 'ZDZ_Data_Permissions class not found — Group 5 not yet deployed',
			];
		}
		$user_id     = get_current_user_id();
		$can_revenue = ZDZ_Data_Permissions::can( $user_id, 'view_company_revenue' );
		return [
			'name'   => 'Permission System (Group 5)',
			'pass'   => true,
			'detail' => 'ZDZ_Data_Permissions active. Admin view_company_revenue: ' . ( $can_revenue ? 'allowed' : 'denied' ),
		];
	}

	private function check_pricing_oracle() {
		$has_bridge = class_exists( 'TSKV_Bridge' );
		if ( ! $has_bridge ) {
			return [
				'name'   => 'Pricing Oracle (Group 6B)',
				'pass'   => false,
				'detail' => 'TSKV_Bridge class not found — Group 6B not yet deployed',
			];
		}
		$pricing  = TSKV_Bridge::get_pricing_context();
		$has_docs = ! empty( $pricing['doc_ids'] );
		$detail   = 'TSKV_Bridge active. ';

		if ( ! $has_docs ) {
			$detail .= 'No docs designated yet';
		} else {
			$detail .= count( $pricing['doc_ids'] ) . ' pricing authority docs';

			// v1.3.3: Verify content chunks exist for each pricing doc.
			global $wpdb;
			$chunk_warnings = array();
			foreach ( $pricing['doc_ids'] as $doc_id ) {
				$chunk_count = $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}tskv_chunks WHERE document_id = %d",
					$doc_id
				) );
				if ( (int) $chunk_count === 0 ) {
					$chunk_warnings[] = "doc #{$doc_id} has 0 chunks";
				} else {
					$detail .= " (#{$doc_id}: {$chunk_count} chunks)";
				}
			}
			if ( ! empty( $chunk_warnings ) ) {
				return [
					'name'   => 'Pricing Oracle (Group 6B)',
					'pass'   => false,
					'detail' => $detail . ' ⚠️ ' . implode( ', ', $chunk_warnings )
						. ' — run chunk seeder or re-index. Brain Bot cannot read pricing tables without chunks.',
				];
			}
		}

		return [
			'name'   => 'Pricing Oracle (Group 6B)',
			'pass'   => true,
			'detail' => $detail,
		];
	}
}

// Self-instantiate (matches ZDZ_Bug_Tracker pattern)
new ZDZ_Integration_Tests();
