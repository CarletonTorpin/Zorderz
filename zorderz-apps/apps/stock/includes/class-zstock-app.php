<?php
/**
 * Zorderz Stock — dashboard app tile (theme interface).
 *
 * Implements \Zorderz\Widget_App_Interface. Required only inside after_setup_theme, once the
 * theme has defined the interface. Declarative `roles` are intent only — live access is granted
 * per user via zdz_allowed_apps and resolved by ZDZ_Plugin_API::user_can_access_app('stock').
 *
 * @package Zorderz\Stock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSTOCK_App implements \Zorderz\Widget_App_Interface {

	public function get_config(): array {
		return array(
			'id'          => ZSTOCK_APP_ID,
			'nm'          => 'Stock',
			'name'        => 'Stock',
			'icon'        => 'package',
			'cat'         => 'Field',
			'cc'          => '#7C3AED',
			'desc'        => 'Inventory tracking, supplier-invoice parsing, and low-stock alerts.',
			'description' => 'Inventory tracking, supplier-invoice parsing, and low-stock alerts.',
			// Admin/field tool — offered to admin-tier and field roles; live access is per-user.
			'roles'       => array( 'administrator', 'zdz_owner', 'zdz_admin', 'zdz_tech' ),
			'bridge_type' => 'inline_widget',
			'admin_url'   => admin_url( 'admin.php?page=zstock-dashboard' ),
		);
	}

	public function render_mobile_view( int $user_id ): void {
		echo '<iframe src="' . esc_url( admin_url( 'admin.php?page=zstock-dashboard&zdz_mobile=1' ) ) . '" style="width:100%;height:100%;border:none;"></iframe>';
	}

	/**
	 * Lightweight inline-widget skeleton. Heavy data loads via AJAX (widget.js). The markup is a
	 * container the script hydrates; it renders the same empty-safe view when the catalog is empty.
	 */
	public function render_dashboard_widget( int $user_id ): ?string {
		wp_enqueue_style( 'zstock-widget-css', ZSTOCK_URL . 'assets/css/widget.css', array(), zstock_asset_ver( 'assets/css/widget.css' ) );
		wp_enqueue_script( 'zstock-widget-js', ZSTOCK_URL . 'assets/js/widget.js', array(), zstock_asset_ver( 'assets/js/widget.js' ), true );

		$user = get_userdata( $user_id );
		wp_localize_script(
			'zstock-widget-js',
			'zstockWidgetData',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( ZSTOCK_NONCE ),
				'userId'   => $user_id,
				'userName' => $user ? $user->display_name : '',
				'version'  => ZSTOCK_VERSION,
				'dashUrl'  => admin_url( 'admin.php?page=zstock-dashboard' ),
			)
		);

		ob_start();
		?>
		<div class="zstock-w" id="zstock-widget">
			<div class="zstock-w-stats">
				<div class="zstock-w-stat" data-stat="total"><span class="zstock-w-stat-val">--</span><span class="zstock-w-stat-label"><?php esc_html_e( 'Total Items', 'zorderz' ); ?></span></div>
				<div class="zstock-w-stat" data-stat="low"><span class="zstock-w-stat-val">--</span><span class="zstock-w-stat-label"><?php esc_html_e( 'Low Stock', 'zorderz' ); ?></span></div>
				<div class="zstock-w-stat" data-stat="pending"><span class="zstock-w-stat-val">--</span><span class="zstock-w-stat-label"><?php esc_html_e( 'Pending Orders', 'zorderz' ); ?></span></div>
			</div>
			<div class="zstock-w-actions">
				<button class="zstock-w-btn zstock-w-btn-primary" data-action="upload" type="button"><?php esc_html_e( 'Upload Invoice', 'zorderz' ); ?></button>
				<button class="zstock-w-btn" data-action="cycle" type="button"><?php esc_html_e( 'Cycle Count', 'zorderz' ); ?></button>
				<button class="zstock-w-btn" data-action="sync" type="button"><?php esc_html_e( 'Sync', 'zorderz' ); ?></button>
			</div>
			<input type="file" class="zstock-w-upload-input" accept="image/jpeg,image/png,image/webp,application/pdf,image/gif" style="display:none" />
			<div class="zstock-w-cycle" style="display:none">
				<select class="zstock-w-cycle-item"></select>
				<input class="zstock-w-cycle-count" type="number" min="0" step="1" placeholder="0" />
				<button class="zstock-w-btn zstock-w-btn-primary" data-action="submit-cycle" type="button"><?php esc_html_e( 'Submit', 'zorderz' ); ?></button>
				<button class="zstock-w-btn" data-action="cancel-cycle" type="button"><?php esc_html_e( 'Cancel', 'zorderz' ); ?></button>
			</div>
			<div class="zstock-w-section">
				<h4 class="zstock-w-section-title"><?php esc_html_e( 'Low Stock Alerts', 'zorderz' ); ?></h4>
				<div class="zstock-w-list" data-list="alerts"><p class="zstock-w-empty"><?php esc_html_e( 'Loading…', 'zorderz' ); ?></p></div>
			</div>
			<div class="zstock-w-section">
				<h4 class="zstock-w-section-title"><?php esc_html_e( 'Recent Supplier Orders', 'zorderz' ); ?></h4>
				<div class="zstock-w-list" data-list="orders"><p class="zstock-w-empty"><?php esc_html_e( 'Loading…', 'zorderz' ); ?></p></div>
			</div>
			<a class="zstock-w-footer" href="<?php echo esc_url( admin_url( 'admin.php?page=zstock-dashboard' ) ); ?>"><?php esc_html_e( 'Open Full Dashboard', 'zorderz' ); ?> &rarr;</a>
		</div>
		<?php
		return ob_get_clean();
	}
}
