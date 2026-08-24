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
		add_action( 'admin_post_zcc_save_t2', [ __CLASS__, 'save_t2_toggle' ] );
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
		$tabs = [
			'rates'       => __( 'Piece rates', 'zorderz' ),
			'coverage'    => __( 'Coverage', 'zorderz' ),
			'attribution' => __( 'Attribution', 'zorderz' ),
		];
		$active = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'rates'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab select
		if ( ! isset( $tabs[ $active ] ) ) {
			$active = 'rates';
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Commission', 'zorderz' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'All compensation data ships EMPTY and is the most commercially sensitive in the platform. Per-rep plans are set on each user\'s profile; the piece-rate table below is keyed by Item Engine item id so counts and rates can never drift apart.', 'zorderz' ); ?>
			</p>
			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a class="nav-tab<?php echo $active === $slug ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE, 'tab' => $slug ], admin_url( 'options-general.php' ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</h2>
			<?php
			if ( $active === 'coverage' ) {
				self::render_coverage_tab();
			} elseif ( $active === 'attribution' ) {
				self::render_attribution_tab();
			} else {
				self::render_rates_tab();
			}
			?>
		</div>
		<?php
	}

	/** The piece-rate + parity self-test tab (the original settings screen). */
	private static function render_rates_tab(): void {
		$rates    = class_exists( 'ZDZ_Compensation' ) ? ZDZ_Compensation::piece_rates() : [];
		$selftest = class_exists( 'ZCC_Self_Test' ) ? ZCC_Self_Test::run() : [ 'passed' => false, 'results' => [] ];
		$payable  = class_exists( 'ZCC_Installer_Pay' ) ? ZCC_Installer_Pay::payable_item_ids() : [];
		?>
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
		<?php
	}

	/**
	 * Coverage telemetry — ADMIN-ONLY. Reports COUNTS + the single unassigned-
	 * revenue total (company-wide revenue with no rep code) and the lookback gauge.
	 * No per-person figure, no rate, no COGS. The snapshot is computed on demand
	 * (a fetch is only run when the admin clicks "Measure now") so page loads stay
	 * cheap; it is a pure read-only step and cannot move a dollar.
	 */
	private static function render_coverage_tab(): void {
		$connected = class_exists( 'ZCC_FreshBooks' ) && ZCC_FreshBooks::is_connected();
		$lookback  = class_exists( 'ZCC_Coverage' ) ? ZCC_Coverage::lookback_days() : 75;
		$measure   = isset( $_GET['measure'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only diagnostic, no state change
		?>
		<h2><?php esc_html_e( 'Attribution coverage', 'zorderz' ); ?></h2>
		<p class="description">
			<?php
			printf(
				/* translators: %d: payment-lookback floor in days. */
				esc_html__( 'Read-only observability: is every fetched invoice attributed to a rep, and is anything sitting past the %d-day payment-lookback floor where it could vanish silently? This surface reports counts and one unassigned-revenue total only — never an individual\'s pay. It cannot change a payout.', 'zorderz' ),
				(int) $lookback
			);
			?>
		</p>
		<?php if ( ! $connected ) : ?>
			<p><em><?php esc_html_e( 'Connect FreshBooks to measure coverage. With no connection the snapshot is all zeros.', 'zorderz' ); ?></em></p>
		<?php endif; ?>
		<p>
			<a class="button" href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE, 'tab' => 'coverage', 'measure' => 1 ], admin_url( 'options-general.php' ) ) ); ?>"><?php esc_html_e( 'Measure now (this month)', 'zorderz' ); ?></a>
		</p>
		<?php
		if ( $measure && $connected && class_exists( 'ZCC_Coverage' ) ) {
			$start = current_time( 'Y-m-01' );
			$end   = current_time( 'Y-m-d' );
			$cov   = [];
			try {
				$invoices = ZCC_FreshBooks::get_invoices( $start, $end );
				$cov      = ZCC_Coverage::snapshot( is_array( $invoices ) ? $invoices : [] );
			} catch ( \Throwable $e ) {
				echo '<p><em>' . esc_html__( 'Coverage fetch failed; see the error log.', 'zorderz' ) . '</em></p>';
				$cov = [];
			}
			if ( $cov ) {
				$binding = ( (int) ( $cov['gap_over_floor'] ?? 0 ) ) > 0;
				?>
				<table class="widefat striped" style="max-width:640px">
					<tbody>
						<tr><th><?php esc_html_e( 'Invoices fetched', 'zorderz' ); ?></th><td><?php echo (int) $cov['fetched']; ?></td></tr>
						<tr><th><?php esc_html_e( 'Attributed (has a rep code)', 'zorderz' ); ?></th><td><?php echo (int) $cov['attributed']; ?></td></tr>
						<tr><th><?php esc_html_e( 'Unattributed (no rep code)', 'zorderz' ); ?></th><td><?php echo (int) $cov['unattributed']; ?></td></tr>
						<tr><th><?php esc_html_e( 'Unassigned revenue (company-wide)', 'zorderz' ); ?></th><td>$<?php echo esc_html( number_format( (float) $cov['unattributed_rev'], 2 ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Would attribute if Tier-2 live (shadow-ready)', 'zorderz' ); ?></th><td><?php echo (int) $cov['shadow_ready']; ?></td></tr>
						<tr><th><?php esc_html_e( 'Payment lookback floor', 'zorderz' ); ?></th><td><?php echo (int) $cov['lookback_days']; ?> <?php esc_html_e( 'days', 'zorderz' ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Paid near the floor (0.8×–1×)', 'zorderz' ); ?></th><td><?php echo (int) $cov['gap_near_floor']; ?></td></tr>
						<tr><th><?php esc_html_e( 'Paid PAST the floor (at risk)', 'zorderz' ); ?></th><td><?php echo ( $binding ? '⚠ ' : '' ) . (int) $cov['gap_over_floor']; ?></td></tr>
						<tr><th><?php esc_html_e( 'Largest payment gap seen', 'zorderz' ); ?></th><td><?php echo (int) $cov['max_gap_days']; ?> <?php esc_html_e( 'days', 'zorderz' ); ?></td></tr>
					</tbody>
				</table>
				<?php if ( $binding ) : ?>
					<p><strong>⚠ <?php esc_html_e( 'One or more invoices were collected past the lookback floor. Widen the floor (the zcc_payment_lookback_days filter) so a late-paid invoice cannot be missed.', 'zorderz' ); ?></strong></p>
				<?php endif; ?>
				<?php
			}
		}
	}

	/**
	 * Attribution mode tab — the Tier-2 shadow control. Shows the mode, the live
	 * roster allow-list (initials only), a pre-flip checklist, and the reversible,
	 * nonce-guarded `zcc_t2_live` toggle. Ships OFF (SHADOW). The plugin NEVER
	 * flips itself — going live is a human, pay-affecting decision.
	 */
	private static function render_attribution_tab(): void {
		$live   = class_exists( 'ZCC_FreshBooks' ) && ZCC_FreshBooks::is_t2_live();
		$roster = class_exists( 'ZCC_FreshBooks' ) ? ZCC_FreshBooks::t2_roster() : [];
		?>
		<h2><?php esc_html_e( 'Tier-2 attribution (bare initials)', 'zorderz' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Tier-1 is the parenthesised document code on the invoice. Tier-2 is a roster-guarded second opinion on bare initials in the free text. It runs in SHADOW: it records what it would attribute for comparison, and pays nothing. Only the toggle below can ever let it feed pay — and it ships OFF.', 'zorderz' ); ?>
		</p>
		<p>
			<strong><?php esc_html_e( 'Mode:', 'zorderz' ); ?></strong>
			<?php echo $live
				? '<span style="color:#b32d2e">' . esc_html__( 'LIVE — Tier-2 may feed pay', 'zorderz' ) . '</span>'
				: '<span style="color:#2271b1">' . esc_html__( 'SHADOW (OFF) — records only, never pays', 'zorderz' ) . '</span>'; ?>
		</p>

		<h3><?php esc_html_e( 'Roster allow-list', 'zorderz' ); ?></h3>
		<p class="description"><?php esc_html_e( 'A bare-initials match may hit ONLY these configured initials. An empty list means the shadow is inert.', 'zorderz' ); ?></p>
		<?php if ( empty( $roster ) ) : ?>
			<p><em><?php esc_html_e( 'No roster configured — the Tier-2 shadow is inert.', 'zorderz' ); ?></em></p>
		<?php else : ?>
			<p><code><?php echo esc_html( implode( ', ', array_map( 'strval', $roster ) ) ); ?></code></p>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Before you flip Tier-2 live', 'zorderz' ); ?></h3>
		<ol>
			<li><?php esc_html_e( 'Confirm every initial above is a real, current rep — an ambiguous or stale code is never guessed, but a live flip pays what it does match.', 'zorderz' ); ?></li>
			<li><?php esc_html_e( 'Confirm reserved place/source tokens are declared so caps-prose is rejected, not attributed.', 'zorderz' ); ?></li>
			<li><?php esc_html_e( 'Review the shadow output against Tier-1 for a full period first — Tier-2 only fills gaps Tier-1 left.', 'zorderz' ); ?></li>
			<li><?php esc_html_e( 'Turning it live changes pay. It is a human decision; the plugin never flips itself.', 'zorderz' ); ?></li>
		</ol>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="zcc_save_t2">
			<?php wp_nonce_field( 'zcc_save_t2' ); ?>
			<label>
				<input type="checkbox" name="zcc_t2_live" value="yes" <?php checked( $live ); ?>>
				<?php esc_html_e( 'Tier-2 attribution is LIVE (feeds pay). Leave unchecked to keep it in shadow.', 'zorderz' ); ?>
			</label>
			<?php submit_button( __( 'Save attribution mode', 'zorderz' ) ); ?>
		</form>
		<?php
	}

	public static function save_t2_toggle(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'zcc_save_t2' ) ) {
			wp_die( 'Forbidden' );
		}
		// Reversible, explicit, nonce-guarded. Anything but an explicit "yes" is OFF.
		$live = isset( $_POST['zcc_t2_live'] ) && (string) wp_unslash( $_POST['zcc_t2_live'] ) === 'yes';
		update_option( 'zcc_t2_live', $live ? 'yes' : 'no', false );
		wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE, 'tab' => 'attribution', 'saved' => 1 ], admin_url( 'options-general.php' ) ) );
		exit;
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
