<?php
/**
 * ZSM_Admin — the console: Zorderz → Security Monitor (wp-admin).
 *
 * Administrators only (manage_options), never the shared device. Every action is nonce- and
 * capability-checked and runs on the server. The console arms and disarms the monitor, picks
 * the digest's recipients, sets the feed connection, the version baseline, the registration
 * override, which components are your own, and the host's request wall; it runs a scan, a feed
 * sync, a code check or a digest on demand; and it lists the findings with acknowledge / reopen.
 *
 * The feed key is write-only here: the field is always empty, a stored key is never printed,
 * and when the platform's Credentials screen manages the key this screen only links to it.
 *
 * @package Zorderz\Security_Monitor
 */

defined( 'ABSPATH' ) || exit;

class ZSM_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
	}

	public static function menu(): void {
		if ( ! ZSM::can_manage() ) {
			return;
		}
		$title = __( 'Security Monitor', 'zorderz' );
		if ( class_exists( 'ZDZ_Core_Settings' ) ) {
			add_submenu_page( 'zdz-core-settings', $title, $title, ZSM::CAP, ZSM::PAGE, array( __CLASS__, 'render' ) );
		} else {
			add_menu_page( $title, $title, ZSM::CAP, ZSM::PAGE, array( __CLASS__, 'render' ), 'dashicons-shield-alt', 81 );
		}
	}

	private static function deny(): void {
		wp_die( esc_html__( 'Sorry, you are not allowed to manage the Security Monitor.', 'zorderz' ), '', array( 'response' => 403 ) );
	}

	public static function render(): void {
		if ( ! ZSM::can_manage() ) {
			self::deny();
		}
		$notice = self::handle_post();

		$counts  = ZSM_Findings::counts();
		$armed   = ZSM::enabled();
		$recips  = ZSM::recipient_ids();
		$variant = ZSM_Feed::variant();
		$job     = ZSM_Feed::job();
		$metrics = ZSM_Feed::metrics();
		$has_key = ZSM::has_feed_key();
		$state   = ZSM_Feed::state();

		echo '<div class="wrap"><h1>' . esc_html__( 'Security Monitor', 'zorderz' ) . ' <span style="font-size:12px;color:#888">v' . esc_html( ZSM::VERSION ) . '</span></h1>';
		if ( '' !== $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
		}

		// Honest scope.
		$fresh = ZSM_Feed::freshness_line();
		echo '<div class="notice notice-info inline"><p><strong>' . esc_html__( 'Scope:', 'zorderz' ) . '</strong> ' . esc_html( ZSM_Report::scope_line() ) . ' '
			. esc_html__( 'Your own code is in no public feed, so the monitor reads it itself once a day (the code check).', 'zorderz' ) . ' '
			. ( ZSM_Feed::alarm() ? '<span style="color:#b32d2e">' . esc_html( $fresh ) . '</span>' : esc_html( $fresh ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in both branches
			. '</p></div>';

		if ( 'not_connected' === $state ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'The vulnerability feed is not connected.', 'zorderz' ) . '</strong> '
				. esc_html__( 'The monitor works without it: it lists what is installed, flags available updates, version drift and your own code reaching the public update channel, and reads your own code daily. To also match installed plugins, themes and WordPress core against published vulnerabilities, connect the free Wordfence Intelligence feed: create a key in your wordfence.com account under Integrations, then set it below.', 'zorderz' )
				. '</p></div>';
		}

		// Feed job and the last measurements.
		if ( ! empty( $job ) ) {
			$pct = (int) floor( 100 * (int) $job['offset'] / max( 1, (int) $job['bytes'] ) );
			echo '<p><strong>' . esc_html__( 'Feed sync in progress:', 'zorderz' ) . '</strong> ';
			/* translators: 1: percent, 2: records, 3: slices */
			echo esc_html( sprintf( __( '%1$d%% read (%2$s records, %3$d steps).', 'zorderz' ), $pct, number_format_i18n( (int) $job['records'] ), (int) $job['slices'] ) ) . ' ';
			echo esc_html( $armed ? __( 'The rest runs in the background, a minute apart.', 'zorderz' ) : __( 'The monitor is not armed: press "Sync feed now" again to continue (or use wp zsm sync).', 'zorderz' ) );
			echo '</p>';
		}
		if ( ! empty( $metrics ) ) {
			$limit = in_array( (string) $metrics['memory_limit'], array( '-1', '' ), true ) ? __( 'no limit', 'zorderz' ) : (string) $metrics['memory_limit'];
			echo '<p style="color:#555;font-size:12px">' . esc_html(
				sprintf(
					/* translators: 1: variant, 2: size, 3: seconds, 4: steps, 5: seconds, 6: seconds, 7: MB, 8: memory limit, 9: records, 10: matched records, 11: ranges */
					__( 'Last good sync (%1$s feed): %2$s downloaded in %3$.1f s; read in %4$d step(s), %5$.1f s in all, longest %6$.1f s; peak memory %7$.0f MB of %8$s; %9$s records, %10$s about software installed here, %11$s version ranges cached.', 'zorderz' ),
					(string) $metrics['variant'],
					size_format( (int) $metrics['bytes'] ),
					(float) $metrics['download_s'],
					(int) $metrics['slices'],
					(float) $metrics['parse_s'],
					(float) $metrics['max_slice_s'],
					(float) $metrics['peak_mb'],
					$limit,
					number_format_i18n( (int) $metrics['records'] ),
					number_format_i18n( (int) $metrics['matched'] ),
					number_format_i18n( (int) $metrics['rows'] )
				)
			) . '</p>';
		}

		$cs = ZSM_Codescan::last();
		if ( ZSM_Codescan::in_progress() ) {
			echo '<p><strong>' . esc_html__( 'Code check in progress.', 'zorderz' ) . '</strong> '
				. esc_html( $armed ? __( 'The rest runs in the background, a minute apart.', 'zorderz' ) : __( 'Press "Check our code now" again to continue.', 'zorderz' ) ) . '</p>';
		} elseif ( ! empty( $cs['at'] ) ) {
			echo '<p style="color:#555;font-size:12px">' . esc_html(
				sprintf(
					/* translators: 1: files, 2: how long ago, 3: findings */
					__( 'Last code check: %1$s files of your own code read %2$s ago, %3$d finding(s).', 'zorderz' ),
					number_format_i18n( (int) $cs['files'] ),
					human_time_diff( (int) $cs['at'] ),
					(int) $cs['findings']
				)
			) . '</p>';
			if ( ! empty( $cs['skipped'] ) ) {
				$sk = (array) $cs['skipped'];
				echo '<p style="color:#8a6d00;font-size:12px">' . esc_html__( 'Not read (too big, or a request ran out of time in it; their earlier findings are kept):', 'zorderz' ) . ' '
					. esc_html( implode( ', ', array_slice( $sk, 0, 10 ) ) . ( count( $sk ) > 10 ? ', …' : '' ) ) . '</p>';
			}
		}

		// Summary.
		echo '<p style="font-size:16px">';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed style attribute and escaped text
		printf(
			'<strong%1$s>%2$s</strong> · <strong>%3$s</strong> · %4$s · %5$s · %6$s',
			$counts['critical'] > 0 ? ' style="color:#b32d2e"' : '',
			/* translators: %d: count */
			esc_html( sprintf( __( '%d critical', 'zorderz' ), (int) $counts['critical'] ) ),
			/* translators: %d: count */
			esc_html( sprintf( __( '%d high', 'zorderz' ), (int) $counts['high'] ) ),
			/* translators: %d: count */
			esc_html( sprintf( __( '%d medium', 'zorderz' ), (int) $counts['medium'] ) ),
			/* translators: %d: count */
			esc_html( sprintf( __( '%d low', 'zorderz' ), (int) $counts['low'] ) ),
			/* translators: %d: count */
			esc_html( sprintf( __( '%d open in all', 'zorderz' ), (int) $counts['total'] ) )
		);
		echo '</p>';

		// Actions. No browser confirm() boxes: a dialog blocks the whole tab, and every action
		// here is harmless and logged.
		self::button( 'scan_now', __( 'Scan now', 'zorderz' ), true );
		if ( $has_key ) {
			self::button( 'sync_now', __( 'Sync feed now', 'zorderz' ) );
		}
		self::button( 'codescan_now', __( 'Check our code now', 'zorderz' ) );
		self::button( 'digest_now', __( 'Send digest now', 'zorderz' ) );
		self::button( 'baseline', __( 'Set first-party baseline', 'zorderz' ), false, __( 'Record today\'s versions of your own components as the expected ones', 'zorderz' ) );

		self::render_settings( $armed, $recips, $variant, $has_key );
		self::render_findings();
		self::render_deploys();
		self::render_aliases();
		self::render_code_import();

		echo '</div>';
	}

	private static function button( string $action, string $label, bool $primary = false, string $title = '' ): void {
		echo '<form method="post" style="display:inline-block;margin:0 .3em .3em 0">';
		wp_nonce_field( 'zsm_' . $action );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed class and escaped values
		printf(
			'<button class="button%1$s" name="zsm_action" value="%2$s"%3$s>%4$s</button>',
			$primary ? ' button-primary' : '',
			esc_attr( $action ),
			'' !== $title ? ' title="' . esc_attr( $title ) . '"' : '',
			esc_html( $label )
		);
		echo '</form>';
	}

	private static function render_settings( bool $armed, array $recips, string $variant, bool $has_key ): void {
		echo '<h2>' . esc_html__( 'Settings', 'zorderz' ) . '</h2><form method="post">';
		wp_nonce_field( 'zsm_save' );
		echo '<table class="form-table"><tbody>';

		echo '<tr><th>' . esc_html__( 'Armed', 'zorderz' ) . '</th><td><label><input type="checkbox" name="zsm_enabled" value="on"' . checked( $armed, true, false ) . '> '
			. esc_html__( 'Run the daily feed sync, scan, code check and digest in the background', 'zorderz' ) . '</label></td></tr>';

		echo '<tr><th>' . esc_html__( 'Digest recipients', 'zorderz' ) . '</th><td><input type="hidden" name="zsm_recipients_posted" value="1">';
		foreach ( get_users( array( 'role__in' => array( 'administrator' ), 'orderby' => 'ID' ) ) as $u ) {
			if ( ! ZSM::can_manage( (int) $u->ID ) ) {
				continue;
			}
			printf(
				'<label style="display:block"><input type="checkbox" name="zsm_recipient_ids[]" value="%1$d"%2$s> %3$s (%4$s)</label>',
				(int) $u->ID,
				checked( in_array( (int) $u->ID, $recips, true ), true, false ),
				esc_html( $u->display_name ),
				esc_html( $u->user_email )
			);
		}
		echo '<p class="description">' . esc_html__( 'Each person gets the digest as an in-app notification (the bell) and an e-mail, through the alert router, plus a copy in Messages as a note to themselves when the messaging app is installed. With none ticked, the first administrator gets it.', 'zorderz' ) . '</p></td></tr>';

		$follow = ZSM::baseline_follows();
		echo '<tr><th>' . esc_html__( 'Version baseline', 'zorderz' ) . '</th><td><select name="zsm_baseline_mode">';
		echo '<option value="follow"' . selected( $follow, true, false ) . '>' . esc_html__( 'Follow deploys (recommended)', 'zorderz' ) . '</option>';
		echo '<option value="manual"' . selected( $follow, false, false ) . '>' . esc_html__( 'Only when I press "Set first-party baseline"', 'zorderz' ) . '</option>';
		echo '</select><p class="description">' . esc_html__( 'Following: when one of your own components gets a newer version it becomes the expected one, and the change is listed under "Deploys noticed". An older version than expected is always reported (a rollback, or a half-finished automatic update).', 'zorderz' ) . '</p></td></tr>';

		$fp = get_option( ZSM::OPT . 'first_party', array() );
		$fp = is_array( $fp ) ? implode( ', ', array_map( 'strval', $fp ) ) : (string) $fp;
		echo '<tr><th><label for="zsm_first_party">' . esc_html__( 'Your own components', 'zorderz' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="zsm_first_party" name="zsm_first_party" value="' . esc_attr( $fp ) . '" placeholder="example-plugin, example-prefix-*">';
		echo '<p class="description">' . esc_html__( 'Folder names of plugins, must-use plugins and themes your business owns, separated by commas. End an entry with "-" or "*" to match every folder that starts with it. The Zorderz theme and apps are always included. Your own components are checked for drift, for a public plugin sharing their name, and by the daily code check, never against the feed.', 'zorderz' ) . '</p></td></tr>';

		echo '<tr><th>' . esc_html__( 'Vulnerability feed', 'zorderz' ) . '</th><td>';
		echo '<p><strong>' . esc_html( $has_key ? __( 'Connected (a key is set).', 'zorderz' ) : __( 'Not connected.', 'zorderz' ) ) . '</strong> '
			. esc_html__( 'Optional. Wordfence Intelligence, free tier: create a key in your wordfence.com account under Integrations.', 'zorderz' ) . '</p>';
		if ( ZSM::key_managed_elsewhere() ) {
			echo '<p>' . esc_html__( 'The key is managed in', 'zorderz' ) . ' <a href="' . esc_url( ZSM::credentials_url() ) . '">' . esc_html__( 'Zorderz → Credentials', 'zorderz' ) . '</a> '
				. esc_html__( '(the "Wordfence vulnerability feed" row).', 'zorderz' ) . '</p>';
		} else {
			echo '<p><label for="zsm_feed_key" class="screen-reader-text">' . esc_html__( 'Feed key', 'zorderz' ) . '</label>';
			echo '<input type="password" id="zsm_feed_key" name="zsm_feed_key" value="" autocomplete="new-password" class="regular-text" placeholder="'
				. esc_attr( $has_key ? __( 'set; leave blank to keep it', 'zorderz' ) : __( 'not set', 'zorderz' ) ) . '">';
			if ( $has_key ) {
				echo ' <label><input type="checkbox" name="zsm_feed_key_remove" value="1"> ' . esc_html__( 'Disconnect (remove the key)', 'zorderz' ) . '</label>';
			}
			echo '</p><p class="description">' . esc_html__( 'Stored sealed in the platform\'s credential store and never shown again. It is sent only to the feed provider, in a request header.', 'zorderz' ) . '</p>';
		}
		echo '<p><select name="zsm_feed_variant">';
		echo '<option value="production"' . selected( $variant, 'production', false ) . '>' . esc_html__( 'Production feed: with severity scores (recommended)', 'zorderz' ) . '</option>';
		echo '<option value="scanner"' . selected( $variant, 'scanner', false ) . '>' . esc_html__( 'Scanner feed: smaller, but without severity scores, so findings show as "info"', 'zorderz' ) . '</option>';
		echo '</select></p><p class="description">' . esc_html__( 'Address:', 'zorderz' ) . ' <code>' . esc_html( ZSM_Feed::BASE_URL . $variant ) . '</code>. '
			. esc_html__( 'The address is fixed to the provider; nothing else is contacted. The feed is downloaded at most once every 30 minutes, streamed to a temporary file and read in short steps, so it never needs much memory.', 'zorderz' ) . '</p>';
		echo '</td></tr>';

		$reg = (string) get_option( ZSM::OPT . 'registration_open', '' );
		echo '<tr><th>' . esc_html__( 'Public registration', 'zorderz' ) . '</th><td><select name="zsm_registration_open">';
		/* translators: %s: open or closed */
		echo '<option value="">' . esc_html( sprintf( __( 'Automatic (now: %s)', 'zorderz' ), get_option( 'users_can_register' ) ? __( 'open', 'zorderz' ) : __( 'closed', 'zorderz' ) ) ) . '</option>';
		echo '<option value="no"' . selected( $reg, 'no', false ) . '>' . esc_html__( 'Closed (bugs that need a login are downgraded one level)', 'zorderz' ) . '</option>';
		echo '<option value="yes"' . selected( $reg, 'yes', false ) . '>' . esc_html__( 'Open', 'zorderz' ) . '</option></select></td></tr>';

		$wall   = (int) get_option( ZSM::OPT . 'request_wall', 0 );
		$budget = ZSM::budget();
		echo '<tr><th><label for="zsm_request_wall">' . esc_html__( 'Request time limit', 'zorderz' ) . '</label></th><td>';
		echo '<input type="number" min="0" max="3600" step="1" id="zsm_request_wall" name="zsm_request_wall" value="' . esc_attr( (string) $wall ) . '" class="small-text"> ' . esc_html__( 'seconds (0 = detect)', 'zorderz' );
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: 1: seconds, 2: seconds, 3: seconds */
				__( 'How long one web request may run on this host before the server cuts it off. Background steps use a third of it. Now: a %1$d s limit, so each step runs at most %2$.0f s and a download may take %3$d s.', 'zorderz' ),
				(int) $budget['wall'],
				(float) $budget['slice'],
				(int) $budget['download_timeout']
			)
		) . '</p></td></tr>';

		echo '</tbody></table><button class="button button-primary" name="zsm_action" value="save">' . esc_html__( 'Save settings', 'zorderz' ) . '</button></form>';
	}

	/** @return string a notice for the person, or '' */
	private static function handle_post(): string {
		if ( empty( $_POST['zsm_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- each action checks its own nonce below
			return '';
		}
		if ( ! ZSM::can_manage() ) {
			self::deny();
		}
		$action = sanitize_key( wp_unslash( $_POST['zsm_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked per action below

		switch ( $action ) {
			case 'save':
				check_admin_referer( 'zsm_save' );
				return self::save();

			case 'scan_now':
				check_admin_referer( 'zsm_scan_now' );
				$r = ZSM::run_scan();
				/* translators: 1: components, 2: open findings */
				return sprintf( __( 'Scan complete: %1$d components, %2$d open findings.', 'zorderz' ), (int) $r['scanned'], (int) ( $r['counts']['total'] ?? 0 ) );

			case 'sync_now':
				check_admin_referer( 'zsm_sync_now' );
				$r = ZSM_Feed::step( (float) ZSM::budget()['slice'] ); // one time-boxed step, inside the host's request wall
				if ( 'done' === $r['status'] ) {
					ZSM::run_scan();
					return $r['message'] . ' ' . __( 'Scan run again on the new copy.', 'zorderz' );
				}
				if ( 'parsing' === $r['status'] ) {
					/* translators: %d: percent */
					return $r['message'] . ' ' . sprintf( __( '%d%% read.', 'zorderz' ), (int) floor( 100 * $r['progress'] ) );
				}
				return ( 'failed' === $r['status'] ? __( 'Feed sync failed:', 'zorderz' ) . ' ' : '' ) . $r['message'];

			case 'codescan_now':
				check_admin_referer( 'zsm_codescan_now' );
				$r = ZSM_Codescan::step( (float) apply_filters( 'zsm_console_slice_seconds', ZSM::budget()['slice'] ) );
				if ( 'done' === $r['status'] ) {
					ZSM::run_scan();
					return $r['message'];
				}
				if ( ZSM::enabled() ) {
					ZSM::schedule_continue();
					return $r['message'] . ' ' . __( 'The rest runs in the background, a minute apart.', 'zorderz' );
				}
				return $r['message'] . ' ' . __( 'Press "Check our code now" again to continue.', 'zorderz' );

			case 'digest_now':
				check_admin_referer( 'zsm_digest_now' );
				$n = ZSM_Report::maybe_send_digest( ZSM::run_scan(), true );
				return $n > 0
					/* translators: %d: number of people */
					? sprintf( _n( 'Digest sent to %d person (the bell; e-mail where the alert router sends it; a copy in Messages).', 'Digest sent to %d people (the bell; e-mail where the alert router sends it; a copy in Messages).', $n, 'zorderz' ), $n )
					: __( 'The digest reached no one: no recipient could be notified (check the alert router and the recipients).', 'zorderz' );

			case 'baseline':
				check_admin_referer( 'zsm_baseline' );
				$n = ZSM_Currency::set_baseline( ZSM_Inventory::collect() );
				/* translators: %d: number of components */
				return sprintf( __( 'Baseline recorded for %d of your own components.', 'zorderz' ), $n );

			case 'add_alias':
				check_admin_referer( 'zsm_alias' );
				global $wpdb;
				$local = sanitize_title( wp_unslash( $_POST['local_slug'] ?? '' ) );
				$feed  = sanitize_title( wp_unslash( $_POST['feed_slug'] ?? '' ) );
				if ( '' === $local || '' === $feed ) {
					return __( 'Both slugs are needed.', 'zorderz' );
				}
				$wpdb->replace( ZSM_DB::aliases_table(), array( 'local_slug' => $local, 'feed_slug' => $feed ) );
				return __( 'Alias saved.', 'zorderz' );

			case 'del_alias':
				check_admin_referer( 'zsm_alias' );
				global $wpdb;
				$wpdb->delete( ZSM_DB::aliases_table(), array( 'id' => absint( $_POST['alias_id'] ?? 0 ) ) );
				return __( 'Alias removed.', 'zorderz' );

			case 'finding_status':
				check_admin_referer( 'zsm_finding' );
				$ok = ZSM_Findings::set_status( absint( $_POST['finding_id'] ?? 0 ), sanitize_key( wp_unslash( $_POST['new_status'] ?? '' ) ) );
				return $ok ? __( 'Finding updated.', 'zorderz' ) : __( 'Nothing changed.', 'zorderz' );

			case 'import_code':
				check_admin_referer( 'zsm_import_code' );
				$json = (string) wp_unslash( $_POST['zsm_code_json'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed as JSON; every field is escaped on output
				$n    = ZSM_Code::import_json( $json );
				/* translators: %d: number of findings */
				return $n >= 0 ? sprintf( __( 'Imported %d code finding(s).', 'zorderz' ), $n ) : __( 'That report could not be read (it is not JSON).', 'zorderz' );
		}
		return '';
	}

	private static function save(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the caller checked the 'zsm_save' nonce
		$was = ZSM::enabled();
		$on  = isset( $_POST['zsm_enabled'] );
		update_option( ZSM::OPT . 'enabled', $on ? 'on' : 'off', false );

		if ( ! empty( $_POST['zsm_recipients_posted'] ) ) {
			$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['zsm_recipient_ids'] ?? array() ) ) ) ) );
			update_option( ZSM::OPT . 'recipient_ids', array_values( array_filter( $ids, array( 'ZSM', 'can_manage' ) ) ), false );
		}

		$mode = sanitize_key( wp_unslash( $_POST['zsm_baseline_mode'] ?? 'follow' ) );
		update_option( ZSM::OPT . 'baseline_mode', 'manual' === $mode ? 'manual' : 'follow', false );

		$variant = sanitize_key( wp_unslash( $_POST['zsm_feed_variant'] ?? 'production' ) );
		update_option( ZSM::OPT . 'feed_variant', in_array( $variant, ZSM_Feed::VARIANTS, true ) ? $variant : 'production', false );

		$fp_raw  = sanitize_text_field( wp_unslash( $_POST['zsm_first_party'] ?? '' ) );
		$entries = array();
		foreach ( explode( ',', $fp_raw ) as $e ) {
			$e = strtolower( trim( $e ) );
			$e = (string) preg_replace( '/[^a-z0-9._*-]/', '', $e );
			if ( '' !== $e && '*' !== $e && '-' !== $e ) {
				$entries[] = $e;
			}
		}
		update_option( ZSM::OPT . 'first_party', array_values( array_unique( $entries ) ), false );

		$reg = sanitize_key( wp_unslash( $_POST['zsm_registration_open'] ?? '' ) );
		update_option( ZSM::OPT . 'registration_open', in_array( $reg, array( 'yes', 'no' ), true ) ? $reg : '', false );

		update_option( ZSM::OPT . 'request_wall', min( 3600, absint( $_POST['zsm_request_wall'] ?? 0 ) ), false );

		$msg = __( 'Settings saved.', 'zorderz' );
		if ( ! ZSM::key_managed_elsewhere() ) {
			if ( ! empty( $_POST['zsm_feed_key_remove'] ) ) {
				ZSM::delete_feed_key();
				$msg .= ' ' . __( 'The feed key was removed; the feed is not connected.', 'zorderz' );
			} else {
				$key = trim( (string) wp_unslash( $_POST['zsm_feed_key'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a secret: stored sealed, never echoed
				if ( '' !== $key ) {
					$stored = ZSM::store_feed_key( $key );
					if ( true === $stored ) {
						delete_option( ZSM::OPT . 'feed_backoff_until' ); // a new key deserves a try
						$msg .= ' ' . __( 'The feed key was stored (sealed).', 'zorderz' );
					} else {
						$msg .= ' ' . __( 'The key was NOT saved:', 'zorderz' ) . ' ' . $stored->get_error_message();
					}
				}
				unset( $key );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $on ) {
			ZSM::ensure_schedule( true );
		} elseif ( $was ) {
			// Disarmed: nothing runs in the background any more.
			delete_transient( ZSM::SCHEDULE_FLAG );
			foreach ( array( ZSM::CRON_HOOK, ZSM::CONTINUE_HOOK, ZSM::RETRY_HOOK ) as $hook ) {
				wp_clear_scheduled_hook( $hook );
				if ( function_exists( 'as_unschedule_all_actions' ) ) {
					as_unschedule_all_actions( $hook );
				}
			}
		}
		return $msg;
	}

	private static function render_findings(): void {
		$rows = ZSM_Findings::list( 'active' );
		echo '<h2>' . esc_html__( 'Open findings', 'zorderz' ) . '</h2>';
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'None in scope.', 'zorderz' ) . '</p>';
			return;
		}
		$color = array( 'critical' => '#b32d2e', 'high' => '#c9660a', 'medium' => '#8a6d00', 'low' => '#555', 'info' => '#777' );
		echo '<table class="widefat striped"><thead><tr>'
			. '<th>' . esc_html__( 'Severity', 'zorderz' ) . '</th>'
			. '<th>' . esc_html__( 'Plane', 'zorderz' ) . '</th>'
			. '<th>' . esc_html__( 'Component', 'zorderz' ) . '</th>'
			. '<th>' . esc_html__( 'Installed', 'zorderz' ) . '</th>'
			. '<th>' . esc_html__( 'Fix', 'zorderz' ) . '</th>'
			. '<th>' . esc_html__( 'Finding', 'zorderz' ) . '</th>'
			. '<th>' . esc_html__( 'CVE', 'zorderz' ) . '</th>'
			. '<th>' . esc_html__( 'Status', 'zorderz' ) . '</th>'
			. '<th></th></tr></thead><tbody>';
		foreach ( $rows as $f ) {
			$sev  = (string) $f['severity'];
			$next = ( 'acknowledged' === $f['status'] ) ? 'open' : 'acknowledged';
			echo '<tr>';
			printf( '<td><strong style="color:%1$s">%2$s</strong></td>', esc_attr( $color[ $sev ] ?? '#555' ), esc_html( strtoupper( $sev ) ) );
			printf( '<td>%s</td>', esc_html( (string) $f['plane'] ) );
			printf( '<td><strong>%1$s</strong><br><span style="color:#777">%2$s</span></td>', esc_html( (string) ( $f['name'] ? $f['name'] : $f['slug'] ) ), esc_html( (string) $f['slug'] ) );
			printf( '<td>%s</td>', esc_html( (string) $f['installed_version'] ) );
			printf( '<td>%s</td>', esc_html( (string) ( $f['fixed_version'] ? $f['fixed_version'] : '—' ) ) );
			echo '<td>' . esc_html( (string) $f['title'] );
			if ( ! empty( $f['context_note'] ) ) {
				echo '<br><em style="color:#666;font-size:11px">' . esc_html( (string) $f['context_note'] ) . '</em>';
			}
			echo '</td><td>';
			if ( ! empty( $f['ref_url'] ) ) {
				echo '<a href="' . esc_url( (string) $f['ref_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( (string) ( $f['cve'] ? $f['cve'] : __( 'reference', 'zorderz' ) ) ) . '</a>';
			} else {
				echo esc_html( (string) ( $f['cve'] ? $f['cve'] : '—' ) );
			}
			echo '</td>';
			printf( '<td>%s</td>', esc_html( (string) $f['status'] ) );
			echo '<td><form method="post">';
			wp_nonce_field( 'zsm_finding' );
			printf( '<input type="hidden" name="finding_id" value="%d">', (int) $f['id'] );
			printf( '<input type="hidden" name="new_status" value="%s">', esc_attr( $next ) );
			printf(
				'<button class="button button-small" name="zsm_action" value="finding_status">%s</button>',
				esc_html( 'acknowledged' === $f['status'] ? __( 'Reopen', 'zorderz' ) : __( 'Acknowledge', 'zorderz' ) )
			);
			echo '</form></td></tr>';
		}
		echo '</tbody></table>';
	}

	/** What the baseline did by itself (and by hand), newest first. */
	private static function render_deploys(): void {
		$log = ZSM_Currency::deploy_log();
		echo '<h2>' . esc_html__( 'Deploys noticed', 'zorderz' ) . ' <span style="font-weight:400;font-size:12px;color:#777">' . esc_html__( '(changes to your own components\' versions)', 'zorderz' ) . '</span></h2>';
		if ( ! $log ) {
			$n = count( ZSM_Currency::baseline() );
			echo '<p class="description">' . esc_html(
				$n
					/* translators: %d: number of components */
					? sprintf( __( 'Expected versions are recorded for %d components; no change noticed since.', 'zorderz' ), $n )
					: __( 'None yet. The first scan records today\'s versions.', 'zorderz' )
			) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:820px"><thead><tr><th>' . esc_html__( 'When', 'zorderz' ) . '</th><th>' . esc_html__( 'Component', 'zorderz' ) . '</th><th>' . esc_html__( 'Change', 'zorderz' ) . '</th></tr></thead><tbody>';
		foreach ( array_slice( $log, 0, 15 ) as $e ) {
			$when = function_exists( 'wp_date' ) ? wp_date( 'j M Y, H:i', (int) $e['at'] ) : gmdate( 'j M Y H:i', (int) $e['at'] );
			switch ( $e['what'] ) {
				case 'deploy':
					$what = sprintf( '%s → %s', $e['from'], $e['to'] );
					break;
				case 'new':
					/* translators: %s: version */
					$what = sprintf( __( 'new, %s', 'zorderz' ), $e['to'] );
					break;
				case 'returned':
					/* translators: %s: version */
					$what = sprintf( __( 'back after being removed: %s', 'zorderz' ), $e['to'] )
						/* translators: %s: version */
						. ( '' !== $e['from'] ? ' ' . sprintf( __( '(expected %s)', 'zorderz' ), $e['from'] ) : '' );
					break;
				case 'removed':
					/* translators: %s: version */
					$what = sprintf( __( 'removed (was %s)', 'zorderz' ), $e['from'] );
					break;
				default:
					/* translators: %s: number of components */
					$what = sprintf( __( 'baseline recorded for %s components', 'zorderz' ), $e['to'] );
			}
			printf( '<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td></tr>', esc_html( (string) $when ), esc_html( (string) ( $e['name'] ? $e['name'] : '—' ) ), esc_html( (string) $what ) );
		}
		echo '</tbody></table>';
	}

	private static function render_aliases(): void {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . ZSM_DB::aliases_table() . ' ORDER BY local_slug', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed identifier
		echo '<h2>' . esc_html__( 'Slug aliases', 'zorderz' ) . ' <span style="font-weight:400;font-size:12px;color:#777">' . esc_html__( '(when a plugin\'s folder name differs from the feed\'s slug)', 'zorderz' ) . '</span></h2>';
		echo '<table class="widefat" style="max-width:640px"><thead><tr><th>' . esc_html__( 'Folder slug', 'zorderz' ) . '</th><th>' . esc_html__( 'Feed slug', 'zorderz' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( (array) $rows as $a ) {
			echo '<tr><td>' . esc_html( (string) $a['local_slug'] ) . '</td><td>' . esc_html( (string) $a['feed_slug'] ) . '</td><td><form method="post">';
			wp_nonce_field( 'zsm_alias' );
			echo '<input type="hidden" name="alias_id" value="' . (int) $a['id'] . '"><button class="button button-small" name="zsm_action" value="del_alias">' . esc_html__( 'Remove', 'zorderz' ) . '</button></form></td></tr>';
		}
		echo '</tbody></table><form method="post" style="margin-top:.5em">';
		wp_nonce_field( 'zsm_alias' );
		echo '<input name="local_slug" placeholder="' . esc_attr__( 'folder-slug', 'zorderz' ) . '"> <input name="feed_slug" placeholder="' . esc_attr__( 'feed-slug', 'zorderz' ) . '"> ';
		echo '<button class="button button-small" name="zsm_action" value="add_alias">' . esc_html__( 'Add', 'zorderz' ) . '</button></form>';
	}

	private static function render_code_import(): void {
		echo '<h2>' . esc_html__( 'Import a code review report', 'zorderz' ) . ' <span style="font-weight:400;font-size:12px;color:#777">' . esc_html__( '(optional)', 'zorderz' ) . '</span></h2>';
		echo '<p class="description">' . esc_html__( 'The daily code check covers the common mistakes. For a deeper review, run Plugin Check or Semgrep elsewhere and paste its JSON here (or use wp zsm import-code <file.json>).', 'zorderz' ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( 'zsm_import_code' );
		echo '<textarea name="zsm_code_json" rows="4" style="width:100%;max-width:800px" placeholder="' . esc_attr__( '{"results":[...]} (Semgrep) or Plugin Check JSON', 'zorderz' ) . '"></textarea><br>';
		echo '<button class="button" name="zsm_action" value="import_code">' . esc_html__( 'Import report', 'zorderz' ) . '</button></form>';
	}
}
