<?php
/**
 * ZDZ_Sealed_Admin -- Zorderz -> Gate code key: the gate codes' key, its check and its printed recovery code.
 *
 * Every key stays exactly as it is. The gate codes' key is not changed here, and nothing here can make a new one.
 * The page:
 *   - says whether codes can be sealed and opened, and if not why (no real salts in wp-config.php, ZDZ_SEALED_KEY not
 *     a recovery code, or LOCKED: the key is not the one the codes were sealed with);
 *   - shows today's key as a printed recovery code each time an administrator presses "Show the recovery code" (a POST
 *     with a nonce; the code is in that one response only, never stored, never in REST or a log; each showing is
 *     written to the audit log as "sealed_recovery_shown", without the code);
 *   - "I've written it down" (option zdz_sealed_code_saved = 'Y-m-d H:i:s|uid'); until then a nudge on this page;
 *   - "Check a printed code": says whether a typed code is today's key, how many characters it read and the
 *     look-alikes that are all valid (5/S, 2/Z, 6/G, U/V); nothing stored, the code never echoed;
 *   - when locked, says what to do: put the saved salt lines back, or the printed code in wp-config.php as
 *     ZDZ_SEALED_KEY; and, only if neither can be, "Accept today's key" (type ACCEPT; logged as sealed_key_accepted);
 *   - the admin tier only, never the shared device or Demo Mode (ZDZ_Sealed::may_manage_key()); the code and the
 *     acceptance only once their audit row is written (fail closed).
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Sealed_Admin {

	const PAGE = 'zdz-gate-code-key';

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ), 21 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_filter( 'site_status_tests', array( __CLASS__, 'site_health' ) );
	}

	public static function site_health( $tests ) {
		$tests['direct']['zdz_sealed_codes'] = array( 'label' => __( 'Zorderz: sealed codes', 'zorderz' ), 'test' => array( __CLASS__, 'site_health_result' ) );
		return $tests;
	}

	public static function site_health_result() {
		list( $cls, $text ) = self::status();
		$ok = ZDZ_Sealed::can_keep();
		return array(
			'label'       => $ok ? __( 'Gate codes can be sealed and opened', 'zorderz' ) : ( ZDZ_Sealed::locked() ? __( 'Gate codes are locked: the site\'s keys changed', 'zorderz' ) : __( 'Gate codes can\'t be sealed or opened', 'zorderz' ) ),
			'status'      => $ok ? 'good' : 'critical',
			'badge'       => array( 'label' => __( 'Security', 'zorderz' ), 'color' => $ok ? 'blue' : 'red' ),
			'description' => '<p>' . esc_html( $text ) . '</p>',
			'actions'     => '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Zorderz → Gate code key', 'zorderz' ) . '</a>',
			'test'        => 'zdz_sealed_codes',
		);
	}

	public static function add_page() {
		add_submenu_page( 'zdz-core-settings', __( 'Gate code key', 'zorderz' ), __( 'Gate code key', 'zorderz' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function page_url() {
		return add_query_arg( array( 'page' => self::PAGE ), admin_url( 'admin.php' ) );
	}

	/** Locked codes: a notice on every admin page, for administrators. */
	public static function notice() {
		if ( ! current_user_can( 'manage_options' ) || ! ZDZ_Sealed::locked() ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Gate codes are locked: the site\'s keys changed.', 'zorderz' ) . '</strong> '
			. esc_html__( 'No gate, lockbox, alarm, door, or keypad code can be opened or saved until they are unlocked.', 'zorderz' ) . ' '
			. '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Open Zorderz → Gate code key', 'zorderz' ) . '</a> ' . esc_html__( 'for what to do.', 'zorderz' ) . '</p></div>';
	}

	/** What the page says about the codes' state: [ notice class, sentence ]. */
	public static function status() {
		$why  = ZDZ_Sealed::not_ready_reason();
		$says = array(
			''             => array( 'notice-success', defined( 'ZDZ_SEALED_KEY' )
				? __( 'Codes are sealed and opened with the key in ZDZ_SEALED_KEY (wp-config.php). They keep opening even if the site\'s secret keys change.', 'zorderz' )
				: __( 'Codes are sealed and opened with a key made from the site\'s secret keys in wp-config.php.', 'zorderz' ) ),
			'locked'       => array( 'notice-error', __( 'Gate codes are locked: the site\'s keys changed. The key the site has now is not the one the codes were sealed with, so no code opens and no new code is saved.', 'zorderz' ) ),
			'no_salts'     => array( 'notice-error', __( 'Codes can\'t be sealed or opened: the site\'s secret keys (AUTH_KEY, AUTH_SALT, SECURE_AUTH_KEY, and SECURE_AUTH_SALT) are not all set in wp-config.php. Without them a copy of the database alone could open the codes, so nothing is sealed.', 'zorderz' ) ),
			'bad_constant' => array( 'notice-error', __( 'ZDZ_SEALED_KEY in wp-config.php is not a recovery code (13 groups of four letters and digits, as printed). No code opens until it is corrected or removed.', 'zorderz' ) ),
			'no_sodium'    => array( 'notice-error', __( 'Codes can\'t be sealed: this server\'s PHP has no libsodium.', 'zorderz' ) ),
		);
		return $says[ $why ] ?? array( 'notice-error', __( 'Codes can\'t be sealed or opened right now.', 'zorderz' ) );
	}

	/**
	 * How a typed code was read, for "Check a printed code": array( characters read, marks left out ). The reading
	 * ZDZ_Sealed::code_matches() makes (0, 1 and 8 read as O, I and B; spaces and dashes not counted; any other mark left
	 * out). Counts only: the code itself is never returned, kept or logged.
	 */
	public static function code_reading( $code ) {
		$s    = strtr( strtoupper( (string) $code ), array( '0' => 'O', '1' => 'I', '8' => 'B' ) );
		$read = strlen( (string) preg_replace( '/[^A-Z2-7]/', '', $s ) );
		// characters, not bytes: a pasted non-breaking space or dash is spacing, an "é" is one mark
		$rest = preg_replace( '/[A-Z2-7\s\p{Z}\p{Pd}]/u', '', $s );
		$left = null === $rest ? strlen( (string) preg_replace( '/[A-Z2-7\s\-]/', '', $s ) ) : ( function_exists( 'mb_strlen' ) ? mb_strlen( $rest, 'UTF-8' ) : preg_match_all( '/./us', $rest ) );
		return array( $read, $left );
	}

	/** An audit row; true only when it was written (a row id). */
	private static function record( $uid, $what, $detail ) {
		global $wpdb;
		if ( ! class_exists( 'ZDZ_Admin_Dashboard' ) || ! method_exists( 'ZDZ_Admin_Dashboard', 'log_action' ) ) {
			return false;
		}
		$ok = ZDZ_Admin_Dashboard::log_action( (int) $uid, $what, $detail, 'zdz-theme' );
		return true === $ok && is_object( $wpdb ) && (int) $wpdb->insert_id > 0;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$uid = (int) get_current_user_id();
		if ( ! ZDZ_Sealed::may_manage_key( $uid ) ) {
			// the admin tier only, never the shared device or Demo Mode
			echo '<div class="wrap"><h1>' . esc_html__( 'Gate code key', 'zorderz' ) . '</h1><p>' . esc_html__( 'Only an administrator, signed in as themselves (not the shared device or Demo Mode), can manage the gate codes\' key.', 'zorderz' ) . '</p></div>';
			return;
		}
		$show   = false;
		$msg    = null;
		$posted = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
		if ( $posted && isset( $_POST['zdz_sealed_do'] ) ) {
			check_admin_referer( 'zdz_sealed_codes' );
			$do = sanitize_key( wp_unslash( $_POST['zdz_sealed_do'] ) );
			if ( 'show' === $do && ZDZ_Sealed::can_keep() ) {
				// the code is shown only once its showing is written down (fail closed, as open() does)
				$show = self::record( $uid, 'sealed_recovery_shown', 'Gate code key: the recovery code was shown' );
				if ( ! $show ) {
					$msg = array( 'notice-error', __( 'The recovery code was not shown: its showing could not be recorded. Try again.', 'zorderz' ) );
				}
			} elseif ( 'accept' === $do ) {
				$typed = (string) wp_unslash( $_POST['zdz_sealed_accept'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared with ACCEPT only
				if ( 'ACCEPT' !== trim( $typed ) ) {
					$msg = array( 'notice-error', __( 'Nothing was changed: type ACCEPT to accept today\'s key.', 'zorderz' ) );
				} elseif ( ! ZDZ_Sealed::locked() ) {
					$msg = array( 'notice-error', __( 'Nothing was changed: the codes are not locked.', 'zorderz' ) );
				} elseif ( ! self::record( $uid, 'sealed_key_accepted', 'Gate code key: today\'s key was accepted after a lock' ) ) {
					$msg = array( 'notice-error', __( 'Nothing was changed: the acceptance could not be recorded. Try again.', 'zorderz' ) );
				} elseif ( ZDZ_Sealed::accept_current_key( $uid, $typed ) ) {
					$msg = array( 'notice-success', __( 'Today\'s key is accepted: new codes are kept again. Codes saved before stay unreadable unless the saved salt lines or ZDZ_SEALED_KEY are put back. Print the recovery code again: it has changed with the key.', 'zorderz' ) );
				} else {
					$msg = array( 'notice-error', __( 'Nothing was changed: there is no key to accept (see above).', 'zorderz' ) );
				}
			} elseif ( 'saved' === $do && ZDZ_Sealed::can_keep() ) {
				ZDZ_Sealed::mark_code_saved( $uid );
				$msg = array( 'notice-success', __( 'Thank you. Keep the printed code with your other printed codes.', 'zorderz' ) );
			} elseif ( 'check' === $do ) {
				$typed = (string) wp_unslash( $_POST['zdz_sealed_code'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read as a code, never stored or echoed
				// how many characters were read, and which look-alikes are both valid letters, so a misread is easy to
				// find on the paper (a 5 read as S). Counts only; the code is never echoed.
				list( $n_read, $n_left ) = self::code_reading( $typed );
				/* translators: %d: how many characters of the typed code were read */
				$read = sprintf( _n( 'It read %d character', 'It read %d characters', (int) $n_read, 'zorderz' ), (int) $n_read )
					/* translators: %d: how many other marks were left out */
					. ( $n_left ? sprintf( _n( ', and left out %d other mark', ', and left out %d other marks', (int) $n_left, 'zorderz' ), (int) $n_left ) : '' );
				$msg  = ZDZ_Sealed::code_matches( $typed )
					? array( 'notice-success', __( 'That code matches the gate codes\' key.', 'zorderz' ) . ' ' . $read . '.' )
					: array( 'notice-error', __( 'That code does not match the gate codes\' key.', 'zorderz' ) . ' ' . $read . '; '
						. __( 'a printed code has 52. The look-alikes 5 and S, 2 and Z, 6 and G, U and V are all valid characters, so one misread can still look right: check each of those against the paper. (0, 1 and 8 are read as O, I and B.)', 'zorderz' ) );
				$typed = '';
			}
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Gate code key', 'zorderz' ) . '</h1>';
		echo '<p style="max-width:860px">' . esc_html__( 'Gate, lockbox, alarm, door, and keypad codes are kept sealed, never in plain text. This page shows whether they can be opened, and lets an administrator print the recovery code. It never makes a new key.', 'zorderz' ) . '</p>';
		list( $cls, $text ) = self::status();
		echo '<div class="notice ' . esc_attr( $cls ) . ' inline"><p>' . esc_html( $text ) . '</p></div>';
		if ( $msg ) {
			echo '<div class="notice ' . esc_attr( $msg[0] ) . ' inline"><p>' . esc_html( $msg[1] ) . '</p></div>';
		}
		$action = esc_url( self::page_url() );

		if ( ZDZ_Sealed::locked() || 'bad_constant' === ZDZ_Sealed::not_ready_reason() ) {
			echo '<h2>' . esc_html__( 'To unlock them', 'zorderz' ) . '</h2><ol style="max-width:860px">'
				. '<li>' . esc_html__( 'If the site\'s secret keys were changed by mistake, put the saved lines back in wp-config.php (they are in your password manager). That unlocks everything at once.', 'zorderz' ) . '</li>'
				. '<li>' . esc_html__( 'Otherwise, add this line to wp-config.php on the server, with the recovery code you printed in place of the letters:', 'zorderz' ) . ' <code>define( \'ZDZ_SEALED_KEY\', \'AAAA BBBB CCCC …\' );</code> ' . esc_html__( 'The codes sealed before the change open again.', 'zorderz' ) . '</li>'
				. '<li>' . esc_html__( 'Do not make a new key. A new key would lose every code sealed so far.', 'zorderz' ) . '</li></ol>';
		}
		if ( ZDZ_Sealed::locked() ) {
			echo '<h2>' . esc_html__( 'Only if neither can be put back', 'zorderz' ) . '</h2>';
			echo '<p style="max-width:860px">' . esc_html__( 'While the codes are locked, every new code is cut out of the words and lost. If the saved salt lines and the printed code are both gone, you can accept today\'s key so new codes are kept again.', 'zorderz' ) . ' <strong>' . esc_html__( 'Accept today\'s key. Codes saved before stay unreadable unless the saved salt lines or ZDZ_SEALED_KEY are put back.', 'zorderz' ) . '</strong></p>';
			echo '<form method="post" action="' . $action . '" autocomplete="off">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url() above
			wp_nonce_field( 'zdz_sealed_codes' );
			echo '<input type="hidden" name="zdz_sealed_do" value="accept"><label>' . esc_html__( 'Type ACCEPT:', 'zorderz' ) . ' <input type="text" name="zdz_sealed_accept" value="" class="regular-text" autocomplete="off"></label> <button class="button">' . esc_html__( 'Accept today\'s key', 'zorderz' ) . '</button></form>';
		}

		if ( ZDZ_Sealed::can_keep() ) {
			echo '<h2>' . esc_html__( 'Recovery code', 'zorderz' ) . '</h2>';
			if ( ! ZDZ_Sealed::code_saved() ) {
				echo '<p style="max-width:860px"><strong>' . esc_html__( 'Print the recovery code and keep it with your other printed codes.', 'zorderz' ) . '</strong> ' . esc_html__( 'Without it, a change to the site\'s secret keys would lock every gate code for good.', 'zorderz' ) . '</p>';
			} else {
				$parts = explode( '|', (string) get_option( ZDZ_Sealed::SAVED_OPT, '' ) );
				/* translators: %s: the date the recovery code was marked as written down */
				echo '<p>' . esc_html( sprintf( __( 'Written down on %s. You can show it again at any time; it is always the same code.', 'zorderz' ), substr( (string) $parts[0], 0, 10 ) ) ) . '</p>';
			}
			if ( $show ) {
				$code = ZDZ_Sealed::recovery_code();
				echo '<p style="font:600 22px/1.5 ui-monospace,Menlo,monospace;letter-spacing:1px;background:#fff;border:1px solid #c3c4c7;padding:12px 16px;max-width:860px;word-spacing:6px">' . esc_html( $code ) . '</p>';
				$code = '';
				echo '<p style="max-width:860px">' . esc_html__( 'This is the gate codes\' key itself: whoever holds it can open every code. It is shown on this page now only, and this showing was recorded. Optional, for safety: add the same code to wp-config.php as', 'zorderz' ) . ' <code>define( \'ZDZ_SEALED_KEY\', \'…\' );</code> ' . esc_html__( 'Then the codes keep opening even if the site\'s secret keys change.', 'zorderz' ) . '</p>';
				echo '<form method="post" action="' . $action . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url() above
				wp_nonce_field( 'zdz_sealed_codes' );
				echo '<input type="hidden" name="zdz_sealed_do" value="saved"><button class="button button-primary">' . esc_html__( 'I\'ve written it down', 'zorderz' ) . '</button></form>';
			} else {
				echo '<form method="post" action="' . $action . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url() above
				wp_nonce_field( 'zdz_sealed_codes' );
				echo '<input type="hidden" name="zdz_sealed_do" value="show"><button class="button">' . esc_html__( 'Show the recovery code', 'zorderz' ) . '</button> <span class="description">' . esc_html__( 'Shown each time you press it, and recorded.', 'zorderz' ) . '</span></form>';
			}
		}

		echo '<h2>' . esc_html__( 'Check a printed code', 'zorderz' ) . '</h2>';
		echo '<form method="post" action="' . $action . '" autocomplete="off">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url() above
		wp_nonce_field( 'zdz_sealed_codes' );
		// "Show what I typed" turns the box to plain text, to compare with the paper; it stays a password box otherwise,
		// so the key is not kept in the browser's form history
		echo '<input type="hidden" name="zdz_sealed_do" value="check"><input type="password" id="zdz-sealed-code" name="zdz_sealed_code" value="" class="regular-text" autocomplete="new-password" autocapitalize="characters" spellcheck="false" style="font-family:ui-monospace,Menlo,monospace;max-width:100%" placeholder="AAAA BBBB CCCC …"> <button class="button">' . esc_html__( 'Check', 'zorderz' ) . '</button> '
			. '<label style="display:inline-block;margin:6px 8px 0 0"><input type="checkbox" onchange="document.getElementById(\'zdz-sealed-code\').type=this.checked?\'text\':\'password\'"> ' . esc_html__( 'Show what I typed', 'zorderz' ) . '</label> '
			. '<span class="description">' . esc_html__( 'Says whether a printed code is this site\'s, and how many characters it read. Nothing is stored.', 'zorderz' ) . '</span></form>';
		echo '</div>';
	}
}
