<?php
/**
 * ZCC_App — the Commission dashboard app (theme Widget interface).
 *
 * Registers the tile + inline widget. The heavy data (a commission figure, a
 * unit tally, a piece-rate paycheck) loads asynchronously via REST after render,
 * so the skeleton here is light. Visibility is the theme's job:
 * ZDZ_Plugin_API::user_can_access_app gates the tile; the bridge enforces the
 * per-figure tier on read.
 *
 * @package Zorderz\Commission
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCC_App implements \Zorderz\Widget_App_Interface {

	public function get_config(): array {
		return [
			'id'          => ZCC_APP_ID,
			'nm'          => __( 'Commission', 'zorderz' ),
			'icon'        => 'calculator',
			'cat'         => 'Finance',
			'cc'          => '#059669',
			'desc'        => __( 'Deterministic commission & piece-rate pay from your invoices.', 'zorderz' ),
			'roles'       => (array) apply_filters( 'zcc_roles', [ 'administrator', 'zdz_owner', 'zdz_admin', 'zdz_sales', 'zdz_operator' ] ),
			'bridge_type' => 'inline_widget',
			'admin_url'   => admin_url( 'options-general.php?page=' . ZCC_Admin::PAGE ),
		];
	}

	public function render_mobile_view( int $user_id ): void {
		echo $this->skeleton( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- skeleton is escaped internally
	}

	public function render_dashboard_widget( int $user_id ): ?string {
		return $this->skeleton( $user_id );
	}

	/** Light, escaped skeleton; widget.js hydrates it from the REST routes. */
	private function skeleton( int $user_id ): string {
		$plan       = class_exists( 'ZDZ_Compensation' ) ? ZDZ_Compensation::get_plan( $user_id ) : [ 'is_piece_worker' => false, 'configured' => false ];
		$is_piece   = ! empty( $plan['is_piece_worker'] );
		$connected  = class_exists( 'ZCC_FreshBooks' ) && ZCC_FreshBooks::is_connected();

		ob_start();
		?>
		<div class="zcc-widget" data-app="<?php echo esc_attr( ZCC_APP_ID ); ?>">
			<?php if ( ! $connected ) : ?>
				<p class="zcc-note"><?php esc_html_e( 'Connect FreshBooks in settings to calculate commission.', 'zorderz' ); ?></p>
			<?php else : ?>
				<div class="zcc-controls">
					<label class="zcc-label"><?php esc_html_e( 'Period', 'zorderz' ); ?>
						<select class="zcc-period">
							<option value="this_month"><?php esc_html_e( 'This month', 'zorderz' ); ?></option>
							<option value="last_month"><?php esc_html_e( 'Last month', 'zorderz' ); ?></option>
							<option value="mtd"><?php esc_html_e( 'Month to date', 'zorderz' ); ?></option>
						</select>
					</label>
					<button type="button" class="zcc-run button"><?php echo $is_piece ? esc_html__( 'My pay', 'zorderz' ) : esc_html__( 'My commission', 'zorderz' ); ?></button>
				</div>
				<div class="zcc-result" data-piece="<?php echo $is_piece ? '1' : '0'; ?>" aria-live="polite"></div>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
