<?php
/**
 * ZCC_Admin — the Commission settings screen.
 *
 * Edits the Compensation Core service's global mechanism config, the piece-rate
 * table (keyed by Item Engine item id — the parity join), and the product-scoped
 * minimum-commission rules. Nothing here is pre-filled: every field ships empty,
 * and the screen shows the parity self-test so an admin can confirm counts and
 * rates stay consistent after any edit.
 *
 * @package Zorderz\Commission
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCC_Admin {

	const PAGE = 'zcc-settings';

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_post_zcc_save_rates', [ __CLASS__, 'save_rates' ] );
	}

	public static function menu(): void {
		add_submenu_page(
			'options-general.php',
			__( 'Commission', 'zorderz' ),
			__( 'Commission', 'zorderz' ),
			'manage_options',
			self::PAGE,
			[ __CLASS__, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$rates    = class_exists( 'ZDZ_Compensation' ) ? ZDZ_Compensation::piece_rates() : [];
		$selftest = class_exists( 'ZCC_Self_Test' ) ? ZCC_Self_Test::run() : [ 'passed' => false, 'results' => [] ];
		$payable  = class_exists( 'ZCC_Installer_Pay' ) ? ZCC_Installer_Pay::payable_item_ids() : [];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Commission', 'zorderz' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'All compensation data ships EMPTY and is the most commercially sensitive in the platform. Per-rep plans are set on each user\'s profile; the piece-rate table below is keyed by Item Engine item id so counts and rates can never drift apart.', 'zorderz' ); ?>
			</p>

			<h2><?php esc_html_e( 'Parity self-test', 'zorderz' ); ?></h2>
			<p>
				<strong><?php echo $selftest['passed'] ? '✅ ' . esc_html__( 'PASS', 'zorderz' ) : '❌ ' . esc_html__( 'FAIL', 'zorderz' ); ?></strong>
				— <?php esc_html_e( 'counts × rates join, replayed on a synthetic ledger.', 'zorderz' ); ?>
			</p>
			<ul>
				<?php foreach ( (array) $selftest['results'] as $r ) : ?>
					<li><?php echo ( ! empty( $r['ok'] ) ? '✅' : '❌' ) . ' ' . esc_html( $r['name'] ) . ' — ' . esc_html( $r['detail'] ); ?></li>
				<?php endforeach; ?>
			</ul>

			<h2><?php esc_html_e( 'Piece rates (per item)', 'zorderz' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="zcc_save_rates">
				<?php wp_nonce_field( 'zcc_save_rates' ); ?>
				<table class="form-table">
					<?php
					$ids = array_values( array_unique( array_merge( array_keys( $rates ), $payable ) ) );
					if ( empty( $ids ) ) :
						?>
						<tr><td><em><?php esc_html_e( 'No item ids yet. Add countable items to the Item Engine (with a bench_payable attribute), then set a $/unit rate here.', 'zorderz' ); ?></em></td></tr>
					<?php else : ?>
						<?php foreach ( $ids as $item_id ) : $row = $rates[ $item_id ] ?? [ 'rate' => '', 'unit' => 'per_item' ]; ?>
							<tr>
								<th><label><?php echo esc_html( $item_id ); ?></label></th>
								<td>
									<input type="number" step="0.01" min="0" name="rates[<?php echo esc_attr( $item_id ); ?>][rate]" value="<?php echo esc_attr( $row['rate'] === '' ? '' : (float) $row['rate'] ); ?>" class="small-text">
									<input type="hidden" name="rates[<?php echo esc_attr( $item_id ); ?>][unit]" value="<?php echo esc_attr( $row['unit'] ?? 'per_item' ); ?>">
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</table>
				<?php submit_button( __( 'Save piece rates', 'zorderz' ) ); ?>
			</form>
		</div>
		<?php
	}

	public static function save_rates(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'zcc_save_rates' ) ) {
			wp_die( 'Forbidden' );
		}
		$rows = isset( $_POST['rates'] ) && is_array( $_POST['rates'] ) ? wp_unslash( $_POST['rates'] ) : [];
		if ( class_exists( 'ZDZ_Compensation' ) ) {
			ZDZ_Compensation::save_piece_rates( $rows );
		}
		wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE, 'saved' => 1 ], admin_url( 'options-general.php' ) ) );
		exit;
	}
}
