<?php
/**
 * ZJOB_Projects_App — the Projects hub as a dashboard app (app id `projects`).
 *
 * Everyone's place to see the work that is theirs: Mine first (what they made, are on, or were confirmed for), then
 * everything the Projects scope lets them see; each card with its install chip; an opened Project with where it stands,
 * its jobs, its people and its material orders. A link of the form /?zprj=<project id>[&at=next|orders] opens a
 * Project straight away. Never on the shared device.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZJOB_Projects_App implements \Zorderz\Widget_App_Interface {

	const APP_ID = 'projects';

	public function get_config(): array {
		return array(
			'id'          => self::APP_ID,
			'nm'          => __( 'Projects', 'zorderz' ),
			'icon'        => 'folder-kanban',
			'cat'         => 'Field',
			'cc'          => '#3E6FD8',
			'desc'        => __( 'Every project you are part of: where it stands, when it is installed, who is on it, and its material orders.', 'zorderz' ),
			'roles'       => (array) apply_filters( 'zjob_projects_roles', array( 'administrator', 'zdz_owner', 'zdz_admin', 'zdz_sales', 'zdz_operator', 'zdz_mfg', 'zdz_tech' ) ),
			'bridge_type' => 'inline_widget',
			'admin_url'   => '',
		);
	}

	public function render_mobile_view( int $user_id ): void {
		echo '<div class="zjob-fullscreen" data-app-id="projects">' . $this->body_html( $user_id ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped below.
	}

	public function render_dashboard_widget( int $user_id ): ?string {
		if ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $user_id ) ) {
			return null;
		}
		return $this->body_html( $user_id );
	}

	private function body_html( int $user_id ): string {
		$desk = class_exists( 'ZJOB_Project_Orders' ) && ZJOB_Project_Orders::desk_ok( $user_id )
			&& ! ( class_exists( 'ZJOB_Privacy_View' ) && ZJOB_Privacy_View::on() );
		wp_enqueue_style( 'zjob-projects', ZJOB_URL . 'assets/css/projects.css', array(), zjob_asset_ver( 'assets/css/projects.css' ) );
		wp_enqueue_script( 'zjob-order-paper', ZJOB_URL . 'assets/js/order-paper.js', array(), zjob_asset_ver( 'assets/js/order-paper.js' ), true );
		wp_enqueue_script( 'zjob-projects', ZJOB_URL . 'assets/js/projects.js', array( 'zjob-order-paper' ), zjob_asset_ver( 'assets/js/projects.js' ), true );
		wp_localize_script( 'zjob-projects', 'zjobProjects', array(
			'ajaxurl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( ZJOB_NONCE ),
			'readOnly'    => class_exists( 'ZJOB_Privacy_View' ) && ZJOB_Privacy_View::on(),
			'canRepair'   => class_exists( 'ZJOB_Repair_Log' ) && ZJOB_Repair_Log::can( $user_id ),
			'pdfLib'      => ZJOB_URL . 'assets/vendor/pdf-lib.min.js?ver=1.17.1',
			'via'         => array(
				'counter' => __( 'at the counter', 'zorderz' ),
				'phone'   => __( 'by phone', 'zorderz' ),
				'email'   => __( 'by email', 'zorderz' ),
				'form'    => __( 'on the order form', 'zorderz' ),
			),
		) );
		ob_start();
		?>
		<div class="zprj" id="zjob-projects">
			<div class="zprj-bar" role="group" aria-label="<?php esc_attr_e( 'Which projects', 'zorderz' ); ?>">
				<button type="button" class="zprj-tab is-active" data-view="mine"><?php esc_html_e( 'Mine', 'zorderz' ); ?></button>
				<button type="button" class="zprj-tab" data-view="all"><?php esc_html_e( 'All I can see', 'zorderz' ); ?></button>
				<?php if ( $desk ) : ?>
				<button type="button" class="zprj-tab" data-view="desk"><?php esc_html_e( 'Materials desk', 'zorderz' ); ?></button>
				<?php endif; ?>
				<input type="search" class="zprj-search" placeholder="<?php esc_attr_e( 'Name, address or project code', 'zorderz' ); ?>" aria-label="<?php esc_attr_e( 'Search projects', 'zorderz' ); ?>" />
			</div>
			<div class="zprj-list" aria-live="polite"><div class="zprj-empty"><?php esc_html_e( 'Loading', 'zorderz' ); ?>&hellip;</div></div>
			<div class="zprj-detail" hidden></div>
			<div class="zprj-desk" hidden></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
