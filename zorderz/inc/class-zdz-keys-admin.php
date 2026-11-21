<?php
/**
 * ZDZ_Keys_Admin -- Zorderz -> App key, the app key's notices and its Site Health item.
 *
 * The page (administrators signed in as themselves only: ZDZ_Keys::may_manage):
 *   - the key's state in one line, the key id in zdz-secrets.php, the key id the app recorded, the site the file is
 *     for and this site's address, whether a previous key is set (a key change under way);
 *   - until the key is set up: what to do (bin/make-app-keys.php on the operator's own computer, the file, the two
 *     lines for wp-config.php);
 *   - the jobs that move stored values onto the key (ZDZ_Keys_Migrate): done / couldn't read / skipped per job, Run,
 *     and Re-encrypt everything now;
 *   - "Check a printed key": which key a typed code is, and how many characters it read; never echoed or kept;
 *   - "Record this key": only when ZDZ_Keys::recordable() allows it.
 * It never shows, makes or stores a key. No browser confirm() (an admin page that automation drives must not block).
 *
 * The notices (every admin page, administrators) appear only once the key is set up and is not ready: missing,
 * bad_constant, mismatch, other_site, no_sodium. Before zdz-secrets.php exists there is no notice.
 * The salt watch (ZDZ_Salt_Watch) keeps its own words: what is not yet sealed under the app's key still depends on the
 * salts, and its notice says what a change to them breaks.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Keys_Admin {

	const PAGE  = 'zdz-app-key';
	const NONCE = 'zdz_keys_admin';

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ), 22 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_filter( 'site_status_tests', array( __CLASS__, 'site_health' ) );
		add_filter( 'zdz_export_non_portable_option_patterns', array( 'ZDZ_Keys', 'export_non_portable' ) );
	}

	public static function add_page() {
		add_submenu_page( 'zdz-core-settings', __( 'App key', 'zorderz' ), __( 'App key', 'zorderz' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function page_url() {
		return add_query_arg( array( 'page' => self::PAGE ), admin_url( 'admin.php' ) );
	}

	/**
	 * The two lines for wp-config.php, above the line that loads wp-settings.php. The require is guarded on purpose: a
	 * bare require of a file a restore or a copy removed is a PHP fatal error that takes the whole site, sign-in
	 * included, down with it. Guarded, a missing file is only the state "missing": the app's secrets stay locked, a
	 * notice says so, and everyone can still sign in to put it back.
	 */
	public static function config_lines() {
		return array(
			"define( 'ZDZ_SECRETS_LOAD', true );",
			"if ( is_readable( __DIR__ . '/zdz-secrets.php' ) ) { require_once __DIR__ . '/zdz-secrets.php'; }",
		);
	}

	/* ================================================================== words */

	/**
	 * The notice for a key that is set up but not ready: [ head, body ], or null when there is nothing to say (ready,
	 * or not set up yet).
	 */
	public static function banner( ?array $i = null ) {
		$i = $i ?? ZDZ_Keys::info();
		if ( 'ok' === $i['state'] || empty( $i['set_up'] ) ) {
			return null;
		}
		switch ( $i['state'] ) {
			case 'missing':
				return array(
					__( 'The app\'s key is missing.', 'zorderz' ),
					__( 'Put the require line back in wp-config.php, or the ZDZ_APP_KEY line back in zdz-secrets.php, from your password manager. Do not make a new key. Until then the app\'s sealed values can\'t be read or saved.', 'zorderz' ),
				);
			case 'bad_constant':
				return array(
					__( 'ZDZ_APP_KEY is not a printed key.', 'zorderz' ),
					__( 'It should be 13 groups of four letters and digits, as printed. Correct it from your password manager. Do not make a new key.', 'zorderz' ),
				);
			case 'mismatch':
				if ( ! empty( $i['unrecorded'] ) ) {
					return array(
						/* translators: %s: a key id (8 characters) */
						sprintf( __( 'The app has no record of which key its sealed values use (key id in zdz-secrets.php: %s).', 'zorderz' ), (string) $i['kid'] ),
						__( 'If that is the key id you saved with the key, press Record on Zorderz → App key. If not, put back the right ZDZ_APP_KEY line from your password manager. Do not make a new key.', 'zorderz' ),
					);
				}
				return array(
					/* translators: 1: the key id in the file, 2: the key id the app recorded */
					sprintf( __( 'The app\'s key changed: this is not the key the app\'s data was sealed with (key id %1$s, expected %2$s).', 'zorderz' ), (string) $i['kid'], (string) $i['recorded_kid'] ),
					__( 'Put back the right ZDZ_APP_KEY line from your password manager. Do not make a new key. Until then the app\'s sealed values can\'t be read or saved.', 'zorderz' ),
				);
			case 'other_site':
				if ( 'record' === ( $i['site_why'] ?? '' ) ) {
					return array(
						/* translators: %s: the address the key was recorded on */
						sprintf( __( 'This site\'s database was copied from %s.', 'zorderz' ), (string) $i['recorded_site'] ),
						/* translators: %s: this site's address */
						sprintf( __( 'Its sealed values belong to that site\'s key and can\'t be read here (this site is %s). Nothing is damaged. Do not make a new key. If this site moved to a new address, set ZDZ_APP_KEY_SITE to it and press Record on Zorderz → App key.', 'zorderz' ), (string) $i['host'] ),
					);
				}
				return array(
					/* translators: %s: the address the key file is for */
					sprintf( __( 'This site has the key for %s.', 'zorderz' ), (string) $i['site_const'] ),
					/* translators: %s: this site's address */
					sprintf( __( 'Upload this site\'s own zdz-secrets.php (a copy between environments brings the other one\'s file). This site is %s.', 'zorderz' ), (string) $i['host'] ),
				);
			case 'no_sodium':
				return array(
					__( 'The app\'s key can\'t be used: this server\'s PHP has no libsodium.', 'zorderz' ),
					__( 'Ask the host to turn the sodium extension on.', 'zorderz' ),
				);
		}
		return array( __( 'The app\'s key can\'t be used right now.', 'zorderz' ), __( 'Open Zorderz → App key.', 'zorderz' ) );
	}

	/** The state in one line, for the page: [ notice class, sentence ]. */
	public static function status_line( array $i ) {
		if ( empty( $i['set_up'] ) && ! empty( $i['secrets_load'] ) ) {
			return array( 'notice-warning', __( 'wp-config.php loads zdz-secrets.php, but no ZDZ_APP_KEY came from it. Check that the file is in the folder that holds wp-config.php and that the web server\'s PHP can read it.', 'zorderz' ) );
		}
		if ( empty( $i['set_up'] ) ) {
			return array( 'notice-info', __( 'Not set up yet. Nothing uses the app\'s key yet, so nothing is affected: the steps are below.', 'zorderz' ) );
		}
		if ( 'ok' === $i['state'] ) {
			return ! empty( $i['rotating'] )
				? array( 'notice-warning', __( 'A key change is under way: values are read with either key and written with the new one. Run Re-encrypt everything now below.', 'zorderz' ) )
				: array( 'notice-success', __( 'The app\'s key is in place and is the one the app recorded.', 'zorderz' ) );
		}
		$b = self::banner( $i );
		return array( 'notice-error', $b ? $b[0] . ' ' . $b[1] : __( 'The app\'s key can\'t be used right now.', 'zorderz' ) );
	}

	private static function job_counts( array $st ) {
		/* translators: 1: values moved, 2: values that could not be read, 3: values skipped */
		return sprintf( __( '%1$d done · %2$d couldn\'t read · %3$d skipped', 'zorderz' ), (int) ( $st['done'] ?? 0 ), (int) ( $st['unreadable_n'] ?? 0 ), (int) ( $st['skipped'] ?? 0 ) );
	}

	/* ================================================================== notice and Site Health */

	public static function notice() {
		if ( ! current_user_can( 'manage_options' ) || ! ZDZ_Keys::set_up() ) {
			return;
		}
		$b = self::banner();
		if ( null === $b ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html( $b[0] ) . '</strong> ' . esc_html( $b[1] ) . ' '
			. '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Zorderz → App key', 'zorderz' ) . '</a></p></div>';
	}

	public static function site_health( $tests ) {
		$tests['direct']['zdz_app_key'] = array(
			'label' => __( 'Zorderz: the app\'s key', 'zorderz' ),
			'test'  => array( __CLASS__, 'site_health_result' ),
		);
		return $tests;
	}

	public static function site_health_result() {
		$i  = ZDZ_Keys::info();
		$ok = 'ok' === $i['state'];
		$b  = self::banner( $i );
		if ( ! $i['set_up'] ) {
			$label = __( 'The app\'s key is not set up yet (nothing uses it yet)', 'zorderz' );
			$desc  = __( 'Zorderz can keep its secrets under a key of its own, in zdz-secrets.php beside wp-config.php, so that a change to the WordPress salts only signs people out. Nothing is kept under it yet, so nothing is affected.', 'zorderz' );
			$ok    = true;
		} elseif ( $ok ) {
			/* translators: %s: a key id (8 characters) */
			$label = sprintf( __( 'The app\'s key is in place (key id %s)', 'zorderz' ), (string) $i['kid'] );
			$desc  = __( 'The app\'s own key is in place and is the one the app recorded. It is kept apart from the WordPress salts.', 'zorderz' );
		} else {
			$label = $b ? $b[0] : __( 'The app\'s key can\'t be used', 'zorderz' );
			$desc  = $b ? $b[1] : '';
		}
		return array(
			'label'       => $label,
			'status'      => $ok ? 'good' : 'critical',
			'badge'       => array(
				'label' => __( 'Security', 'zorderz' ),
				'color' => $ok ? 'blue' : 'red',
			),
			'description' => '<p>' . esc_html( $desc ) . '</p>',
			'actions'     => '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Zorderz → App key', 'zorderz' ) . '</a>',
			'test'        => 'zdz_app_key',
		);
	}

	/* ================================================================== the page */

	/** An audit row when the audit log is there (never blocks: nothing on this page shows a secret). */
	private static function audit( $uid, $what, $detail ) {
		if ( class_exists( 'ZDZ_Admin_Dashboard' ) && method_exists( 'ZDZ_Admin_Dashboard', 'log_action' ) ) {
			ZDZ_Admin_Dashboard::log_action( (int) $uid, $what, $detail, 'zdz-theme' );
		}
	}

	/**
	 * How a typed code was read: [ characters read, other marks left out ]. The reading ZDZ_Keys::parse() makes (0, 1
	 * and 8 read as O, I and B); spacing and dashes of any kind are not counted as marks. Counts only: the code itself
	 * is never returned, kept or logged.
	 */
	public static function code_reading( $code ) {
		$s    = strtr( strtoupper( (string) $code ), array( '0' => 'O', '1' => 'I', '8' => 'B' ) );
		$read = strlen( (string) preg_replace( '/[^A-Z2-7]/', '', $s ) );
		// characters, not bytes: a pasted non-breaking space or dash is spacing, an accented letter is one mark
		$rest = preg_replace( '/[A-Z2-7\s\p{Z}\p{Pd}]/u', '', $s );
		$left = null === $rest ? strlen( (string) preg_replace( '/[A-Z2-7\s\-]/', '', $s ) ) : ( function_exists( 'mb_strlen' ) ? mb_strlen( $rest, 'UTF-8' ) : preg_match_all( '/./us', $rest ) );
		return array( (int) $read, (int) $left );
	}

	/** Handle a press. [ notice class, sentence ] or null. */
	private static function handle( $uid ) {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] || ! isset( $_POST['zdz_keys_do'] ) ) {
			return null;
		}
		check_admin_referer( self::NONCE );
		$do = is_string( $_POST['zdz_keys_do'] ) ? sanitize_key( wp_unslash( $_POST['zdz_keys_do'] ) ) : '';

		if ( 'check' === $do ) {
			$typed = ( isset( $_POST['zdz_keys_code'] ) && is_string( $_POST['zdz_keys_code'] ) ) ? wp_unslash( $_POST['zdz_keys_code'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read as a printed key and compared by key id only; never stored, logged or echoed
			list( $n_read, $n_left ) = self::code_reading( $typed );
			$as    = ZDZ_Keys::code_reads_as( $typed );
			$typed = '';
			/* translators: %d: how many characters of the typed code were read */
			$read = sprintf( _n( 'It read %d character', 'It read %d characters', $n_read, 'zorderz' ), $n_read );
			if ( $n_left ) {
				/* translators: %d: how many other marks were left out */
				$read .= sprintf( _n( ', and left out %d other mark', ', and left out %d other marks', $n_left, 'zorderz' ), $n_left );
			}
			$words = array(
				'this'       => array( 'notice-success', __( 'That code is the key in zdz-secrets.php.', 'zorderz' ) . ' ' . $read . '.' ),
				'recorded'   => array( 'notice-warning', __( 'That code is the key the app\'s data was sealed with, not the one in zdz-secrets.php: that file\'s ZDZ_APP_KEY line should hold this code.', 'zorderz' ) . ' ' . $read . '.' ),
				'previous'   => array( 'notice-success', __( 'That code is the previous key (ZDZ_APP_KEY_PREVIOUS).', 'zorderz' ) . ' ' . $read . '.' ),
				'none'       => array( 'notice-error', __( 'That code is not this site\'s key, the recorded key, or the previous key.', 'zorderz' ) . ' ' . $read . '.' ),
				'not_a_code' => array(
					'notice-error',
					__( 'That is not a printed key.', 'zorderz' ) . ' ' . $read . '; '
						. __( 'a printed key has 52. The look-alikes 5 and S, 2 and Z, 6 and G, U and V are all valid characters, so one misread can still look right: check each of those against the paper. (0, 1 and 8 are read as O, I and B.)', 'zorderz' ),
				),
			);
			return $words[ $as ] ?? $words['not_a_code'];
		}
		if ( 'run' === $do ) {
			$id = ( isset( $_POST['zdz_keys_job'] ) && is_string( $_POST['zdz_keys_job'] ) ) ? sanitize_key( wp_unslash( $_POST['zdz_keys_job'] ) ) : '';
			$r  = ZDZ_Keys_Migrate::run( $id, 200, $uid );
			if ( ! empty( $r['error'] ) ) {
				return array( 'notice-error', __( 'Nothing was moved:', 'zorderz' ) . ' ' . self::error_words( $r['error'] ) );
			}
			self::audit( $uid, 'keys_migration_run', 'App key: ran ' . $id . ' (' . (int) $r['done'] . ' done, ' . (int) $r['unreadable_n'] . ' unreadable, ' . (int) $r['skipped'] . ' skipped)' );
			return array(
				'notice-success',
				__( 'Ran it:', 'zorderz' ) . ' ' . self::job_counts( $r ) . '. '
					. ( empty( $r['finished'] ) ? __( 'Not finished: press Run again to continue.', 'zorderz' ) : __( 'Finished.', 'zorderz' ) ),
			);
		}
		if ( 'run_all' === $do ) {
			if ( ! ZDZ_Keys::ready() ) {
				return array( 'notice-error', __( 'Nothing was moved:', 'zorderz' ) . ' ' . self::error_words( ZDZ_Keys::state() ) );
			}
			$r   = ZDZ_Keys_Migrate::run_all( 200, $uid );
			$rec = ! empty( $r['_recorded'] );
			unset( $r['_recorded'] );
			$left = 0;
			$errs = 0;
			foreach ( $r as $st ) {
				if ( ! empty( $st['error'] ) ) {
					++$errs;
				} elseif ( empty( $st['finished'] ) ) {
					++$left;
				}
			}
			self::audit( $uid, 'keys_migration_run_all', 'App key: re-encrypt everything now (' . count( $r ) . ' jobs)' . ( $rec ? '; the new key was recorded' : '' ) );
			if ( ! $r ) {
				return array( 'notice-info', __( 'There is nothing to re-encrypt yet: no component has registered its stored values.', 'zorderz' ) );
			}
			$say = __( 'Ran every job once.', 'zorderz' );
			if ( $errs ) {
				/* translators: %d: how many jobs could not run */
				$say .= ' ' . sprintf( __( '%d could not run (see the table).', 'zorderz' ), $errs );
			}
			if ( $left ) {
				/* translators: %d: how many jobs are not finished */
				$say .= ' ' . sprintf( __( '%d not finished: press it again to continue.', 'zorderz' ), $left );
			}
			if ( $rec ) {
				$say .= ' ' . __( 'Everything is under the new key, and the new key is recorded: you can now remove the ZDZ_APP_KEY_PREVIOUS line.', 'zorderz' );
			}
			return array( $errs ? 'notice-error' : 'notice-success', $say );
		}
		if ( 'record' === $do ) {
			$typed = ( isset( $_POST['zdz_keys_code'] ) && is_string( $_POST['zdz_keys_code'] ) ) ? wp_unslash( $_POST['zdz_keys_code'] ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read as a printed key and compared by key id only; never stored, logged or echoed
			$ok    = ZDZ_Keys::record_key( $uid, $typed );
			$typed = null;
			if ( $ok ) {
				$rk = (string) ( ZDZ_Keys::recorded()['kid'] ?? '' );
				self::audit( $uid, 'keys_key_recorded', 'App key: key id ' . $rk . ' recorded' );
				/* translators: %s: a key id (8 characters) */
				return array( 'notice-success', sprintf( __( 'Recorded: key id %s is the app\'s key.', 'zorderz' ), $rk ) );
			}
			return array( 'notice-error', __( 'Nothing was recorded. After a lost record, type the printed key from the paper or the password manager in the box: it must be the key whose key id this page offers to record. A key is never recorded while a stored value is under another key, or before a key change has finished re-encrypting.', 'zorderz' ) );
		}
		return null;
	}

	private static function error_words( $e ) {
		$w = array(
			'no_such_job'  => __( 'there is no such job.', 'zorderz' ),
			'busy'         => __( 'another run is under way; try again in a minute.', 'zorderz' ),
			'missing'      => __( 'the app\'s key is missing.', 'zorderz' ),
			'bad_constant' => __( 'ZDZ_APP_KEY is not a printed key.', 'zorderz' ),
			'mismatch'     => __( 'the app\'s key is not the recorded one.', 'zorderz' ),
			'other_site'   => __( 'the key file or the database here is another site\'s.', 'zorderz' ),
			'no_sodium'    => __( 'this server\'s PHP has no libsodium.', 'zorderz' ),
		);
		return $w[ (string) $e ] ?? __( 'the key isn\'t ready.', 'zorderz' );
	}

	private static function name_of( $uid ) {
		$uid = (int) $uid;
		$u   = $uid > 0 ? get_userdata( $uid ) : null;
		if ( ! $u ) {
			/* translators: %d: a user id */
			return $uid > 0 ? sprintf( __( 'user %d', 'zorderz' ), $uid ) : __( 'the app, on first use', 'zorderz' );
		}
		$name = (string) ( $u->display_name ?? '' );
		if ( '' === $name ) {
			$name = (string) ( $u->user_login ?? '' );
		}
		/* translators: %d: a user id */
		return '' !== $name ? $name : sprintf( __( 'user %d', 'zorderz' ), $uid );
	}

	/** The key ids on stored values, for the page: what holds Record or a key change open. */
	private static function found_words() {
		$f = ZDZ_Keys::sealed_kids();
		if ( null === $f ) {
			return __( 'can\'t tell: a registered job is too large to scan and has no key-id reader', 'zorderz' );
		}
		return $f ? implode( ', ', $f ) : __( 'none found', 'zorderz' );
	}

	/** A box for a printed key: plain text, masked, so the browser never offers to save the key as a password. */
	private static function code_box( $id = '' ) {
		echo '<input type="text"';
		if ( '' !== $id ) {
			echo ' id="' . esc_attr( $id ) . '"';
		}
		echo ' name="zdz_keys_code" value="" aria-label="' . esc_attr__( 'The printed key', 'zorderz' ) . '" class="regular-text" autocomplete="off" data-lpignore="true" data-1p-ignore autocapitalize="characters" spellcheck="false" style="font-family:ui-monospace,Menlo,monospace;-webkit-text-security:disc;max-width:100%"> ';
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$uid = (int) get_current_user_id();
		echo '<div class="wrap"><h1>' . esc_html__( 'App key', 'zorderz' ) . '</h1>';
		if ( ! ZDZ_Keys::may_manage( $uid ) ) {
			echo '<p>' . esc_html__( 'Only an administrator, signed in as themselves (not the shared device, Demo Mode, View As, or an account in Privacy View), can see the app\'s key page.', 'zorderz' ) . '</p></div>';
			return;
		}
		$msg = self::handle( $uid );
		ZDZ_Keys::reset();
		$i   = ZDZ_Keys::info();
		$url = self::page_url();

		echo '<p style="max-width:860px">' . esc_html__( 'The app\'s own key, ZDZ_APP_KEY, is kept in zdz-secrets.php beside wp-config.php, apart from the WordPress salts. What the app seals under it (stored provider keys, sign-ins, codes) stays readable when the salts change, so a change to the salts then only signs people out. This page shows key ids only (an 8-character fingerprint); it never shows, makes, or stores a key.', 'zorderz' ) . '</p>';
		list( $cls, $text ) = self::status_line( $i );
		echo '<div class="notice ' . esc_attr( $cls ) . ' inline"><p>' . esc_html( $text ) . '</p></div>';
		if ( $msg ) {
			echo '<div class="notice ' . esc_attr( $msg[0] ) . ' inline"><p>' . esc_html( $msg[1] ) . '</p></div>';
		}

		if ( ! $i['set_up'] ) {
			$lines = self::config_lines();
			echo '<h2>' . esc_html__( 'To set it up', 'zorderz' ) . '</h2><ol style="max-width:860px">'
				. '<li>' . esc_html__( 'Make the key on your own computer, never on this server. The script is bin/make-app-keys.php in the Zorderz source code (it is not inside the theme or apps zip). Run it with PHP, naming this site and a new folder for the file:', 'zorderz' )
				. ' <code>' . esc_html( 'php bin/make-app-keys.php --site=' . $i['host'] ) . ' ' . esc_html__( 'NEW-FOLDER', 'zorderz' ) . '</code> '
				. esc_html__( 'It makes this site\'s key, writes zdz-secrets.php into that folder and prints the key id. It sends nothing anywhere. Copy the ZDZ_APP_KEY line from the file into your password manager and onto paper. A staging or test copy of the site gets a key of its own: run the script again with that site\'s address and another folder.', 'zorderz' ) . '</li>'
				. '<li>' . esc_html__( 'Put this site\'s zdz-secrets.php in the folder that holds wp-config.php. Only the web server\'s PHP needs to read it: keep its permissions as closed as your host allows.', 'zorderz' ) . '</li>'
				. '<li>' . esc_html__( 'Add these two lines to wp-config.php, above the line that loads wp-settings.php:', 'zorderz' )
				. ' <code>' . esc_html( $lines[0] ) . '</code> ' . esc_html__( 'and', 'zorderz' ) . ' <code>' . esc_html( $lines[1] ) . '</code> '
				. esc_html__( 'Keep the second line as it is written: with the check in front, a missing file locks the app\'s secrets but never takes the site down.', 'zorderz' ) . '</li>'
				. '<li>' . esc_html__( 'Come back to this page: it should say the key is in place, with its key id. Compare the key id with the one the script printed.', 'zorderz' ) . '</li></ol>';
			/* translators: %s: this site's address */
			echo '<p style="max-width:860px">' . esc_html( sprintf( __( 'Nothing uses the key yet in this version: setting it up changes nothing for anyone. This site is %s.', 'zorderz' ), $i['host'] ) ) . '</p>';
		}

		if ( '' !== $i['recorded_kid'] ) {
			/* translators: 1: a key id, 2: date and time (UTC), 3: who recorded it */
			$recorded = sprintf( __( '%1$s · %2$s UTC, by %3$s', 'zorderz' ), $i['recorded_kid'], substr( $i['recorded_at'], 0, 16 ), self::name_of( $i['recorded_by'] ) );
			if ( '' !== $i['recorded_site'] ) {
				/* translators: %s: a site address */
				$recorded .= sprintf( __( ', on %s', 'zorderz' ), $i['recorded_site'] );
			}
		} else {
			$recorded = __( '(none yet)', 'zorderz' );
		}
		if ( $i['prev_bad'] ) {
			$previous = __( 'ZDZ_APP_KEY_PREVIOUS is set but is not a printed key: it opens nothing', 'zorderz' );
		} elseif ( '' !== $i['prev_kid'] ) {
			$previous = $i['prev_kid'] . ' ' . ( $i['rotating'] ? __( '(a key change is under way)', 'zorderz' ) : __( '(can be removed once nothing is under it)', 'zorderz' ) );
		} else {
			$previous = __( 'not set', 'zorderz' );
		}
		if ( '' !== $i['kid'] ) {
			$in_file = $i['kid'];
		} else {
			$in_file = defined( 'ZDZ_APP_KEY' ) ? __( '(not a printed key)', 'zorderz' ) : __( '(no ZDZ_APP_KEY line)', 'zorderz' );
		}
		if ( '' !== $i['site_const'] ) {
			$file_for = $i['site_const'];
		} else {
			$file_for = defined( 'ZDZ_APP_KEY' ) ? __( '(the file names no site: add ZDZ_APP_KEY_SITE)', 'zorderz' ) : '–';
		}
		$scanned = ( ! empty( $i['unrecorded'] ) || $i['rotating'] || 'mismatch' === $i['state'] );
		$rows    = array(
			array( __( 'Key id in zdz-secrets.php', 'zorderz' ), $in_file ),
			array( __( 'Key id the app recorded', 'zorderz' ), $recorded ),
			array( __( 'The file is for', 'zorderz' ), $file_for ),
			array( __( 'This site', 'zorderz' ), $i['host'] ),
			array( __( 'Previous key', 'zorderz' ), $previous ),
			array( __( 'Key ids on stored values', 'zorderz' ), $scanned ? self::found_words() : __( 'checked only while something is unrecorded, changing or locked', 'zorderz' ) ),
			array( __( 'WordPress secrets key', 'zorderz' ), $i['wp_secrets'] ? __( 'WP_SECRETS_KEY is set', 'zorderz' ) : __( 'WP_SECRETS_KEY is not set', 'zorderz' ) ),
		);
		echo '<h2>' . esc_html__( 'The key', 'zorderz' ) . '</h2><table class="widefat striped" style="max-width:860px"><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><th style="width:240px">' . esc_html( $row[0] ) . '</th><td>' . esc_html( $row[1] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		$jobs = ZDZ_Keys_Migrate::jobs();
		echo '<h2>' . esc_html__( 'Moving stored values onto the key', 'zorderz' ) . '</h2>';
		if ( ! $jobs ) {
			echo '<p style="max-width:860px">' . esc_html__( 'Nothing is registered yet: in this version no part of Zorderz keeps its stored values under the app\'s key. Stored credentials, gate codes and sign-ins still depend on the WordPress salts, as before.', 'zorderz' ) . '</p>';
		} else {
			echo '<p style="max-width:860px">' . esc_html__( 'Each press moves up to 200 values and reads each one back before going on. A value it can\'t read is listed and left as it is, never wiped. Pressing again after it finishes counts again and changes nothing.', 'zorderz' );
			if ( $i['rotating'] ) {
				echo ' <strong>' . esc_html__( 'A key change ends links and signatures made with the previous key.', 'zorderz' ) . '</strong>';
			}
			echo '</p>';
			echo '<table class="widefat striped" style="max-width:860px"><thead><tr><th>' . esc_html__( 'What', 'zorderz' ) . '</th><th>' . esc_html__( 'Counts', 'zorderz' ) . '</th><th>' . esc_html__( 'Last run', 'zorderz' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $jobs as $id => $j ) {
				$st     = ZDZ_Keys_Migrate::status( $id );
				$counts = '' === $st['at'] ? __( 'not run yet', 'zorderz' ) : self::job_counts( $st ) . ( $st['finished'] ? '' : ' ' . __( '(not finished)', 'zorderz' ) );
				echo '<tr><td>' . esc_html( $j['label'] ) . '</td><td>' . esc_html( $counts ) . '</td>'
					. '<td>' . esc_html( '' === $st['at'] ? '–' : substr( $st['at'], 0, 16 ) . ' UTC' ) . '</td><td>';
				if ( 'ok' === $i['state'] ) {
					echo '<form method="post" action="' . esc_url( $url ) . '" style="margin:0">';
					wp_nonce_field( self::NONCE );
					echo '<input type="hidden" name="zdz_keys_do" value="run"><input type="hidden" name="zdz_keys_job" value="' . esc_attr( $id ) . '"><button class="button">' . esc_html__( 'Run', 'zorderz' ) . '</button></form>';
				}
				echo '</td></tr>';
				if ( ! empty( $st['damaged'] ) ) {
					$n_dmg = count( (array) $st['damaged'] );
					/* translators: %d: how many values */
					$head = sprintf( _n( '%d could not be put back as it was after a failed write:', '%d could not be put back as they were after a failed write:', $n_dmg, 'zorderz' ), $n_dmg );
					echo '<tr><td colspan="4"><div class="notice notice-error inline"><p><strong>' . esc_html( $head ) . '</strong> '
						. esc_html( implode( ', ', array_map( 'strval', (array) $st['damaged'] ) ) ) . '. '
						. esc_html__( 'These rows may hold neither their old value nor a readable new one. Before pressing anything else, restore them from a backup or enter them again where they are kept.', 'zorderz' ) . '</p></div></td></tr>';
				}
				if ( ! empty( $st['again'] ) ) {
					echo '<tr><td colspan="4"><span class="description">' . esc_html__( 'More values appeared since this pass began: press Run again for a new pass.', 'zorderz' ) . '</span></td></tr>';
				}
				if ( ! empty( $st['unreadable'] ) ) {
					$n_bad = (int) $st['unreadable_n'];
					/* translators: %d: how many values */
					$head = sprintf( _n( '%d couldn\'t be read (by row; left as it was)', '%d couldn\'t be read (by row; left as they were)', $n_bad, 'zorderz' ), $n_bad );
					echo '<tr><td colspan="4"><details><summary>' . esc_html( $head ) . '</summary><p>'
						. esc_html( implode( ', ', array_map( 'strval', (array) $st['unreadable'] ) ) . ( $n_bad > count( (array) $st['unreadable'] ) ? ' …' : '' ) ) . '</p></details></td></tr>';
				}
			}
			echo '</tbody></table>';
			if ( 'ok' === $i['state'] ) {
				echo '<form method="post" action="' . esc_url( $url ) . '" style="margin:10px 0">';
				wp_nonce_field( self::NONCE );
				echo '<input type="hidden" name="zdz_keys_do" value="run_all"><button class="button button-primary">' . esc_html__( 'Re-encrypt everything now', 'zorderz' ) . '</button></form>';
			}
		}

		$can = ZDZ_Keys::recordable();
		if ( null !== $can ) {
			echo '<h2>' . esc_html__( 'Record this key', 'zorderz' ) . '</h2>';
			echo '<form method="post" action="' . esc_url( $url ) . '" autocomplete="off">';
			wp_nonce_field( self::NONCE );
			echo '<input type="hidden" name="zdz_keys_do" value="record">';
			if ( ! empty( $i['unrecorded'] ) ) {
				/* translators: %s: a key id (8 characters) */
				$ask = sprintf( __( 'Type the printed key from your paper or password manager to record key id %s.', 'zorderz' ), $can[0] );
				echo '<p style="max-width:860px">' . esc_html__( 'The app has no record of its key: its key has been recorded on this site before (a restore can lose the record), or stored values are already sealed.', 'zorderz' )
					. ' <strong>' . esc_html( $ask ) . '</strong> ' . esc_html__( 'It is compared by key id and never kept.', 'zorderz' ) . '</p>';
				self::code_box();
			} elseif ( 'moved' === $can[1] ) {
				/* translators: %s: the address the key was recorded on */
				echo '<p style="max-width:860px">' . esc_html( sprintf( __( 'The app recorded its key on %s. zdz-secrets.php names this site and holds that same key, so this looks like a move to a new address. Record it only if the site really moved.', 'zorderz' ), $i['recorded_site'] ) ) . '</p>';
			}
			if ( 'moved' === $can[1] ) {
				/* translators: 1: a key id (8 characters), 2: this site's address */
				$press = sprintf( __( 'Record key id %1$s as the app\'s key on %2$s', 'zorderz' ), $can[0], $i['host'] );
			} else {
				/* translators: %s: a key id (8 characters) */
				$press = sprintf( __( 'Record key id %s as the app\'s key', 'zorderz' ), $can[0] );
			}
			echo '<button class="button">' . esc_html( $press ) . '</button></form>';
		}

		echo '<h2>' . esc_html__( 'Check a printed key', 'zorderz' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( $url ) . '" autocomplete="off">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="zdz_keys_do" value="check">';
		self::code_box( 'zdz-keys-code' );
		// "Show what I typed" unmasks the box, to compare with the paper. It is a text box, masked, and never a password
		// box, so the browser does not offer to save the key.
		echo '<button class="button">' . esc_html__( 'Check', 'zorderz' ) . '</button><br>'
			. '<label style="display:inline-block;margin:6px 8px 0 0"><input type="checkbox" onchange="document.getElementById(\'zdz-keys-code\').style.webkitTextSecurity=this.checked?\'none\':\'disc\'"> ' . esc_html__( 'Show what I typed', 'zorderz' ) . '</label>'
			. '<span class="description">' . esc_html__( 'Says which key a printed code is (this site\'s, the recorded one, or the previous one), and how many characters it read. Nothing is stored.', 'zorderz' ) . '</span></form>';

		echo '<h2>' . esc_html__( 'If the host changes wp-config.php', 'zorderz' ) . '</h2><ol style="max-width:860px">'
			. '<li>' . esc_html__( 'Change nothing yet. Look at this page, at Zorderz → Gate code key and at any app\'s settings that read their own lines from wp-config.php, and note what they say.', 'zorderz' ) . '</li>'
			. '<li>' . esc_html__( 'If only the salts changed, everyone signs in again. What is not yet sealed under the app\'s key still depends on the salts: the salt notice lists what a change to them breaks.', 'zorderz' ) . '</li>'
			. '<li>' . esc_html__( 'If this page says the key is missing, the require line or zdz-secrets.php went: put it back from the password manager. Never make a new key.', 'zorderz' ) . '</li>'
			. '<li>' . esc_html__( 'After one environment is copied onto another (a staging copy made from the live site, or back), put the destination\'s own zdz-secrets.php back in place, then look at this page there.', 'zorderz' ) . '</li></ol>';
		echo '</div>';
	}
}
