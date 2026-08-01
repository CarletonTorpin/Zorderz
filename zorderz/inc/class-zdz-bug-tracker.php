<?php
/**
 * Bug Tracker — User-Facing Bug Reporting + Admin Dashboard
 *
 * Handles:
 * - `zdz_bug_report` CPT registration
 * - REST API endpoint for user submissions
 * - REST API endpoint for admin status updates
 * - Admin columns, status management, and dashboard
 * - Audit log integration
 *
 * @package Zorderz
 * @since   2.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Bug_Tracker {

	/* ──────────────────────────────────────────────
	 * Constants
	 * ────────────────────────────────────────────── */

	/** Valid categories for bug reports. */
	const CATEGORIES = [ 'bug', 'feature', 'other' ];

	/** Valid statuses for bug reports. */
	const STATUSES = [ 'new', 'in_progress', 'resolved', 'closed' ];

	/** Status display config: label and color. */
	const STATUS_LABELS = [
		'new'         => [ 'label' => 'New',         'color' => '#EF4444' ],
		'in_progress' => [ 'label' => 'In Progress', 'color' => '#F59E0B' ],
		'resolved'    => [ 'label' => 'Resolved',    'color' => '#10B981' ],
		'closed'      => [ 'label' => 'Closed',      'color' => '#6B7280' ],
	];

	/** Category display config. */
	const CATEGORY_LABELS = [
		'bug'     => [ 'label' => 'Bug',        'icon' => 'alert-circle',   'color' => '#EF4444' ],
		'feature' => [ 'label' => 'Suggestion',  'icon' => 'lightbulb',     'color' => '#8B5CF6' ],
		'other'   => [ 'label' => 'Other',       'icon' => 'message-circle','color' => '#3B82F6' ],
	];

	/* ──────────────────────────────────────────────
	 * Constructor
	 * ────────────────────────────────────────────── */

	public function __construct() {
		add_action( 'init', [ $this, 'register_cpt' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );

		// Custom admin columns.
		add_filter( 'manage_ts_bug_report_posts_columns', [ $this, 'set_admin_columns' ] );
		add_action( 'manage_ts_bug_report_posts_custom_column', [ $this, 'render_admin_column' ], 10, 2 );
		add_filter( 'manage_edit-zdz_bug_report_sortable_columns', [ $this, 'sortable_columns' ] );

		// Row actions.
		add_filter( 'post_row_actions', [ $this, 'row_actions' ], 10, 2 );

		// Handle quick status change from admin list.
		add_action( 'admin_init', [ $this, 'handle_status_change' ] );

		// Admin styles for the CPT list.
		add_action( 'admin_head', [ $this, 'admin_styles' ] );

		// Meta boxes on the bug report edit screen.
		add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
		add_action( 'save_post_ts_bug_report', [ $this, 'save_meta_boxes' ] );
	}

	/* ──────────────────────────────────────────────
	 * CPT Registration
	 * ────────────────────────────────────────────── */

	public function register_cpt() {
		register_post_type( 'zdz_bug_report', [
			'labels'          => [
				'name'               => __( 'Bug Reports', 'zorderz' ),
				'singular_name'      => __( 'Bug Report', 'zorderz' ),
				'all_items'          => __( 'All Reports', 'zorderz' ),
				'search_items'       => __( 'Search Reports', 'zorderz' ),
				'not_found'          => __( 'No reports found.', 'zorderz' ),
				'not_found_in_trash' => __( 'No reports found in Trash.', 'zorderz' ),
			],
			'public'          => false,
			'show_ui'         => current_user_can( 'manage_options' ),
			'show_in_menu'    => false,
			'supports'        => [ 'title', 'editor' ],
			'capability_type' => 'post',
			'capabilities'    => [
				'create_posts' => 'do_not_allow',
			],
			'map_meta_cap'    => true,
		] );
	}

	/* ──────────────────────────────────────────────
	 * Admin Menu
	 * ────────────────────────────────────────────── */

	public function add_admin_menu() {
		$new_count = $this->get_new_count();
		$badge     = $new_count > 0
			? sprintf( ' <span class="awaiting-mod">%d</span>', $new_count )
			: '';

		add_submenu_page(
			'zdz-core-settings',
			__( 'Bug Reports', 'zorderz' ),
			__( 'Bug Reports', 'zorderz' ) . $badge,
			'manage_options',
			'edit.php?post_type=zdz_bug_report'
		);
	}

	/* ──────────────────────────────────────────────
	 * Admin Columns
	 * ────────────────────────────────────────────── */

	public function set_admin_columns( $columns ) {
		$new = [];
		$new['cb']          = $columns['cb'];
		$new['title']       = __( 'Report', 'zorderz' );
		$new['zdz_status']   = __( 'Status', 'zorderz' );
		$new['zdz_category'] = __( 'Category', 'zorderz' );
		$new['zdz_reporter'] = __( 'Reporter', 'zorderz' );
		$new['zdz_context']  = __( 'Context', 'zorderz' );
		$new['date']        = $columns['date'];
		return $new;
	}

	public function render_admin_column( $column, $post_id ) {
		switch ( $column ) {
			case 'zdz_status':
				$status = get_post_meta( $post_id, '_ts_bug_status', true ) ?: 'new';
				$info   = self::STATUS_LABELS[ $status ] ?? self::STATUS_LABELS['new'];
				printf(
					'<span class="zdz-bug-status-badge" style="background:%s20;color:%s;border:1px solid %s40;">%s</span>',
					esc_attr( $info['color'] ),
					esc_attr( $info['color'] ),
					esc_attr( $info['color'] ),
					esc_html( $info['label'] )
				);
				break;

			case 'zdz_category':
				$cat  = get_post_meta( $post_id, '_ts_bug_category', true ) ?: 'bug';
				$info = self::CATEGORY_LABELS[ $cat ] ?? self::CATEGORY_LABELS['bug'];
				printf(
					'<span style="color:%s;">%s</span>',
					esc_attr( $info['color'] ),
					esc_html( $info['label'] )
				);
				break;

			case 'zdz_reporter':
				$author_id = get_post_field( 'post_author', $post_id );
				$user      = get_userdata( $author_id );
				if ( $user ) {
					$role = ! empty( $user->roles ) ? $user->roles[0] : '';
					printf(
						'%s<br><small style="color:#6B7280;">%s</small>',
						esc_html( $user->display_name ),
						esc_html( $role )
					);
				}
				break;

			case 'zdz_context':
				$debug = get_post_meta( $post_id, '_ts_debug_data', true );
				if ( $debug ) {
					$data = json_decode( $debug, true );
					if ( is_array( $data ) ) {
						$view = $data['currentView'] ?? '';
						$app  = $data['currentApp'] ?? '';
						if ( $view ) {
							printf( '<small>%s</small>', esc_html( $view ) );
						}
						if ( $app && 'None' !== $app ) {
							printf( '<br><small style="color:#6B7280;">App: %s</small>', esc_html( $app ) );
						}
					}
				}
				break;
		}
	}

	public function sortable_columns( $columns ) {
		$columns['zdz_status'] = 'zdz_status';
		return $columns;
	}

	/* ──────────────────────────────────────────────
	 * Row Actions — Quick Status Change
	 * ────────────────────────────────────────────── */

	public function row_actions( $actions, $post ) {
		if ( 'zdz_bug_report' !== $post->post_type || ! current_user_can( 'manage_options' ) ) {
			return $actions;
		}

		$current = get_post_meta( $post->ID, '_ts_bug_status', true ) ?: 'new';

		// Add status change actions.
		foreach ( self::STATUSES as $status ) {
			if ( $status === $current ) {
				continue;
			}
			$info = self::STATUS_LABELS[ $status ];
			$url  = wp_nonce_url(
				add_query_arg( [
					'zdz_bug_action' => 'change_status',
					'zdz_bug_id'     => $post->ID,
					'zdz_new_status' => $status,
				], admin_url( 'edit.php?post_type=zdz_bug_report' ) ),
				'zdz_bug_status_' . $post->ID
			);
			$actions[ 'zdz_' . $status ] = sprintf(
				'<a href="%s" style="color:%s;">%s %s</a>',
				esc_url( $url ),
				esc_attr( $info['color'] ),
				'&#x2192;',
				esc_html( $info['label'] )
			);
		}

		// View debug data.
		$actions['zdz_debug'] = sprintf(
			'<a href="%s">View Debug Data</a>',
			esc_url( get_edit_post_link( $post->ID ) )
		);

		return $actions;
	}

	public function handle_status_change() {
		if ( empty( $_GET['zdz_bug_action'] ) || 'change_status' !== $_GET['zdz_bug_action'] ) {
			return;
		}

		$post_id    = absint( $_GET['zdz_bug_id'] ?? 0 );
		$new_status = sanitize_text_field( $_GET['zdz_new_status'] ?? '' );

		if ( ! $post_id || ! in_array( $new_status, self::STATUSES, true ) ) {
			return;
		}

		check_admin_referer( 'zdz_bug_status_' . $post_id );

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$old_status = get_post_meta( $post_id, '_ts_bug_status', true ) ?: 'new';
		update_post_meta( $post_id, '_ts_bug_status', $new_status );

		// Audit log.
		if ( class_exists( 'ZDZ_Admin_Dashboard' ) ) {
			ZDZ_Admin_Dashboard::log_action(
				get_current_user_id(),
				'bug_status_change',
				sprintf(
					'Bug #%d status: %s -> %s',
					$post_id,
					$old_status,
					$new_status
				),
				'',
				[
					'bug_id'     => $post_id,
					'old_status' => $old_status,
					'new_status' => $new_status,
				]
			);
		}

		wp_safe_redirect( remove_query_arg( [ 'zdz_bug_action', 'zdz_bug_id', 'zdz_new_status', '_wpnonce' ] ) );
		exit;
	}

	/* ──────────────────────────────────────────────
	 * Admin Styles
	 * ────────────────────────────────────────────── */

	public function admin_styles() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-zdz_bug_report' !== $screen->id ) {
			return;
		}
		?>
		<style>
			.zdz-bug-status-badge {
				display: inline-block;
				padding: 3px 10px;
				border-radius: 12px;
				font-size: 12px;
				font-weight: 600;
				line-height: 1.4;
				white-space: nowrap;
			}
			.column-zdz_status { width: 110px; }
			.column-zdz_category { width: 100px; }
			.column-zdz_reporter { width: 140px; }
			.column-zdz_context { width: 160px; }
		</style>
		<?php
	}

	/* ──────────────────────────────────────────────
	 * Meta Boxes — Edit Screen
	 * ────────────────────────────────────────────── */

	/**
	 * Register meta boxes for the bug report edit screen.
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'zdz_bug_report_details',
			__( 'Report Details', 'zorderz' ),
			[ $this, 'render_details_meta_box' ],
			'zdz_bug_report',
			'side',
			'high'
		);

		add_meta_box(
			'zdz_bug_report_debug',
			__( 'Debug Context', 'zorderz' ),
			[ $this, 'render_debug_meta_box' ],
			'zdz_bug_report',
			'normal',
			'high'
		);
	}

	/**
	 * Render the Report Details meta box (side column).
	 *
	 * @param WP_Post $post The post object.
	 */
	public function render_details_meta_box( $post ) {
		wp_nonce_field( 'zdz_save_bug_report_meta', 'zdz_bug_report_nonce' );

		$status   = get_post_meta( $post->ID, '_ts_bug_status', true ) ?: 'new';
		$category = get_post_meta( $post->ID, '_ts_bug_category', true ) ?: 'bug';
		$priority = get_post_meta( $post->ID, '_ts_bug_priority', true ) ?: 'normal';

		$debug_data_raw = get_post_meta( $post->ID, '_ts_debug_data', true );
		$debug_data     = $debug_data_raw ? json_decode( $debug_data_raw, true ) : [];

		$reporter_name = $debug_data['userName'] ?? 'Unknown';
		$reporter_role = $debug_data['role'] ?? 'Unknown';
		$timestamp     = $debug_data['timestamp'] ?? '';

		$formatted_date = $timestamp
			? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $timestamp ) )
			: get_the_date( '', $post ) . ' ' . get_the_time( '', $post );

		$priority_colors = [
			'normal' => '#64748b',
			'medium' => '#f59e0b',
			'high'   => '#ef4444',
		];
		$priority_color = $priority_colors[ $priority ] ?? $priority_colors['normal'];
		?>
		<style>
			.zdz-meta-row { margin-bottom: 14px; }
			.zdz-meta-label { font-weight: 600; display: block; margin-bottom: 4px; font-size: 12px; color: #1d2327; }
			.zdz-meta-select { width: 100%; padding: 6px 8px; }
			.zdz-priority-badge {
				display: inline-block;
				padding: 3px 10px;
				border-radius: 12px;
				color: #fff;
				font-size: 12px;
				font-weight: 600;
				text-transform: uppercase;
			}
			.zdz-reporter-card {
				background: #f8fafc;
				padding: 12px;
				border: 1px solid #e2e8f0;
				border-radius: 6px;
				margin-top: 16px;
			}
			.zdz-reporter-card p { margin: 0 0 6px 0; font-size: 13px; }
			.zdz-reporter-card p:last-child { margin: 0; }
			.zdz-reporter-card strong { color: #1d2327; }
		</style>

		<div class="zdz-meta-row">
			<label class="zdz-meta-label" for="zdz_bug_status"><?php esc_html_e( 'Status', 'zorderz' ); ?></label>
			<select name="zdz_bug_status" id="zdz_bug_status" class="zdz-meta-select">
				<?php foreach ( self::STATUSES as $s ) :
					$info = self::STATUS_LABELS[ $s ]; ?>
					<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status, $s ); ?>><?php echo esc_html( $info['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="zdz-meta-row">
			<label class="zdz-meta-label" for="zdz_bug_category"><?php esc_html_e( 'Category', 'zorderz' ); ?></label>
			<select name="zdz_bug_category" id="zdz_bug_category" class="zdz-meta-select">
				<?php foreach ( self::CATEGORY_LABELS as $key => $info ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $category, $key ); ?>><?php echo esc_html( $info['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="zdz-meta-row">
			<label class="zdz-meta-label"><?php esc_html_e( 'Priority', 'zorderz' ); ?></label>
			<span class="zdz-priority-badge" style="background-color:<?php echo esc_attr( $priority_color ); ?>;">
				<?php echo esc_html( ucfirst( $priority ) ); ?>
			</span>
		</div>

		<div class="zdz-reporter-card">
			<p><strong><?php esc_html_e( 'Reporter:', 'zorderz' ); ?></strong> <?php echo esc_html( $reporter_name ); ?></p>
			<p><strong><?php esc_html_e( 'Role:', 'zorderz' ); ?></strong> <?php echo esc_html( $reporter_role ); ?></p>
			<p><strong><?php esc_html_e( 'Submitted:', 'zorderz' ); ?></strong> <?php echo esc_html( $formatted_date ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render the Debug Context meta box (main column, full width).
	 *
	 * @param WP_Post $post The post object.
	 */
	public function render_debug_meta_box( $post ) {
		$debug_data_raw = get_post_meta( $post->ID, '_ts_debug_data', true );
		$debug_data     = $debug_data_raw ? json_decode( $debug_data_raw, true ) : [];

		if ( empty( $debug_data ) ) {
			echo '<p style="color:#6B7280;">' . esc_html__( 'No debug data available for this report.', 'zorderz' ) . '</p>';
			return;
		}
		?>
		<style>
			.zdz-debug-grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
				gap: 16px;
				margin-bottom: 16px;
			}
			.zdz-debug-section {
				background: #fff;
				border: 1px solid #ccd0d4;
				border-radius: 6px;
				padding: 14px 16px;
			}
			.zdz-debug-section h4 {
				margin: 0 0 10px;
				padding-bottom: 8px;
				border-bottom: 1px solid #f0f0f1;
				font-size: 13px;
				font-weight: 600;
				color: #1d2327;
			}
			.zdz-debug-list {
				margin: 0; padding: 0; list-style: none;
			}
			.zdz-debug-list li {
				margin-bottom: 7px;
				font-size: 13px;
				line-height: 1.5;
				display: flex;
				gap: 8px;
			}
			.zdz-debug-list .zdz-dl-label {
				flex: 0 0 100px;
				font-weight: 600;
				color: #1d2327;
			}
			.zdz-debug-list .zdz-dl-value {
				flex: 1;
				color: #50575e;
				word-break: break-word;
			}
			.zdz-error-item {
				background: #fef2f2;
				border-left: 3px solid #ef4444;
				padding: 8px 12px;
				margin-bottom: 8px;
				border-radius: 0 4px 4px 0;
			}
			.zdz-error-msg {
				font-family: 'SF Mono', Monaco, Consolas, 'Liberation Mono', monospace;
				font-size: 12px;
				color: #991b1b;
				word-break: break-word;
			}
			.zdz-error-time {
				color: #94a3b8;
				font-size: 11px;
				display: block;
				margin-bottom: 4px;
			}
			.zdz-recent-apps-list {
				display: flex;
				flex-wrap: wrap;
				gap: 6px;
				margin: 0;
				padding: 0;
				list-style: none;
			}
			.zdz-recent-apps-list li {
				display: inline-block;
				padding: 2px 10px;
				background: #f0f0f1;
				border-radius: 12px;
				font-size: 12px;
				color: #50575e;
			}
			.zdz-json-toggle {
				cursor: pointer;
				font-weight: 600;
				font-size: 13px;
				padding: 10px 14px;
				background: #f0f0f1;
				border: 1px solid #ccd0d4;
				border-radius: 6px;
				color: #1d2327;
				user-select: none;
			}
			.zdz-json-toggle:hover {
				background: #e5e7eb;
			}
			.zdz-json-pre {
				background: #1e293b;
				color: #e2e8f0;
				padding: 16px;
				border-radius: 6px;
				overflow-x: auto;
				font-size: 12px;
				font-family: 'SF Mono', Monaco, Consolas, 'Liberation Mono', monospace;
				margin-top: 0;
				line-height: 1.5;
			}
		</style>

		<div class="zdz-debug-grid">
			<!-- Device & Browser Info -->
			<div class="zdz-debug-section">
				<h4>&#128187; <?php esc_html_e( 'Device & Browser', 'zorderz' ); ?></h4>
				<ul class="zdz-debug-list">
					<li>
						<span class="zdz-dl-label"><?php esc_html_e( 'Platform', 'zorderz' ); ?></span>
						<span class="zdz-dl-value"><?php echo esc_html( $debug_data['platform'] ?? 'N/A' ); ?></span>
					</li>
					<li>
						<span class="zdz-dl-label"><?php esc_html_e( 'Viewport', 'zorderz' ); ?></span>
						<span class="zdz-dl-value"><?php echo esc_html( $debug_data['viewport'] ?? 'N/A' ); ?></span>
					</li>
					<li>
						<span class="zdz-dl-label"><?php esc_html_e( 'Pixel Ratio', 'zorderz' ); ?></span>
						<span class="zdz-dl-value"><?php echo esc_html( $debug_data['pixelRatio'] ?? 'N/A' ); ?></span>
					</li>
					<li>
						<span class="zdz-dl-label"><?php esc_html_e( 'Online', 'zorderz' ); ?></span>
						<span class="zdz-dl-value"><?php echo ! empty( $debug_data['online'] ) ? '<span style="color:#059669;">&#9679; Yes</span>' : '<span style="color:#dc2626;">&#9679; No</span>'; ?></span>
					</li>
					<li>
						<span class="zdz-dl-label"><?php esc_html_e( 'User Agent', 'zorderz' ); ?></span>
						<span class="zdz-dl-value" style="font-size:11px;"><?php echo esc_html( $debug_data['userAgent'] ?? 'N/A' ); ?></span>
					</li>
				</ul>
			</div>

			<!-- App Context -->
			<div class="zdz-debug-section">
				<h4>&#128736;&#65039; <?php esc_html_e( 'App Context', 'zorderz' ); ?></h4>
				<ul class="zdz-debug-list">
					<li>
						<span class="zdz-dl-label"><?php esc_html_e( 'Current App', 'zorderz' ); ?></span>
						<span class="zdz-dl-value"><?php echo esc_html( $debug_data['currentApp'] ?? 'None' ); ?></span>
					</li>
					<li>
						<span class="zdz-dl-label"><?php esc_html_e( 'Current View', 'zorderz' ); ?></span>
						<span class="zdz-dl-value"><?php echo esc_html( $debug_data['currentView'] ?? 'N/A' ); ?></span>
					</li>
					<li>
						<span class="zdz-dl-label"><?php esc_html_e( 'Theme', 'zorderz' ); ?></span>
						<span class="zdz-dl-value"><?php echo esc_html( ucfirst( $debug_data['theme'] ?? 'N/A' ) ); ?></span>
					</li>
					<li>
						<span class="zdz-dl-label"><?php esc_html_e( 'OS Version', 'zorderz' ); ?></span>
						<span class="zdz-dl-value"><?php echo esc_html( $debug_data['version'] ?? 'N/A' ); ?></span>
					</li>
					<li>
						<span class="zdz-dl-label"><?php esc_html_e( 'URL', 'zorderz' ); ?></span>
						<span class="zdz-dl-value">
							<a href="<?php echo esc_url( $debug_data['url'] ?? '#' ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $debug_data['url'] ?? 'N/A' ); ?></a>
						</span>
					</li>
				</ul>
			</div>
		</div>

		<?php // Recent Apps ?>
		<?php if ( ! empty( $debug_data['recentApps'] ) && is_array( $debug_data['recentApps'] ) ) : ?>
			<div class="zdz-debug-section" style="margin-bottom:16px;">
				<h4>&#128197; <?php esc_html_e( 'Recently Used Apps', 'zorderz' ); ?></h4>
				<ul class="zdz-recent-apps-list">
					<?php foreach ( $debug_data['recentApps'] as $app_id ) : ?>
						<li><?php echo esc_html( $app_id ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<?php // Recent Errors ?>
		<?php if ( ! empty( $debug_data['recentErrors'] ) && is_array( $debug_data['recentErrors'] ) ) : ?>
			<div class="zdz-debug-section" style="margin-bottom:16px;">
				<h4 style="color:#991b1b;">&#9888;&#65039; <?php esc_html_e( 'Recent Console Errors', 'zorderz' ); ?> (<?php echo count( $debug_data['recentErrors'] ); ?>)</h4>
				<?php foreach ( $debug_data['recentErrors'] as $error ) : ?>
					<div class="zdz-error-item">
						<span class="zdz-error-time"><?php echo esc_html( $error['time'] ?? '' ); ?></span>
						<span class="zdz-error-msg"><?php echo esc_html( $error['msg'] ?? 'Unknown error' ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php // Raw JSON (collapsible) ?>
		<details style="margin-top:8px;">
			<summary class="zdz-json-toggle"><?php esc_html_e( 'View Raw Debug JSON', 'zorderz' ); ?></summary>
			<pre class="zdz-json-pre"><code><?php echo esc_html( wp_json_encode( $debug_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></code></pre>
		</details>
		<?php
	}

	/**
	 * Save meta box data when the post is saved via the editor.
	 *
	 * @param int $post_id The post ID.
	 */
	public function save_meta_boxes( $post_id ) {
		// Verify nonce.
		if ( ! isset( $_POST['zdz_bug_report_nonce'] ) || ! wp_verify_nonce( $_POST['zdz_bug_report_nonce'], 'zdz_save_bug_report_meta' ) ) {
			return;
		}

		// Skip autosaves.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Save status.
		if ( isset( $_POST['zdz_bug_status'] ) ) {
			$new_status = sanitize_text_field( wp_unslash( $_POST['zdz_bug_status'] ) );
			if ( in_array( $new_status, self::STATUSES, true ) ) {
				$old_status = get_post_meta( $post_id, '_ts_bug_status', true ) ?: 'new';
				update_post_meta( $post_id, '_ts_bug_status', $new_status );

				// Audit log status change.
				if ( $old_status !== $new_status && class_exists( 'ZDZ_Admin_Dashboard' ) ) {
					ZDZ_Admin_Dashboard::log_action(
						get_current_user_id(),
						'bug_status_change',
						sprintf( 'Bug #%d status: %s -> %s', $post_id, $old_status, $new_status ),
						'',
						[ 'bug_id' => $post_id, 'old_status' => $old_status, 'new_status' => $new_status ]
					);
				}
			}
		}

		// Save category.
		if ( isset( $_POST['zdz_bug_category'] ) ) {
			$category = sanitize_text_field( wp_unslash( $_POST['zdz_bug_category'] ) );
			if ( in_array( $category, self::CATEGORIES, true ) ) {
				update_post_meta( $post_id, '_ts_bug_category', $category );
			}
		}
	}

	/* ──────────────────────────────────────────────
	 * REST API Routes
	 * ────────────────────────────────────────────── */

	public function register_rest_routes() {
		// Public: User submits bug report.
		register_rest_route( 'zorderz/v1', '/report-bug', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_bug_report' ],
			'permission_callback' => function () {
				return is_user_logged_in();
			},
		] );

		// Admin: Get bug reports.
		register_rest_route( 'zorderz/v1', '/admin/bugs', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'rest_list_bugs' ],
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'args'                => [
				'status'   => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				'category' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				'per_page' => [ 'type' => 'integer', 'default' => 20, 'sanitize_callback' => 'absint' ],
				'page'     => [ 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ],
			],
		] );

		// Admin: Update bug status.
		register_rest_route( 'zorderz/v1', '/admin/bugs/(?P<id>\d+)/status', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'rest_update_status' ],
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'args'                => [
				'id'     => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
				'status' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
			],
		] );

		// Admin: Get summary stats.
		register_rest_route( 'zorderz/v1', '/admin/bugs/summary', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'rest_bug_summary' ],
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		] );
	}

	/* ──────────────────────────────────────────────
	 * REST: User submits bug report
	 * ────────────────────────────────────────────── */

	public function handle_bug_report( WP_REST_Request $request ) {
		$description = sanitize_textarea_field( $request->get_param( 'description' ) );
		$category    = sanitize_text_field( $request->get_param( 'category' ) );
		$debug_data  = $request->get_param( 'debug_data' );

		if ( empty( $description ) ) {
			return new WP_Error( 'missing_description', __( 'Please describe the issue.', 'zorderz' ), [ 'status' => 400 ] );
		}

		if ( ! in_array( $category, self::CATEGORIES, true ) ) {
			$category = 'bug';
		}

		if ( ! is_array( $debug_data ) ) {
			$debug_data = [];
		}

		$user     = wp_get_current_user();
		$cat_info = self::CATEGORY_LABELS[ $category ] ?? self::CATEGORY_LABELS['bug'];

		// Build informative title.
		$title = sprintf(
			'[%s] %s — %s (%s)',
			strtoupper( $category ),
			wp_trim_words( $description, 8, '...' ),
			$user->display_name,
			gmdate( 'M j, g:ia' )
		);

		$post_id = wp_insert_post( [
			'post_title'   => $title,
			'post_content' => $description,
			'post_status'  => 'publish',
			'post_type'    => 'zdz_bug_report',
			'post_author'  => $user->ID,
		] );

		if ( is_wp_error( $post_id ) ) {
			return new WP_Error( 'bug_report_failed', __( 'Failed to save report.', 'zorderz' ), [ 'status' => 500 ] );
		}

		// Store meta.
		update_post_meta( $post_id, '_ts_bug_status', 'new' );
		update_post_meta( $post_id, '_ts_bug_category', $category );
		update_post_meta( $post_id, '_ts_debug_data', wp_json_encode( $debug_data ) );

		// Auto-detect priority from error count.
		$error_count = 0;
		if ( ! empty( $debug_data['recentErrors'] ) && is_array( $debug_data['recentErrors'] ) ) {
			$error_count = count( $debug_data['recentErrors'] );
		}
		$priority = $error_count >= 3 ? 'high' : ( $error_count >= 1 ? 'medium' : 'normal' );
		update_post_meta( $post_id, '_ts_bug_priority', $priority );

		// Store context fields for easy admin filtering.
		if ( ! empty( $debug_data['currentApp'] ) && 'None' !== $debug_data['currentApp'] ) {
			update_post_meta( $post_id, '_ts_bug_app', sanitize_text_field( $debug_data['currentApp'] ) );
		}
		if ( ! empty( $debug_data['currentView'] ) ) {
			update_post_meta( $post_id, '_ts_bug_view', sanitize_text_field( $debug_data['currentView'] ) );
		}

		// Audit log.
		if ( class_exists( 'ZDZ_Admin_Dashboard' ) ) {
			ZDZ_Admin_Dashboard::log_action(
				$user->ID,
				'bug_reported',
				sprintf(
					'%s report: %s',
					$cat_info['label'],
					wp_trim_words( $description, 12, '...' )
				),
				'',
				[
					'bug_id'   => $post_id,
					'category' => $category,
					'priority' => $priority,
				]
			);
		}

		return rest_ensure_response( [ 'success' => true, 'post_id' => $post_id ] );
	}

	/* ──────────────────────────────────────────────
	 * REST: Admin endpoints
	 * ────────────────────────────────────────────── */

	/**
	 * GET /admin/bugs — List bug reports with filters.
	 */
	public function rest_list_bugs( WP_REST_Request $request ) {
		$args = [
			'post_type'      => 'zdz_bug_report',
			'posts_per_page' => min( 100, $request->get_param( 'per_page' ) ?: 20 ),
			'paged'          => $request->get_param( 'page' ) ?: 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => [],
		];

		$status = $request->get_param( 'status' );
		if ( ! empty( $status ) && in_array( $status, self::STATUSES, true ) ) {
			$args['meta_query'][] = [
				'key'   => '_ts_bug_status',
				'value' => $status,
			];
		}

		$category = $request->get_param( 'category' );
		if ( ! empty( $category ) && in_array( $category, self::CATEGORIES, true ) ) {
			$args['meta_query'][] = [
				'key'   => '_ts_bug_category',
				'value' => $category,
			];
		}

		$query = new WP_Query( $args );
		$items = [];

		foreach ( $query->posts as $post ) {
			$debug_raw = get_post_meta( $post->ID, '_ts_debug_data', true );
			$debug     = $debug_raw ? json_decode( $debug_raw, true ) : [];
			$author    = get_userdata( $post->post_author );

			$items[] = [
				'id'          => $post->ID,
				'title'       => $post->post_title,
				'description' => $post->post_content,
				'status'      => get_post_meta( $post->ID, '_ts_bug_status', true ) ?: 'new',
				'category'    => get_post_meta( $post->ID, '_ts_bug_category', true ) ?: 'bug',
				'priority'    => get_post_meta( $post->ID, '_ts_bug_priority', true ) ?: 'normal',
				'reporter'    => $author ? $author->display_name : 'Unknown',
				'reporter_role' => $author && ! empty( $author->roles ) ? $author->roles[0] : '',
				'debug_data'  => is_array( $debug ) ? $debug : [],
				'date'        => $post->post_date,
			];
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'items' => $items,
				'total' => $query->found_posts,
				'pages' => $query->max_num_pages,
			],
		] );
	}

	/**
	 * POST /admin/bugs/{id}/status — Update a bug's status.
	 */
	public function rest_update_status( WP_REST_Request $request ) {
		$post_id    = absint( $request->get_param( 'id' ) );
		$new_status = sanitize_text_field( $request->get_param( 'status' ) );

		if ( ! in_array( $new_status, self::STATUSES, true ) ) {
			return new WP_Error( 'invalid_status', __( 'Invalid status.', 'zorderz' ), [ 'status' => 400 ] );
		}

		$post = get_post( $post_id );
		if ( ! $post || 'zdz_bug_report' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Report not found.', 'zorderz' ), [ 'status' => 404 ] );
		}

		$old_status = get_post_meta( $post_id, '_ts_bug_status', true ) ?: 'new';
		update_post_meta( $post_id, '_ts_bug_status', $new_status );

		// Audit log.
		if ( class_exists( 'ZDZ_Admin_Dashboard' ) ) {
			ZDZ_Admin_Dashboard::log_action(
				get_current_user_id(),
				'bug_status_change',
				sprintf( 'Bug #%d: %s -> %s', $post_id, $old_status, $new_status ),
				'',
				[ 'bug_id' => $post_id, 'old_status' => $old_status, 'new_status' => $new_status ]
			);
		}

		return rest_ensure_response( [ 'success' => true ] );
	}

	/**
	 * GET /admin/bugs/summary — Summary stats.
	 */
	public function rest_bug_summary( WP_REST_Request $request ) {
		$counts = [];
		foreach ( self::STATUSES as $status ) {
			$q = new WP_Query( [
				'post_type'      => 'zdz_bug_report',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_ts_bug_status',
				'meta_value'     => $status,
			] );
			$counts[ $status ] = $q->found_posts;
		}

		// Total without a status meta (legacy reports before this update).
		$legacy = new WP_Query( [
			'post_type'      => 'zdz_bug_report',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [
				[
					'key'     => '_ts_bug_status',
					'compare' => 'NOT EXISTS',
				],
			],
		] );
		$counts['new'] += $legacy->found_posts;

		return rest_ensure_response( [ 'success' => true, 'data' => $counts ] );
	}

	/* ──────────────────────────────────────────────
	 * Helpers
	 * ────────────────────────────────────────────── */

	/**
	 * Count reports with "new" status (for admin menu badge).
	 *
	 * @return int
	 */
	private function get_new_count(): int {
		$q = new WP_Query( [
			'post_type'      => 'zdz_bug_report',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [
				'relation' => 'OR',
				[
					'key'   => '_ts_bug_status',
					'value' => 'new',
				],
				[
					'key'     => '_ts_bug_status',
					'compare' => 'NOT EXISTS',
				],
			],
		] );

		return (int) ( $q->found_posts ?? 0 );
	}
}

new ZDZ_Bug_Tracker();
