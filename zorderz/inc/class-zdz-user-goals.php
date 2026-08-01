<?php
/**
 * Zorderz Theme — User Goals (v2.13.0)
 *
 * Admin-assigned goals per user / KPI / period. Backend infrastructure
 * only — no frontend rendering. Goals can be read via the REST endpoint
 * `/zorderz/v1/user-goals`. Plugins compute progress via
 * `ZDZ_User_Goals::calc_goal_progress()` if they need it.
 *
 * The admin UI lives under Users → Team Goals. Only administrators
 * can set goals.
 *
 * Safety:
 *   - Only hooks to `init` (for admin menu) and `admin_menu` (for page).
 *     Neither affects the frontend render path.
 *   - All DB queries go through `$wpdb->prepare()`.
 *   - Goal cache version option (`zdz_goal_version_counter`) bumps on
 *     save so downstream callers can invalidate cleanly.
 *
 * @package Zorderz
 * @since   2.13.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_User_Goals {

	const CACHE_BUMP_OPTION = 'zdz_goal_version_counter';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_subpage' ) );
	}

	/* ── CRUD ─────────────────────────────────────────────────── */

	public static function get_goals_for_user( int $user_id ): array {
		global $wpdb;
		$t = $wpdb->prefix . 'zdz_user_goals';
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE user_id = %d ORDER BY period_start DESC",
				$user_id
			),
			ARRAY_A
		) ?: array();
	}

	public static function get_active_goal( int $user_id, string $kpi_key, string $period_type = 'month' ): ?array {
		global $wpdb;
		$t = $wpdb->prefix . 'zdz_user_goals';
		$period_start = self::period_start_for( $period_type );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$t}
				 WHERE user_id = %d AND kpi_key = %s AND period_type = %s AND period_start = %s
				 LIMIT 1",
				$user_id, $kpi_key, $period_type, $period_start
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	public static function save_goal( array $data ): array {
		global $wpdb;
		$t = $wpdb->prefix . 'zdz_user_goals';

		$row = array(
			'user_id'      => (int) ( $data['user_id'] ?? 0 ),
			'kpi_key'      => sanitize_key( $data['kpi_key'] ?? '' ),
			'period_type'  => in_array( ( $data['period_type'] ?? 'month' ), array( 'week', 'month', 'quarter' ), true ) ? $data['period_type'] : 'month',
			'period_start' => (string) ( $data['period_start'] ?? self::period_start_for( $data['period_type'] ?? 'month' ) ),
			'target_value' => (float) ( $data['target_value'] ?? 0 ),
			'unit'         => in_array( ( $data['unit'] ?? 'dollars' ), array( 'dollars', 'count', 'pct' ), true ) ? $data['unit'] : 'dollars',
			'set_by'       => get_current_user_id(),
			'notes'        => wp_kses_post( $data['notes'] ?? '' ),
		);

		if ( $row['user_id'] <= 0 || '' === $row['kpi_key'] ) {
			return array( 'success' => false, 'errors' => array( 'user_id + kpi_key required' ) );
		}

		$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$t}
			 WHERE user_id = %d AND kpi_key = %s AND period_type = %s AND period_start = %s",
			$row['user_id'], $row['kpi_key'], $row['period_type'], $row['period_start']
		) );

		if ( $existing_id > 0 ) {
			$wpdb->update( $t, $row, array( 'id' => $existing_id ) );
			self::bump_cache_version();
			return array( 'success' => true, 'id' => $existing_id );
		}

		$wpdb->insert( $t, $row );
		self::bump_cache_version();
		return array( 'success' => true, 'id' => (int) $wpdb->insert_id );
	}

	public static function delete_goal( int $id ): bool {
		global $wpdb;
		$t = $wpdb->prefix . 'zdz_user_goals';
		$deleted = $wpdb->delete( $t, array( 'id' => $id ), array( '%d' ) );
		if ( $deleted ) { self::bump_cache_version(); return true; }
		return false;
	}

	/* ── Pace calc (clamp at period length so day 31/30 doesn't say 103%) ── */

	public static function calc_goal_progress( float $actual, float $target, string $period_type, string $period_start ): array {
		$period_length = self::period_length_days( $period_type, $period_start );
		$start_ts = strtotime( $period_start . ' 00:00:00 UTC' ) ?: time();
		$days_elapsed_raw = (int) floor( ( time() - $start_ts ) / DAY_IN_SECONDS ) + 1;
		$days_elapsed     = max( 0, min( $period_length, $days_elapsed_raw ) );

		$expected_pct = $period_length > 0 ? ( $days_elapsed / $period_length ) * 100 : 0;
		$actual_pct   = $target > 0 ? ( $actual / $target ) * 100 : 0;
		$ended        = $days_elapsed_raw > $period_length;
		$on_pace      = $actual_pct >= $expected_pct;
		$color        = self::pace_color( $actual_pct, $expected_pct, $ended );

		return array(
			'actual'        => $actual,
			'target'        => $target,
			'actual_pct'    => round( $actual_pct, 2 ),
			'expected_pct'  => round( $expected_pct, 2 ),
			'days_elapsed'  => $days_elapsed,
			'period_length' => $period_length,
			'ended'         => $ended,
			'on_pace'       => $on_pace,
			'color'         => $color,
		);
	}

	private static function pace_color( float $actual_pct, float $expected_pct, bool $ended ): string {
		if ( $ended ) { return $actual_pct >= 100 ? 'green' : 'red'; }
		if ( $actual_pct >= $expected_pct ) { return 'green'; }
		if ( $actual_pct >= 0.7 * $expected_pct ) { return 'yellow'; }
		return 'red';
	}

	public static function period_length_days( string $period_type, string $period_start ): int {
		switch ( $period_type ) {
			case 'week':    return 7;
			case 'quarter': return 91;
			case 'month':
			default:
				$ts = strtotime( $period_start . ' 00:00:00 UTC' ) ?: time();
				return (int) gmdate( 't', $ts );
		}
	}

	public static function period_start_for( string $period_type ): string {
		$now = time();
		switch ( $period_type ) {
			case 'week':
				return gmdate( 'Y-m-d', strtotime( 'monday this week', $now ) );
			case 'quarter':
				$m = (int) gmdate( 'n', $now );
				$q_start_m = 1 + 3 * floor( ( $m - 1 ) / 3 );
				return gmdate( 'Y-' . sprintf( '%02d', $q_start_m ) . '-01' );
			case 'month':
			default:
				return gmdate( 'Y-m-01', $now );
		}
	}

	/* ── Cache version bump ───────────────────────────────────── */

	public static function bump_cache_version(): int {
		$cur = (int) get_option( self::CACHE_BUMP_OPTION, 0 );
		$new = $cur + 1;
		update_option( self::CACHE_BUMP_OPTION, $new, false );
		return $new;
	}
	public static function cache_version(): int {
		return (int) get_option( self::CACHE_BUMP_OPTION, 0 );
	}

	/* ── Admin subpage (Users → Team Goals) ───────────────────── */

	public static function register_subpage(): void {
		add_submenu_page(
			'users.php',
			'Team Goals',
			'Team Goals',
			'manage_options',
			'zdz-team-goals',
			array( __CLASS__, 'render_admin' )
		);
	}

	public static function render_admin(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		if ( isset( $_POST['zdz_goal_save'] ) && check_admin_referer( 'zdz_goal_save', 'zdz_goal_nonce' ) ) {
			$result = self::save_goal( array(
				'user_id'      => (int) ( $_POST['user_id'] ?? 0 ),
				'kpi_key'      => sanitize_key( $_POST['kpi_key'] ?? '' ),
				'period_type'  => sanitize_text_field( $_POST['period_type'] ?? 'month' ),
				'period_start' => sanitize_text_field( $_POST['period_start'] ?? self::period_start_for( 'month' ) ),
				'target_value' => (float) ( $_POST['target_value'] ?? 0 ),
				'unit'         => sanitize_text_field( $_POST['unit'] ?? 'dollars' ),
				'notes'        => wp_unslash( $_POST['notes'] ?? '' ),
			) );
			if ( empty( $result['success'] ) ) {
				printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( implode( '; ', $result['errors'] ?? array() ) ) );
			} else {
				echo '<div class="notice notice-success"><p>Goal saved. Cache version: ' . (int) self::cache_version() . '</p></div>';
			}
		}

		if ( isset( $_GET['delete_goal'] ) && check_admin_referer( 'zdz_goal_delete' ) ) {
			self::delete_goal( (int) $_GET['delete_goal'] );
			echo '<div class="notice notice-success"><p>Goal deleted.</p></div>';
		}

		$users = get_users( array( 'fields' => array( 'ID', 'display_name', 'user_email' ) ) );

		echo '<div class="wrap"><h1>Team Goals <span style="font-size:13px;color:#6b7280">v2.13.0</span></h1>';
		echo '<p>Set goals per user, per KPI, per period. Read back via the REST endpoint <code>/zorderz/v1/user-goals</code>. Saving bumps the goal cache version.</p>';

		echo '<h2>Create / Update Goal</h2>';
		echo '<form method="post"><table class="form-table" role="presentation"><tbody>';
		wp_nonce_field( 'zdz_goal_save', 'zdz_goal_nonce' );

		echo '<tr><th><label>User</label></th><td><select name="user_id" required>';
		foreach ( $users as $u ) {
			printf( '<option value="%d">%s (%s)</option>', (int) $u->ID, esc_html( $u->display_name ), esc_html( $u->user_email ) );
		}
		echo '</select></td></tr>';

		echo '<tr><th><label>KPI key</label></th><td><select name="kpi_key">';
		$kpis = array(
			'revenue_monthly'     => 'Monthly Revenue (dollars)',
			'estimates_monthly'   => 'Monthly Estimate Count',
			'installs_weekly'     => 'Weekly Install Count',
			'survey_rate_monthly' => 'Monthly Survey Response Rate (%)',
		);
		foreach ( $kpis as $k => $lbl ) {
			printf( '<option value="%s">%s</option>', esc_attr( $k ), esc_html( $lbl ) );
		}
		echo '</select></td></tr>';

		echo '<tr><th><label>Period</label></th><td><select name="period_type">';
		foreach ( array( 'week', 'month', 'quarter' ) as $p ) {
			printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $p ), 'month' === $p ? ' selected' : '' );
		}
		echo '</select></td></tr>';

		printf( '<tr><th><label>Period start</label></th><td><input type="date" name="period_start" value="%s" /></td></tr>', esc_attr( self::period_start_for( 'month' ) ) );
		echo '<tr><th><label>Target value</label></th><td><input type="number" step="0.01" name="target_value" value="0" required /></td></tr>';
		echo '<tr><th><label>Unit</label></th><td><select name="unit">';
		foreach ( array( 'dollars', 'count', 'pct' ) as $u ) {
			printf( '<option value="%1$s">%1$s</option>', esc_attr( $u ) );
		}
		echo '</select></td></tr>';
		echo '<tr><th><label>Notes</label></th><td><textarea name="notes" rows="3" cols="50"></textarea></td></tr>';
		echo '</tbody></table>';
		echo '<p><button class="button button-primary" name="zdz_goal_save" value="1" type="submit">Save Goal</button></p>';
		echo '</form>';

		global $wpdb;
		$t = $wpdb->prefix . 'zdz_user_goals';
		$rows = $wpdb->get_results( "SELECT * FROM {$t} ORDER BY period_start DESC, user_id ASC LIMIT 200", ARRAY_A ) ?: array();
		echo '<h2>Existing Goals</h2>';
		echo '<table class="widefat striped"><thead><tr><th>User</th><th>KPI</th><th>Period</th><th>Start</th><th>Target</th><th>Unit</th><th>Notes</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$u = get_userdata( (int) $r['user_id'] );
			$del_url = wp_nonce_url( add_query_arg( array( 'page' => 'zdz-team-goals', 'delete_goal' => (int) $r['id'] ), admin_url( 'users.php' ) ), 'zdz_goal_delete' );
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><a class="button-link-delete" href="%s" onclick="return confirm(\'Delete this goal?\')">Delete</a></td></tr>',
				esc_html( $u ? $u->display_name : '#' . $r['user_id'] ),
				esc_html( $r['kpi_key'] ),
				esc_html( $r['period_type'] ),
				esc_html( $r['period_start'] ),
				esc_html( $r['target_value'] ),
				esc_html( $r['unit'] ),
				esc_html( wp_trim_words( (string) $r['notes'], 12 ) ),
				esc_url( $del_url )
			);
		}
		echo '</tbody></table>';
		echo '</div>';
	}
}

add_action( 'init', array( 'ZDZ_User_Goals', 'init' ) );
