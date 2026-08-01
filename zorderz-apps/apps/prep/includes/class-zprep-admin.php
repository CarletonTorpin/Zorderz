<?php
/**
 * Zorderz Prep — settings page.
 *
 * Credentials are NOT managed here — the CRM / AI / billing providers are configured once
 * in the theme's Core Settings and read via the shared clients. This page owns only the
 * prep-specific configuration: the roll/material set (costs ship empty), the fabrication
 * rules, the QUEUE tag + subtype, the CRM cut stage, and the AI model. Plus the leftover
 * inventory subpage and the hidden dashboard page used by the SPA iframe bridge.
 *
 * @package Zorderz\Prep
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZPREP_Admin {

	const PAGE = 'zprep-settings';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	public static function register_admin_page(): void {
		add_menu_page(
			__( 'Prep', 'zorderz' ),
			__( 'Prep', 'zorderz' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_settings_page' ),
			'dashicons-editor-scissors',
			58
		);
		add_submenu_page( self::PAGE, __( 'Leftover Inventory', 'zorderz' ), __( 'Leftover Inventory', 'zorderz' ), 'manage_options', 'zprep-leftovers', array( __CLASS__, 'render_leftovers_page' ) );
		add_submenu_page( null, __( 'Prep', 'zorderz' ), __( 'Prep', 'zorderz' ), 'read', 'zprep-dashboard', array( __CLASS__, 'render_dashboard_page' ) );
	}

	public static function register_settings(): void {
		register_setting(
			'zprep_settings_group',
			'zprep_settings',
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => ZPREP_Settings::defaults(),
			)
		);
	}

	public static function sanitize_settings( $input ): array {
		$defaults = ZPREP_Settings::defaults();

		// Rolls: one per line, "width,color,cost,available" (available optional -> 1).
		$rolls = array();
		if ( isset( $input['rolls_text'] ) ) {
			foreach ( preg_split( '/\r\n|\r|\n/', (string) $input['rolls_text'] ) as $line ) {
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}
				$parts = array_map( 'trim', explode( ',', $line ) );
				$w     = (int) ( $parts[0] ?? 0 );
				$c     = strtolower( sanitize_text_field( $parts[1] ?? '' ) );
				if ( $w <= 0 || '' === $c ) {
					continue;
				}
				$rolls[] = array(
					'width_in'    => $w,
					'color'       => $c,
					'cost_per_ft' => max( 0.0, (float) ( $parts[2] ?? 0 ) ),
					'available'   => ! isset( $parts[3] ) || '' === $parts[3] || '0' !== $parts[3],
				);
			}
		} elseif ( isset( $input['rolls'] ) && is_array( $input['rolls'] ) ) {
			$rolls = $input['rolls'];
		}

		return array(
			'grungy_end_margin_in' => isset( $input['grungy_end_margin_in'] ) ? max( 0, (float) $input['grungy_end_margin_in'] ) : $defaults['grungy_end_margin_in'],
			'min_saveable_in'      => isset( $input['min_saveable_in'] ) ? max( 0, (float) $input['min_saveable_in'] ) : $defaults['min_saveable_in'],
			'black_tiebreaker'     => in_array( $input['black_tiebreaker'] ?? '', array( 'fewest_sheets', 'shortest_length' ), true ) ? $input['black_tiebreaker'] : $defaults['black_tiebreaker'],
			'ai_model'             => sanitize_text_field( $input['ai_model'] ?? '' ),
			'queue_tag'            => sanitize_text_field( $input['queue_tag'] ?? '' ),
			'queue_subtype'        => sanitize_key( $input['queue_subtype'] ?? '' ),
			'cut_stage_name'       => sanitize_text_field( $input['cut_stage_name'] ?? '' ),
			'rolls'                => $rolls,
		);
	}

	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = ZPREP_Settings::all();

		// Serialize rolls to the textarea format.
		$rolls_text = '';
		foreach ( ZPREP_Settings::rolls() as $r ) {
			$rolls_text .= sprintf( "%d,%s,%s,%d\n", (int) $r['width_in'], $r['color'], rtrim( rtrim( number_format( (float) $r['cost_per_ft'], 2, '.', '' ), '0' ), '.' ) ?: '0', $r['available'] ? 1 : 0 );
		}

		// Subtype choices from the Item Engine (if present).
		$subtypes = array();
		if ( class_exists( 'ZDZ_Item_Engine' ) && method_exists( 'ZDZ_Item_Engine', 'subtypes' ) ) {
			foreach ( (array) ZDZ_Item_Engine::subtypes() as $st ) {
				$slug = is_array( $st ) ? ( $st['slug'] ?? '' ) : (string) $st;
				$lbl  = is_array( $st ) ? ( $st['label'] ?? $slug ) : (string) $st;
				if ( '' !== $slug ) {
					$subtypes[ $slug ] = $lbl;
				}
			}
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Prep Settings', 'zorderz' ); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'zprep_settings_group' ); ?>

				<h2><?php esc_html_e( 'Roll / Material Stock', 'zorderz' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'One roll per line: width_in,color,cost_per_ft,available (1/0). Costs ship EMPTY — enter your own per-foot costs (used only for the material-cost summary). Availability is data: a width/colour that "does not exist" is simply omitted.', 'zorderz' ); ?>
				</p>
				<table class="form-table">
					<tr>
						<th><label for="zprep_rolls_text"><?php esc_html_e( 'Rolls', 'zorderz' ); ?></label></th>
						<td>
							<textarea id="zprep_rolls_text" name="zprep_settings[rolls_text]" rows="5" class="large-text code" placeholder="36,black,0,1"><?php echo esc_textarea( trim( $rolls_text ) ); ?></textarea>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Cutting Rules', 'zorderz' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="grungy_end_margin_in"><?php esc_html_e( 'Grungy-end margin', 'zorderz' ); ?></label></th>
						<td><input type="number" step="0.1" min="0" id="grungy_end_margin_in" name="zprep_settings[grungy_end_margin_in]" value="<?php echo esc_attr( $s['grungy_end_margin_in'] ); ?>" class="regular-text" /> <span class="description"><?php esc_html_e( 'inches discarded off each end of every raw roll.', 'zorderz' ); ?></span></td>
					</tr>
					<tr>
						<th><label for="min_saveable_in"><?php esc_html_e( 'Minimum saveable leftover', 'zorderz' ); ?></label></th>
						<td><input type="number" step="0.1" min="0" id="min_saveable_in" name="zprep_settings[min_saveable_in]" value="<?php echo esc_attr( $s['min_saveable_in'] ); ?>" class="regular-text" /> <span class="description"><?php esc_html_e( 'inches — a leftover with BOTH dimensions ≥ this becomes "Save for future use".', 'zorderz' ); ?></span></td>
					</tr>
					<tr>
						<th><label for="black_tiebreaker"><?php esc_html_e( 'Roll tiebreaker', 'zorderz' ); ?></label></th>
						<td>
							<select id="black_tiebreaker" name="zprep_settings[black_tiebreaker]">
								<option value="fewest_sheets" <?php selected( $s['black_tiebreaker'], 'fewest_sheets' ); ?>><?php esc_html_e( 'Fewest Sheets (minimize setups)', 'zorderz' ); ?></option>
								<option value="shortest_length" <?php selected( $s['black_tiebreaker'], 'shortest_length' ); ?>><?php esc_html_e( 'Shortest Length (minimize linear feet)', 'zorderz' ); ?></option>
							</select>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Queue', 'zorderz' ); ?></h2>
				<p class="description"><?php esc_html_e( 'What makes a job "prep work". Leave both empty to accept every job.', 'zorderz' ); ?></p>
				<table class="form-table">
					<tr>
						<th><label for="queue_subtype"><?php esc_html_e( 'Queue item subtype', 'zorderz' ); ?></label></th>
						<td>
							<?php if ( $subtypes ) : ?>
								<select id="queue_subtype" name="zprep_settings[queue_subtype]">
									<option value=""><?php esc_html_e( '— any —', 'zorderz' ); ?></option>
									<?php foreach ( $subtypes as $slug => $lbl ) : ?>
										<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $s['queue_subtype'], $slug ); ?>><?php echo esc_html( $lbl ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php else : ?>
								<input type="text" id="queue_subtype" name="zprep_settings[queue_subtype]" value="<?php echo esc_attr( $s['queue_subtype'] ); ?>" class="regular-text" />
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Binds the queue to an Item Engine subtype (the product-line gate reads the item\'s own subtype).', 'zorderz' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="queue_tag"><?php esc_html_e( 'Queue reference tag', 'zorderz' ); ?></label></th>
						<td><input type="text" id="queue_tag" name="zprep_settings[queue_tag]" value="<?php echo esc_attr( $s['queue_tag'] ); ?>" class="regular-text" /> <span class="description"><?php esc_html_e( 'Optional: a reference-code token that marks a job for this queue.', 'zorderz' ); ?></span></td>
					</tr>
					<tr>
						<th><label for="cut_stage_name"><?php esc_html_e( 'CRM cut stage', 'zorderz' ); ?></label></th>
						<td><input type="text" id="cut_stage_name" name="zprep_settings[cut_stage_name]" value="<?php echo esc_attr( $s['cut_stage_name'] ); ?>" class="regular-text" /> <span class="description"><?php esc_html_e( 'The pipeline stage that means "ready to cut". Empty disables the auto-queue.', 'zorderz' ); ?></span></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'AI Parsing', 'zorderz' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="ai_model"><?php esc_html_e( 'AI Model', 'zorderz' ); ?></label></th>
						<td><input type="text" id="ai_model" name="zprep_settings[ai_model]" value="<?php echo esc_attr( $s['ai_model'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( ZPREP_Settings::ai_model() ); ?>" /> <p class="description"><?php esc_html_e( 'Model name for measurement parsing. Blank uses the platform default.', 'zorderz' ); ?></p></td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Connections', 'zorderz' ); ?></h2>
			<p><?php esc_html_e( 'Prep reads its CRM, AI and billing providers from the theme\'s Core Settings. It stores no credentials of its own.', 'zorderz' ); ?></p>
			<p>
				<button type="button" class="button" id="zprep-test-crm-btn"><?php esc_html_e( 'Test CRM', 'zorderz' ); ?></button>
				<button type="button" class="button" id="zprep-test-billing-btn" style="margin-left:8px;"><?php esc_html_e( 'Test Billing', 'zorderz' ); ?></button>
				<button type="button" class="button" id="zprep-test-ai-btn" style="margin-left:8px;"><?php esc_html_e( 'Test AI', 'zorderz' ); ?></button>
			</p>
			<div id="zprep-test-results" style="margin-top:1em;"></div>

			<script>
			(function(){
				var nonce='<?php echo esc_js( wp_create_nonce( ZPREP_NONCE ) ); ?>', ajaxurl='<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
				var out=document.getElementById('zprep-test-results');
				function esc(s){var d=document.createElement('div');d.textContent=(s==null?'':String(s));return d.innerHTML;}
				function run(btn,action,label){btn.disabled=true;var o=btn.textContent;btn.textContent='…';var b=new FormData();b.append('action',action);b.append('nonce',nonce);
					fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:b}).then(function(r){return r.json();}).then(function(res){btn.disabled=false;btn.textContent=o;
						var r=(res&&res.data&&res.data.result)||{ok:false,message:(res&&res.data&&res.data.message)||'Failed'};
						var el=document.createElement('p');el.innerHTML='<strong>'+esc(label)+':</strong> '+(r.ok?'✓ ':'✗ ')+esc(r.message);out.prepend(el);
					}).catch(function(e){btn.disabled=false;btn.textContent=o;var el=document.createElement('p');el.textContent=label+': '+e;out.prepend(el);});}
				document.getElementById('zprep-test-crm-btn').addEventListener('click',function(){run(this,'zprep_test_crm','CRM');});
				document.getElementById('zprep-test-billing-btn').addEventListener('click',function(){run(this,'zprep_test_billing','Billing');});
				document.getElementById('zprep-test-ai-btn').addEventListener('click',function(){run(this,'zprep_test_ai','AI');});
			})();
			</script>

			<hr>
			<p><?php esc_html_e( 'Module version:', 'zorderz' ); ?> <code><?php echo esc_html( ZPREP_VERSION ); ?></code></p>
		</div>
		<?php
	}

	public static function render_dashboard_page(): void {
		if ( ! is_user_logged_in() || ! ZPREP_Dashboard::user_can_access() ) {
			wp_die( esc_html__( 'You do not have access to Prep.', 'zorderz' ) );
		}
		$is_mobile = isset( $_GET['zdz_mobile'] ) && '1' === $_GET['zdz_mobile']; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $is_mobile ) {
			echo '<style>#wpadminbar,#adminmenuwrap,#adminmenuback,#wpfooter,.update-nag,.notice{display:none!important;}#wpcontent{margin-left:0!important;padding-top:0!important;}html{margin-top:0!important;}</style>';
		}
		if ( class_exists( 'ZPREP_App' ) && interface_exists( '\Zorderz\Widget_App_Interface' ) ) {
			$app = new ZPREP_App();
			echo '<div class="wrap"><div style="max-width:720px;margin:0 auto;">';
			echo $app->render_dashboard_widget( get_current_user_id() ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</div></div>';
		} else {
			echo '<p>' . esc_html__( 'The Zorderz theme is not active.', 'zorderz' ) . '</p>';
		}
	}

	public static function render_leftovers_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_enqueue_script( 'zprep-leftovers-admin', ZPREP_URL . 'assets/js/leftovers-admin.js', array(), zprep_asset_ver( 'assets/js/leftovers-admin.js' ), true );
		wp_localize_script( 'zprep-leftovers-admin', 'zprepLeftoversData', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( ZPREP_NONCE ) ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Leftover Inventory', 'zorderz' ); ?></h1>
			<p><?php esc_html_e( 'Save-for-future offcuts produced by the cut engine are logged here. Enable "Use available leftovers?" on a new job to consume from this list. Pieces are reserved for 4 hours when a plan is computed; they auto-release if the plan is not posted to the CRM.', 'zorderz' ); ?></p>

			<div class="zprep-lo-filters" style="display:flex;gap:12px;align-items:flex-end;margin:1em 0;flex-wrap:wrap;">
				<label><?php esc_html_e( 'Material', 'zorderz' ); ?>
					<select id="zprep-lo-mat"><option value=""><?php esc_html_e( 'All', 'zorderz' ); ?></option>
						<?php foreach ( ZPREP_Settings::roll_colors() as $c ) : ?>
							<option value="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( ucfirst( $c ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label><?php esc_html_e( 'Status', 'zorderz' ); ?>
					<select id="zprep-lo-status">
						<option value=""><?php esc_html_e( 'All', 'zorderz' ); ?></option>
						<option value="available" selected><?php esc_html_e( 'Available', 'zorderz' ); ?></option>
						<option value="reserved"><?php esc_html_e( 'Reserved', 'zorderz' ); ?></option>
						<option value="used"><?php esc_html_e( 'Used', 'zorderz' ); ?></option>
						<option value="discarded"><?php esc_html_e( 'Discarded', 'zorderz' ); ?></option>
					</select>
				</label>
				<label><?php esc_html_e( 'Min width (in)', 'zorderz' ); ?> <input type="number" id="zprep-lo-mw" step="0.1" min="0" style="width:90px;"></label>
				<label><?php esc_html_e( 'Min length (in)', 'zorderz' ); ?> <input type="number" id="zprep-lo-ml" step="0.1" min="0" style="width:90px;"></label>
				<button type="button" class="button" id="zprep-lo-reload"><?php esc_html_e( 'Apply filters', 'zorderz' ); ?></button>
				<a class="button" id="zprep-lo-csv" href="#" style="margin-left:auto;"><?php esc_html_e( 'Export CSV', 'zorderz' ); ?></a>
			</div>

			<div class="zprep-lo-bulk" style="margin:.5em 0;">
				<button type="button" class="button" id="zprep-lo-discard" disabled><?php esc_html_e( 'Discard selected', 'zorderz' ); ?></button>
				<span id="zprep-lo-count" style="margin-left:1em;color:#666;"></span>
			</div>

			<table class="widefat striped" id="zprep-lo-table">
				<thead>
					<tr>
						<th style="width:32px;"><input type="checkbox" id="zprep-lo-all"></th>
						<th><?php esc_html_e( 'Created', 'zorderz' ); ?></th>
						<th><?php esc_html_e( 'Source job', 'zorderz' ); ?></th>
						<th><?php esc_html_e( 'Material', 'zorderz' ); ?></th>
						<th><?php esc_html_e( 'Roll', 'zorderz' ); ?></th>
						<th><?php esc_html_e( 'W × L (in)', 'zorderz' ); ?></th>
						<th><?php esc_html_e( 'Bin', 'zorderz' ); ?></th>
						<th><?php esc_html_e( 'Status', 'zorderz' ); ?></th>
						<th><?php esc_html_e( 'Used in', 'zorderz' ); ?></th>
					</tr>
				</thead>
				<tbody><tr><td colspan="9"><em><?php esc_html_e( 'Loading…', 'zorderz' ); ?></em></td></tr></tbody>
			</table>
		</div>
		<?php
	}
}
