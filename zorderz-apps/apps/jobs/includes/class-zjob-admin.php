<?php
/**
 * Zorderz Jobs — admin settings screen (Settings → Zorderz Jobs).
 *
 * A minimal owner/admin surface for the app's operating-mode options. Jobs ships with
 * rules written for a TEAM — a dispatcher schedules the work and a distinct second
 * party signs off when it is done. A solo owner who runs everything is otherwise
 * blocked by those two-person rules.
 *
 * The headline option is SINGLE-OPERATOR MODE (option zjob_solo_operator): turning it
 * on lets that one person schedule their own jobs and close them with a RECORDED
 * single-party attestation — without weakening any multi-user guard for teams that
 * leave it off (the default). Everything here is strictly ADDITIVE and OFF by default:
 * with both boxes unchecked the app behaves exactly as it did before this screen
 * existed. The completion safety floor (finish photos; a solo close recorded as
 * `single_party_attested`, never laundered into `two_party`) is preserved even when on.
 *
 * Same home as the sibling Scheduler settings (Settings menu). Only manage_options
 * users reach this page.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZJOB_Admin {

	const CAP  = 'manage_options';
	const SLUG = 'zjob-settings';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_save' ) );
	}

	/** Settings → Zorderz Jobs. */
	public function menu(): void {
		add_options_page(
			__( 'Zorderz Jobs', 'zorderz' ),
			__( 'Zorderz Jobs', 'zorderz' ),
			self::CAP,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Persist the toggles. An unchecked checkbox is absent from POST, so each is stored
	 * explicitly as 1/0 — the option always reflects the box, never a stale value.
	 */
	public function maybe_save(): void {
		if ( empty( $_POST['zjob_settings_save'] ) ) {
			return;
		}
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'zorderz' ) );
		}
		check_admin_referer( 'zjob_settings' );

		update_option( 'zjob_solo_operator', ! empty( $_POST['zjob_solo_operator'] ) ? 1 : 0 );
		update_option( 'zjob_workers_may_self_schedule', ! empty( $_POST['zjob_workers_may_self_schedule'] ) ? 1 : 0 );

		add_settings_error( 'zjob', 'saved', __( 'Settings saved.', 'zorderz' ), 'updated' );
	}

	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'zorderz' ) );
		}
		$solo       = (bool) get_option( 'zjob_solo_operator', false );
		$self_sched = (bool) get_option( 'zjob_workers_may_self_schedule', false );
		// The effective self-schedule policy: solo mode implies it, and a filter may force it.
		$self_sched_effective = class_exists( 'ZJOB_Jobs' ) && ZJOB_Jobs::workers_may_self_schedule();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Zorderz Jobs — Settings', 'zorderz' ); ?></h1>
			<?php settings_errors( 'zjob' ); ?>

			<p style="max-width:740px">
				<?php esc_html_e( 'Jobs ships with rules written for a team: a dispatcher schedules the work, and a second person signs off when it is done. If ONE person runs everything, turn on single-operator mode so those two-person rules do not block you. Teams should leave this off — every multi-user guard then stays exactly as it is.', 'zorderz' ); ?>
			</p>

			<div style="margin:12px 0;padding:10px 14px;border-radius:8px;background:<?php echo $solo ? '#ecfdf5' : '#f8fafc'; ?>;border:1px solid <?php echo $solo ? '#a7f3d0' : '#e2e8f0'; ?>;max-width:740px">
				<strong><?php esc_html_e( 'Status:', 'zorderz' ); ?></strong>
				<?php
				if ( $solo ) {
					esc_html_e( 'Single-operator mode is ON — you can schedule and close your own jobs (with a recorded single-party attestation).', 'zorderz' );
				} else {
					esc_html_e( 'Single-operator mode is OFF (default) — a dispatcher schedules, and a distinct second party closes out.', 'zorderz' );
				}
				?>
			</div>

			<form method="post">
				<?php wp_nonce_field( 'zjob_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Single-operator mode', 'zorderz' ); ?></th>
						<td>
							<label>
								<input name="zjob_solo_operator" type="checkbox" value="1" <?php checked( $solo ); ?>>
								<?php esc_html_e( 'Single-operator mode (one person runs everything: allow self-scheduling and self-attested completion)', 'zorderz' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Off by default. When on, the person assigned a job may set its own time, and may close their own job with a written single-party attestation when no second person is available to sign off. Completion still requires the finish photos, and a solo close is recorded as single_party_attested — never as a two-party sign-off.', 'zorderz' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Let workers self-schedule', 'zorderz' ); ?></th>
						<td>
							<label>
								<input name="zjob_workers_may_self_schedule" type="checkbox" value="1" <?php checked( $self_sched ); ?>>
								<?php esc_html_e( 'Allow an assignee to schedule their own job (the workers_may_self_schedule capability)', 'zorderz' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Optional and independent of single-operator mode, for a shop that wants self-scheduling without full solo mode. Single-operator mode already includes this, so you only need this box for the self-schedule relaxation on its own. It does NOT relax completion — the two-party / photo-gated close still applies.', 'zorderz' ); ?>
								<?php if ( $self_sched_effective ) : ?>
									<br><strong><?php esc_html_e( 'Currently effective:', 'zorderz' ); ?></strong> <?php esc_html_e( 'assignees may schedule their own jobs.', 'zorderz' ); ?>
								<?php endif; ?>
							</p>
						</td>
					</tr>
				</table>
				<p><button type="submit" name="zjob_settings_save" value="1" class="button button-primary"><?php esc_html_e( 'Save settings', 'zorderz' ); ?></button></p>
			</form>
		</div>
		<?php
	}
}
