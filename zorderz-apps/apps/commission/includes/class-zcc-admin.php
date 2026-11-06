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
		add_action( 'admin_post_zcc_save_cost', [ __CLASS__, 'save_cost' ] );
		add_action( 'admin_post_zcc_move_costs', [ __CLASS__, 'move_costs' ] );
		add_action( 'admin_post_zcc_save_material', [ __CLASS__, 'save_material' ] );
		add_action( 'admin_post_zcc_ai', [ __CLASS__, 'ai_action' ] );
		add_action( 'admin_post_zcc_review_dismiss', [ __CLASS__, 'review_dismiss' ] );
	}

	/** Cost screens and actions: the administrator tier that may see costs (not View As, Privacy View or the shared device). */
	private static function costs_ok(): bool {
		return current_user_can( 'manage_options' ) && class_exists( 'ZCC_Visibility' ) && ZCC_Visibility::may_see_costs( get_current_user_id() );
	}

	private static function back( string $tab, array $args = [] ): void {
		wp_safe_redirect( add_query_arg( array_merge( [ 'page' => self::PAGE, 'tab' => $tab ], $args ), admin_url( 'options-general.php' ) ) );
		exit;
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
			'review'      => __( 'Review', 'zorderz' ),
			'costs'       => __( 'Costs', 'zorderz' ),
			'material'    => __( 'Material cost', 'zorderz' ),
			'coverage'    => __( 'Coverage', 'zorderz' ),
			'attribution' => __( 'Attribution', 'zorderz' ),
		];
		if ( ! self::costs_ok() ) {
			unset( $tabs['costs'], $tabs['material'], $tabs['review'] );
		}
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
			if ( isset( $_GET['zcc_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a notice after a redirect
				echo '<div class="notice notice-info"><p>' . esc_html( sanitize_text_field( wp_unslash( (string) $_GET['zcc_msg'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
			if ( $active === 'review' ) {
				self::render_review_tab();
			} elseif ( $active === 'costs' ) {
				self::render_costs_tab();
			} elseif ( $active === 'material' ) {
				self::render_material_tab();
			} elseif ( $active === 'coverage' ) {
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

	/** Review items (money nobody is paid on yet) and the Ai's proposals for unclear lines. */
	private static function render_review_tab(): void {
		$items = class_exists( 'ZCC_Review' ) ? ZCC_Review::open() : [];
		$props = class_exists( 'ZCC_Ai' ) ? ZCC_Ai::all() : [];
		?>
		<h2><?php esc_html_e( 'Waiting for a person', 'zorderz' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Nobody is paid on these until they are settled: set a rep override, record the installer, or mark the installer in Team Roles. Then dismiss the item.', 'zorderz' ); ?></p>
		<?php if ( ! $items ) : ?>
			<p><em><?php esc_html_e( 'Nothing waiting.', 'zorderz' ); ?></em></p>
		<?php else : ?>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Invoice', 'zorderz' ); ?></th><th><?php esc_html_e( 'Pay', 'zorderz' ); ?></th><th><?php esc_html_e( 'Why', 'zorderz' ); ?></th><th></th></tr></thead><tbody>
			<?php foreach ( $items as $key => $r ) : ?>
				<tr>
					<td><?php echo esc_html( (string) ( $r['invoice_number'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( 'install' === ( $r['role'] ?? '' ) ? __( 'Piece rate', 'zorderz' ) : __( 'Commission', 'zorderz' ) ); ?><?php echo ! empty( $r['item_id'] ) ? ' · ' . esc_html( (string) $r['item_id'] ) : ''; ?></td>
					<td><?php echo esc_html( ZCC_Attribution::reason_text( (string) ( $r['reason'] ?? '' ) ) ); ?></td>
					<td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="zcc_review_dismiss"><input type="hidden" name="key" value="<?php echo esc_attr( (string) $key ); ?>"><?php wp_nonce_field( 'zcc_review_dismiss' ); ?><button class="button"><?php esc_html_e( 'Settled', 'zorderz' ); ?></button></form></td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Ai proposals for unclear lines', 'zorderz' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Lines the catalog could not place are booked at no cost and flagged. The Ai can propose which catalog item each one is; nothing changes until you confirm a proposal, which teaches the classifier that exact line. Customer details are hidden from the Ai and from this list.', 'zorderz' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="zcc_ai"><input type="hidden" name="do" value="propose"><?php wp_nonce_field( 'zcc_ai' ); ?>
			<button class="button"><?php esc_html_e( 'Ask about this month’s unclear lines', 'zorderz' ); ?></button>
		</form>
		<?php if ( $props ) : ?>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Line', 'zorderz' ); ?></th><th><?php esc_html_e( 'Proposed item', 'zorderz' ); ?></th><th><?php esc_html_e( 'Status', 'zorderz' ); ?></th><th></th></tr></thead><tbody>
			<?php foreach ( array_reverse( $props, true ) as $key => $p ) : ?>
				<tr>
					<td><?php echo esc_html( (string) ( $p['shown'] ?? '' ) ); ?><?php echo '' !== (string) ( $p['note'] ?? '' ) ? '<br><small>' . esc_html( (string) $p['note'] ) . '</small>' : ''; ?></td>
					<td><?php echo esc_html( '' !== (string) ( $p['item_id'] ?? '' ) ? (string) $p['item_id'] : ( ! empty( $p['non_product'] ) ? __( '(not a product)', 'zorderz' ) : __( '(no item)', 'zorderz' ) ) ); ?></td>
					<td><?php echo esc_html( (string) ( $p['status'] ?? '' ) ); ?></td>
					<td>
						<?php if ( 'proposed' === ( $p['status'] ?? '' ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="zcc_ai"><input type="hidden" name="key" value="<?php echo esc_attr( (string) $key ); ?>"><?php wp_nonce_field( 'zcc_ai' ); ?>
							<input type="text" name="item_id" class="regular-text" placeholder="<?php echo esc_attr( (string) ( $p['item_id'] ?? '' ) ); ?>">
							<button class="button button-primary" name="do" value="confirm"><?php esc_html_e( 'Confirm', 'zorderz' ); ?></button>
							<button class="button" name="do" value="reject"><?php esc_html_e( 'Reject', 'zorderz' ); ?></button>
						</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>
		<?php endif; ?>
		<?php
	}

	/** The private cost book: add or change a cost-each entry; move costs out of the public catalog. */
	private static function render_costs_tab(): void {
		$entries = ZCC_Cost_Book::entries();
		$public  = ZCC_Cost_Book::catalog_costs();
		$edit    = isset( $_GET['item'] ) ? sanitize_key( wp_unslash( (string) $_GET['item'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only form prefill
		?>
		<h2><?php esc_html_e( 'Costs', 'zorderz' ); ?></h2>
		<p class="description"><?php esc_html_e( 'What each catalog item costs the business, for commission arithmetic. Kept here, readable by administrators only. Nothing is pre-filled.', 'zorderz' ); ?></p>
		<?php if ( $public ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php
				/* translators: %d: number of catalog items. */
				printf( esc_html__( '%d catalog item(s) keep a cost in their attributes, which every signed-in person can read. Move them here:', 'zorderz' ), count( $public ) );
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="zcc_move_costs"><?php wp_nonce_field( 'zcc_move_costs' ); ?><p><button class="button"><?php esc_html_e( 'Move costs into the cost book', 'zorderz' ); ?></button></p></form></div>
		<?php endif; ?>
		<table class="widefat striped" style="max-width:720px"><thead><tr><th><?php esc_html_e( 'Item', 'zorderz' ); ?></th><th><?php esc_html_e( 'Kind', 'zorderz' ); ?></th><th><?php esc_html_e( 'Cost', 'zorderz' ); ?></th><th></th></tr></thead><tbody>
		<?php foreach ( $entries as $id => $e ) : ?>
			<tr><td><?php echo esc_html( (string) $id ); ?></td><td><?php echo esc_html( (string) ( $e['cost_type'] ?? 'per_unit' ) ); ?></td><td><?php echo esc_html( isset( $e['cost'] ) ? '$' . number_format( (float) $e['cost'], 2 ) : ( isset( $e['cost_percent'] ) ? (float) $e['cost_percent'] . '%' : '' ) ); ?></td>
			<td><?php if ( in_array( (string) ( $e['cost_type'] ?? 'per_unit' ), [ 'per_unit', 'fixed' ], true ) ) : ?><a href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE, 'tab' => 'costs', 'item' => $id ], admin_url( 'options-general.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'zorderz' ); ?></a><?php endif; ?></td></tr>
		<?php endforeach; ?>
		</tbody></table>
		<h3><?php esc_html_e( 'Add or change a cost (cost each)', 'zorderz' ); ?></h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="zcc_save_cost"><?php wp_nonce_field( 'zcc_save_cost' ); ?>
			<label><?php esc_html_e( 'Catalog item id', 'zorderz' ); ?> <input type="text" name="item_id" value="<?php echo esc_attr( $edit ); ?>" <?php echo '' !== $edit ? 'readonly' : ''; ?>></label>
			<label><?php esc_html_e( 'Cost each', 'zorderz' ); ?> <input type="number" step="0.01" min="0" name="cost" value="<?php echo esc_attr( '' !== $edit && isset( $entries[ $edit ]['cost'] ) ? (string) (float) $entries[ $edit ]['cost'] : '' ); ?>"></label>
			<?php submit_button( __( 'Save cost', 'zorderz' ), 'primary', 'submit', false ); ?>
		</form>
		<?php
	}

	/** The material cost rules and the what-if report. */
	private static function render_material_tab(): void {
		$c = ZCC_Material_Cost::get();
		$areas = '';
		foreach ( $c['areas'] as $n => $r ) {
			$areas .= $n . ' | ' . $r . "\n";
		}
		$fams = '';
		foreach ( $c['families'] as $f => $g ) {
			$fams .= $f . ' | ' . $g . "\n";
		}
		$groups = '';
		foreach ( $c['groups'] as $g => $l ) {
			$groups .= $g . ' | ' . $l . ' | ' . ( $c['tax_areas'][ $g ] ?? '' ) . "\n";
		}
		?>
		<h2><?php esc_html_e( 'Material cost rules', 'zorderz' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Price-book families whose lines take the supplier cost from the price book, and the purchase tax paid on materials by supplier group. They change nothing until the start date, and then only invoices paid on or after it. Locked months are never recalculated. Set the start date only once the written commission plan says so.', 'zorderz' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="zcc_save_material"><?php wp_nonce_field( 'zcc_save_material' ); ?>
			<p><label><?php esc_html_e( 'Start date (YYYY-MM-DD, empty = off)', 'zorderz' ); ?> <input type="text" name="start_date" value="<?php echo esc_attr( (string) $c['start_date'] ); ?>"></label>
			<label><input type="checkbox" name="confirm_past" value="1"> <?php esc_html_e( 'The signed plan covers invoices paid since that day', 'zorderz' ); ?></label></p>
			<p><label><?php esc_html_e( 'Tax areas: one per line, "name | rate %"', 'zorderz' ); ?><br><textarea name="areas" rows="4" cols="60"><?php echo esc_textarea( $areas ); ?></textarea></label></p>
			<p><label><?php esc_html_e( 'Supplier groups: "key | label | tax area"', 'zorderz' ); ?><br><textarea name="groups" rows="4" cols="60"><?php echo esc_textarea( $groups ); ?></textarea></label></p>
			<p><label><?php esc_html_e( 'Price-book families: "family key | supplier group"', 'zorderz' ); ?><br><textarea name="families" rows="3" cols="60"><?php echo esc_textarea( $fams ); ?></textarea></label></p>
			<?php submit_button( __( 'Save material cost rules', 'zorderz' ) ); ?>
		</form>
		<?php
	}

	/** "a | b | c" lines → rows of trimmed cells. */
	private static function rows( string $text ): array {
		$out = [];
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$cells = array_map( 'trim', explode( '|', $line ) );
			if ( '' !== ( $cells[0] ?? '' ) ) {
				$out[] = $cells;
			}
		}
		return $out;
	}

	public static function save_material(): void {
		if ( ! self::costs_ok() || ! check_admin_referer( 'zcc_save_material' ) ) {
			wp_die( 'Forbidden' );
		}
		$in = [
			'start_date'   => isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['start_date'] ) ) : '',
			'confirm_past' => ! empty( $_POST['confirm_past'] ),
			'areas'        => [],
			'groups'       => [],
			'tax_areas'    => [],
			'families'     => [],
		];
		foreach ( self::rows( isset( $_POST['areas'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['areas'] ) ) : '' ) as $r ) {
			$in['areas'][ $r[0] ] = $r[1] ?? '';
		}
		foreach ( self::rows( isset( $_POST['groups'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['groups'] ) ) : '' ) as $r ) {
			$in['groups'][ $r[0] ] = $r[1] ?? $r[0];
			if ( '' !== ( $r[2] ?? '' ) ) {
				$in['tax_areas'][ $r[0] ] = $r[2];
			}
		}
		foreach ( self::rows( isset( $_POST['families'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['families'] ) ) : '' ) as $r ) {
			$in['families'][ $r[0] ] = $r[1] ?? 'other';
		}
		$res = ZCC_Material_Cost::save( $in, get_current_user_id() );
		self::back( 'material', [ 'zcc_msg' => ! empty( $res['ok'] ) ? __( 'Saved.', 'zorderz' ) : (string) $res['error'] ] );
	}

	public static function save_cost(): void {
		if ( ! self::costs_ok() || ! check_admin_referer( 'zcc_save_cost' ) ) {
			wp_die( 'Forbidden' );
		}
		$res = ZCC_Cost_Book::save_entry(
			isset( $_POST['item_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['item_id'] ) ) : '',
			isset( $_POST['cost'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['cost'] ) ) : '',
			get_current_user_id()
		);
		self::back( 'costs', [ 'zcc_msg' => ! empty( $res['ok'] ) ? __( 'Cost saved.', 'zorderz' ) : (string) $res['error'] ] );
	}

	public static function move_costs(): void {
		if ( ! self::costs_ok() || ! check_admin_referer( 'zcc_move_costs' ) ) {
			wp_die( 'Forbidden' );
		}
		$n = ZCC_Cost_Book::move_catalog_costs( get_current_user_id() );
		/* translators: %d: number of items. */
		self::back( 'costs', [ 'zcc_msg' => sprintf( __( '%d item(s) moved into the cost book.', 'zorderz' ), $n ) ] );
	}

	public static function ai_action(): void {
		if ( ! self::costs_ok() || ! check_admin_referer( 'zcc_ai' ) ) {
			wp_die( 'Forbidden' );
		}
		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( (string) $_POST['do'] ) ) : '';
		$uid = get_current_user_id();
		if ( 'propose' === $do ) {
			$res = ZCC_Ai::propose( ZCC_Ai::unclear_lines( ZCC_FreshBooks::get_invoices( current_time( 'Y-m-01' ), current_time( 'Y-m-d' ) ) ), $uid );
			$msg = ! empty( $res['ok'] ) ? sprintf( /* translators: %d: proposals. */ __( '%d proposal(s).', 'zorderz' ), count( (array) ( $res['proposals'] ?? [] ) ) )
				: ( 'too_soon' === ( $res['error'] ?? '' ) ? __( 'Asked a few minutes ago; try again later.', 'zorderz' ) : __( 'The Ai did not answer; nothing changed.', 'zorderz' ) );
		} else {
			$res = ZCC_Ai::settle(
				isset( $_POST['key'] ) ? sanitize_key( wp_unslash( (string) $_POST['key'] ) ) : '',
				'confirm' === $do ? 'confirm' : 'reject',
				isset( $_POST['item_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['item_id'] ) ) : '',
				$uid
			);
			$msg = ! empty( $res['ok'] ) ? __( 'Done.', 'zorderz' ) : __( 'Not changed: ', 'zorderz' ) . (string) ( $res['error'] ?? '' );
		}
		self::back( 'review', [ 'zcc_msg' => $msg ] );
	}

	public static function review_dismiss(): void {
		if ( ! self::costs_ok() || ! check_admin_referer( 'zcc_review_dismiss' ) ) {
			wp_die( 'Forbidden' );
		}
		ZCC_Review::dismiss( isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['key'] ) ) : '' );
		self::back( 'review' );
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
