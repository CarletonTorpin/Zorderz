<?php
/**
 * ZAGR_Admin - Settings -> Agreements: administrators only, never the shared device. [CORE screen; the values are the
 * business's]
 *
 * In order: a plain statement that the legal packs and the starter template are not legal advice; who gets agreements
 * (off / test / on, with the readiness list from the app and the legal packs; "on" refused until it is complete); the
 * legal packs (which apply, where, what each rule says and where it comes from, when it was last checked); the business
 * facts; lead times; the template in use and its approval (its own confirmed step, for the exact files shown, naming
 * who approved it); the Key panel; and the latest agreements with their record's check, "Make a new link", "Record a
 * cancellation", "Turn codes back on" and "Email the signed copy again".
 *
 * Each unsigned agreement's row has "Signed on paper" (the day, the answers the agreement's legal packs ask, and
 * photos: uploaded from the computer, stored as the administrator's own private photos, or picked from the last 14
 * days' photos by the administrator and the one who signed for the business; this screen never asks the salesperson
 * filter, which may call out to the billing system), through the same server path as the app's button
 * (ZAGR_Paper::record). A row signed on paper shows the day, who recorded it and where, the photos as thumbnails
 * (ZDZ_User_Media's gated links, never a share link) with whether each is unchanged (its SHA-256 still matches), and,
 * until it is OK to order, "Undo the paper signing" with a typed reason. A backdated paper date is flagged on the row;
 * one recorded after its cancellation period had ended has "Confirm this late paper signing" (administrators, its own
 * nonce, a tick that the photo and the paper date were looked at, an optional note), and its undo until then.
 *
 * The Key panel: "Make a key" draws 32 random bytes in the administrator's own browser (crypto.getRandomValues), shows
 * them once, and sends nothing until they are pasted back exactly; "Enter a key" takes a saved one. The server never
 * shows a key back, never logs it, and keeps it only sealed (ZAGR_Key).
 *
 * @package Zorderz\Agreements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAGR_Admin {

	const SLUG = 'zagr-agreements';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'foreign_notice' ) );
		foreach ( array( 'mode', 'facts', 'lead', 'template', 'approve', 'unapprove', 'key', 'rotate', 'cancel', 'codes', 'resend', 'paper', 'paper_undo', 'paper_confirm' ) as $a ) {
			add_action( 'admin_post_zagr_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
	}

	/** An administrator sees, on every admin screen, that tables of another shape stop the app. */
	public static function foreign_notice(): void {
		if ( self::allowed() && ! ZAGR_Install::ready() ) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Agreements:', 'zorderz' ) . '</strong> ' . esc_html( self::foreign_words() ) . '</p></div>';
		}
	}

	public static function menu(): void {
		add_options_page( __( 'Agreements', 'zorderz' ), __( 'Agreements', 'zorderz' ), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function assets( $hook ): void {
		if ( 'settings_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_script( 'zagr-admin-key', ZAGR_URL . 'assets/js/zagr-admin-key.js', array(), ZAGR_VERSION, true );
		wp_enqueue_style( 'zagr-admin', ZAGR_URL . 'assets/css/zagr-admin.css', array(), ZAGR_VERSION );
	}

	/** Administrators only; the shared device and Demo Mode never. */
	public static function allowed(): bool {
		return ZAGR_People::is_admin( (int) get_current_user_id() );
	}

	private static function guard( string $action ): int {
		$uid = (int) get_current_user_id();
		if ( ! ZAGR_People::is_admin( $uid ) ) {
			wp_die( esc_html__( 'Only an administrator can change the agreements settings.', 'zorderz' ), 403 );
		}
		check_admin_referer( 'zagr_' . $action );
		if ( ! ZAGR_Install::ready() ) {
			self::back( $uid, self::foreign_words(), false );
		}
		return $uid;
	}

	/**
	 * What the notice says about tables of another shape. It tells an administrator how to remove them only when every
	 * agreement in them was checked and is marked as a test agreement; otherwise, never.
	 */
	private static function foreign_words(): string {
		$h = __( 'The agreements app found tables of another shape that it can\'t use, so it takes no agreements and leaves the tables as they are.', 'zorderz' );
		$r = ZAGR_Install::foreign_rows();
		if ( null !== $r && 0 === $r['real'] ) {
			return $h . ( 0 === $r['agreements'] ? ' They hold no agreements.' : ' They hold ' . $r['agreements'] . ' ' . ( 1 === $r['agreements'] ? 'agreement' : 'agreements' ) . ', all test rows (every one is marked as a test agreement).' ) . ' To start again, remove the tables '
				. ZAGR_Settings::oxford( array_values( ZAGR_Install::tables() ) ) . ', then deactivate and activate the Zorderz apps.';
		}
		return $h . ' ' . ( null === $r ? 'What they hold couldn\'t be checked.' : 'They hold ' . $r['agreements'] . ' ' . ( 1 === $r['agreements'] ? 'agreement' : 'agreements' ) . ', ' . $r['real'] . ' of them not marked as a test.' )
			. ' Don\'t remove them: they may hold signed agreements. Ask a developer to look at them.';
	}

	private static function back( int $uid, string $message, bool $ok ): void {
		set_transient( 'zagr_notice_' . $uid, array( 'm' => $message, 'ok' => $ok ), 120 );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::SLUG ) );
		exit;
	}

	/* ---------------------------------------------------------------- handlers */

	public static function handle_mode(): void {
		$uid = self::guard( 'mode' );
		$r   = ZAGR_Settings::set_mode( sanitize_key( (string) ( $_POST['mode'] ?? '' ) ) ); // phpcs:ignore
		self::back( $uid, $r['message'], $r['ok'] );
	}

	public static function handle_facts(): void {
		$uid = self::guard( 'facts' );
		$p   = wp_unslash( $_POST ); // phpcs:ignore
		ZAGR_Settings::save_facts( array(
			'legal_name' => $p['legal_name'] ?? '', 'address_lines' => (string) ( $p['address_lines'] ?? '' ), 'license' => $p['license'] ?? '',
			'cancel_email' => $p['cancel_email'] ?? '', 'help_phone' => $p['help_phone'] ?? '', 'substantial_commencement' => $p['substantial_commencement'] ?? '',
			'cgl_insurer' => $p['cgl_insurer'] ?? '', 'cgl_phone' => $p['cgl_phone'] ?? '', 'subcontractors' => (string) ( $p['subcontractors'] ?? '' ),
		) );
		$fixture = ZAGR_Settings::clean( $p['test_fixture'] ?? '', 60 );
		update_option( ZAGR_Settings::OPT_TEST, $fixture );
		self::back( $uid, __( 'Saved. New drafts use these facts; agreements already made keep the words they were made with.', 'zorderz' ), true );
	}

	/** The lead-time groups: every row of the table, blank rows dropped. */
	public static function handle_lead(): void {
		$uid  = self::guard( 'lead' );
		$rows = wp_unslash( $_POST['lead'] ?? array() ); // phpcs:ignore -- each field cleaned by ZAGR_Settings::clean_group()
		ZAGR_Settings::save_lead( is_array( $rows ) ? array_values( $rows ) : array() );
		self::back( $uid, __( 'Lead times saved. New drafts use them; agreements already made keep theirs.', 'zorderz' ), true );
	}

	/** The version in use. Choosing a version never approves it. */
	public static function handle_template(): void {
		$uid = self::guard( 'template' );
		$v   = (string) wp_unslash( $_POST['version'] ?? '' ); // phpcs:ignore
		if ( ! ZAGR_Settings::set_template_version( $v ) ) {
			self::back( $uid, __( 'That template version isn\'t on the server.', 'zorderz' ), false );
		}
		self::back( $uid, 'Template ' . $v . ' is the version in use.' . ( ZAGR_Settings::template_approved() ? '' : ' Its approval isn\'t recorded yet.' ), true );
	}

	/** The approval: the version and files fingerprint the screen showed, who approved it, and the confirmation. */
	public static function handle_approve(): void {
		$uid = self::guard( 'approve' );
		$r   = ZAGR_Settings::approve_template( (string) wp_unslash( $_POST['version'] ?? '' ), strtolower( (string) wp_unslash( $_POST['files_hash'] ?? '' ) ), $uid, '1' === (string) ( $_POST['confirm'] ?? '' ), (string) wp_unslash( $_POST['approver'] ?? '' ) ); // phpcs:ignore
		self::back( $uid, $r['message'], $r['ok'] );
	}

	public static function handle_unapprove(): void {
		$uid = self::guard( 'unapprove' );
		$r   = ZAGR_Settings::unapprove_template( (string) wp_unslash( $_POST['version'] ?? '' ), $uid ); // phpcs:ignore
		self::back( $uid, $r['message'], $r['ok'] );
	}

	public static function handle_codes(): void {
		$uid = self::guard( 'codes' );
		$r   = ZAGR_Codes::reset_locks( (int) ( $_POST['agreement'] ?? 0 ), $uid ); // phpcs:ignore
		self::back( $uid, $r['message'], $r['ok'] );
	}

	public static function handle_resend(): void {
		$uid = self::guard( 'resend' );
		$r   = ZAGR_Flow::resend_copy( (int) ( $_POST['agreement'] ?? 0 ), $uid ); // phpcs:ignore
		self::back( $uid, $r['message'], $r['ok'] );
	}

	public static function handle_key(): void {
		$uid = self::guard( 'key' );
		$k   = (string) wp_unslash( $_POST['zagr_key'] ?? '' ); // phpcs:ignore -- never echoed, never logged
		$r   = ZAGR_Key::enter( $k, $uid, ! empty( $_POST['replace'] ) ); // phpcs:ignore
		$k   = str_repeat( "\0", strlen( $k ) );
		unset( $_POST['zagr_key'], $_REQUEST['zagr_key'] );
		self::back( $uid, $r['message'], $r['ok'] );
	}

	public static function handle_rotate(): void {
		$uid = self::guard( 'rotate' );
		$r   = ZAGR_Flow::rotate_link( (int) ( $_POST['agreement'] ?? 0 ), $uid ); // phpcs:ignore
		self::back( $uid, $r['message'], $r['ok'] );
	}

	/** "Signed on paper" from a row (uploads and picked photos), through ZAGR_Paper::record. */
	public static function handle_paper(): void {
		$uid = self::guard( 'paper' );
		$r   = ZAGR_Paper::from_admin( (int) ( $_POST['agreement'] ?? 0 ), $uid, (array) wp_unslash( $_POST ), $_FILES ); // phpcs:ignore -- checked field by field there
		self::back( $uid, $r['message'], $r['ok'] );
	}

	/** "Undo the paper signing", with a typed reason. */
	public static function handle_paper_undo(): void {
		$uid = self::guard( 'paper_undo' );
		$r   = ZAGR_Paper::undo( (int) ( $_POST['agreement'] ?? 0 ), $uid, (string) wp_unslash( $_POST['reason'] ?? '' ) ); // phpcs:ignore
		self::back( $uid, $r['message'], $r['ok'] );
	}

	/**
	 * "Confirm this late paper signing", guarded as the undo (an administrator, its own nonce), with the tick that the
	 * photo and the paper date were looked at and an optional note.
	 */
	public static function handle_paper_confirm(): void {
		$uid = self::guard( 'paper_confirm' );
		$r   = ZAGR_Paper::confirm_late( (int) ( $_POST['agreement'] ?? 0 ), $uid, '1' === (string) ( $_POST['looked'] ?? '' ), (string) wp_unslash( $_POST['note'] ?? '' ) ); // phpcs:ignore
		self::back( $uid, $r['message'], $r['ok'] );
	}

	public static function handle_cancel(): void {
		$uid = self::guard( 'cancel' );
		$r   = ZAGR_Flow::staff_cancel( (int) ( $_POST['agreement'] ?? 0 ), sanitize_key( (string) ( $_POST['how'] ?? '' ) ), (string) ( $_POST['sent_on'] ?? '' ), $uid ); // phpcs:ignore
		self::back( $uid, $r['message'], $r['ok'] );
	}

	/* ---------------------------------------------------------------- the screen */

	private static function e( $s ): string {
		return esc_html( (string) $s );
	}

	private static function post_form( string $action, string $inner, string $class = '', bool $files = false ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . ( $files ? ' enctype="multipart/form-data"' : '' ) . '>'
			. '<input type="hidden" name="action" value="zagr_' . esc_attr( $action ) . '">' . wp_nonce_field( 'zagr_' . $action, '_wpnonce', true, false ) . $inner . '</form>';
	}

	public static function render(): void {
		if ( ! self::allowed() ) {
			wp_die( esc_html__( 'Only an administrator can open the agreements settings.', 'zorderz' ), 403 );
		}
		echo self::screen( (int) get_current_user_id() ); // phpcs:ignore -- built escaped
	}

	/** The readiness rows as a list: done, still needed, or (an item about one agreement) asked per agreement. */
	private static function ready_list( array $rows ): string {
		$h = '<ul class="zagr-ready">';
		foreach ( $rows as $r ) {
			if ( ! empty( $r['per_agreement'] ) ) {
				$h .= '<li class="info">' . self::e( __( 'For each agreement:', 'zorderz' ) . ' ' . ucfirst( $r['label'] ) ) . ( '' !== $r['citation'] ? ' <span class="description">(' . self::e( $r['citation'] ) . ')</span>' : '' ) . '</li>';
				continue;
			}
			$cls = $r['ok'] ? 'ok' : ( $r['needed'] ? 'todo' : 'later' );
			$say = $r['ok'] ? __( 'Done:', 'zorderz' ) : ( $r['needed'] ? __( 'Still needed:', 'zorderz' ) : __( 'Needed before customers sign:', 'zorderz' ) );
			$h  .= '<li class="' . $cls . '">' . self::e( $say . ' ' . ucfirst( $r['label'] ) ) . ( '' !== $r['citation'] ? ' <span class="description">(' . self::e( $r['citation'] ) . ')</span>' : '' ) . '</li>';
		}
		return $h . '</ul>';
	}

	/** The legal packs: the one chosen, which state layers exist, what each rule says, where it comes from. */
	private static function packs_card(): string {
		$sel  = ZAGR_Pack::selected();
		$h    = '<div class="zagr-card"><h2>' . self::e( __( 'Legal rules (packs)', 'zorderz' ) ) . '</h2>';
		$h   .= '<p>' . self::e( __( 'The pack in use is chosen in the Business Profile (Legal pack). The national pack applies to every job; a state pack is added on top for a job whose address is in that state. The job\'s address decides, not the office\'s.', 'zorderz' ) ) . '</p>';
		$h   .= '<p><strong>' . self::e( __( 'Chosen pack:', 'zorderz' ) ) . '</strong> ' . self::e( '' === $sel ? __( 'none (no agreement is made)', 'zorderz' ) : $sel ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . ( class_exists( 'ZDZ_Business_Profile_Admin' ) ? ZDZ_Business_Profile_Admin::SLUG : 'zdz-business-profile' ) ) ) . '">' . self::e( __( 'Change it in the Business Profile', 'zorderz' ) ) . '</a></p>';
		$region = class_exists( 'ZDZ_Business_Profile' ) ? ZAGR_Pack::region_of( array( 'region' => (string) ZDZ_Business_Profile::get( 'contact.address.region', '' ) ) ) : '';
		foreach ( ZAGR_Pack::ids() as $id ) {
			try {
				$p = ZAGR_Pack::load( $id );
			} catch ( ZAGR_Pack_Error $e ) {
				$h .= '<p class="zagr-bad">' . self::e( $id . ': ' . $e->getMessage() ) . '</p>';
				continue;
			}
			$m   = $p['meta'];
			$reg = strtoupper( (string) ( $m['region'] ?? '' ) );
			$h  .= '<details class="zagr-pack"' . ( $id === $sel ? ' open' : '' ) . '><summary><strong>' . self::e( (string) ( $m['label'] ?? $id ) ) . '</strong> (' . self::e( $id ) . ')'
				. ( '' !== $reg ? ' ' . self::e( sprintf( /* translators: %s: a state code */ __( 'for jobs in %s', 'zorderz' ), $reg ) ) : '' )
				. ' <span class="description">' . self::e( sprintf( /* translators: %s: a date */ __( 'last checked %s', 'zorderz' ), (string) ( $m['last_checked'] ?? '' ) ) ) . '</span></summary>';
			if ( '' !== $reg && $reg === $region && $id !== $sel ) {
				$h .= '<p class="notice-inline">' . self::e( sprintf( /* translators: %s: a state code */ __( 'Your business address is in %s: this pack applies to every job there on its own. You may also choose it as your pack in the Business Profile.', 'zorderz' ), $reg ) ) . '</p>';
			}
			$h .= '<p>' . self::e( (string) ( $m['summary'] ?? '' ) ) . '</p><ul class="zagr-rules">';
			$r  = $p['rules'];
			foreach ( (array) $r['windows'] as $w ) {
				$when = array();
				foreach ( (array) ( $w['when'] ?? array() ) as $q => $v ) {
					$when[] = $q . ' = ' . $v;
				}
				$h .= '<li>' . self::e( sprintf( __( '%1$d business days to cancel%2$s.', 'zorderz' ), (int) $w['days'], $when ? ' (' . implode( ', ', $when ) . ')' : '' ) ) . ' <span class="description">' . self::e( $w['citation'] . '; in force from ' . $w['effective_from'] . '; checked ' . $w['last_checked'] ) . '</span></li>';
			}
			$bd = (array) $r['business_days'];
			$h .= '<li>' . self::e( __( 'Business days:', 'zorderz' ) . ' ' . (string) ( $bd['note'] ?? '' ) ) . ' <span class="description">' . self::e( $bd['citation'] . '; checked ' . $bd['last_checked'] ) . '</span></li>';
			if ( is_array( $r['deposit_cap'] ?? null ) ) {
				$h .= '<li>' . self::e( (string) ( $r['deposit_cap']['note'] ?? '' ) ) . ' <span class="description">' . self::e( $r['deposit_cap']['citation'] ) . '</span></li>';
			}
			foreach ( (array) ( $r['flags'] ?? array() ) as $f ) {
				$h .= '<li>' . self::e( (string) $f['text'] ) . ' <span class="description">' . self::e( $f['citation'] ) . '</span></li>';
			}
			$h .= '</ul><p class="description">' . self::e( __( 'Sources:', 'zorderz' ) ) . '</p><ul>';
			foreach ( (array) ( $m['sources'] ?? array() ) as $src ) {
				$h .= '<li class="description">' . self::e( (string) ( $src['what'] ?? '' ) ) . ': <a href="' . esc_url( (string) ( $src['url'] ?? '' ) ) . '" rel="noopener noreferrer" target="_blank">' . self::e( (string) ( $src['url'] ?? '' ) ) . '</a> (' . self::e( (string) ( $src['checked'] ?? '' ) ) . ')</li>';
			}
			$h .= '</ul></details>';
		}
		return $h . '</div>';
	}

	/** The whole screen as HTML (the suites read it). */
	public static function screen( int $uid ): string {
		$h = '<div class="wrap zagr-admin"><h1>' . self::e( __( 'Agreements', 'zorderz' ) ) . '</h1>'
			. '<div class="notice notice-warning zagr-not-advice"><p><strong>' . self::e( __( 'Not legal advice.', 'zorderz' ) ) . '</strong> '
			. self::e( __( 'The legal packs and the starter template are a starting point drawn from public law. They are not legal advice and may be out of date or incomplete for your business. Have your own lawyer review the packs that apply to you and write your agreement\'s terms before any customer signs. Agreements stay in test mode until an administrator turns them on below.', 'zorderz' ) ) . '</p></div>';
		if ( ! ZAGR_Install::ready() ) {
			return $h . '<div class="notice notice-error"><p>' . self::e( self::foreign_words() ) . '</p></div></div>';
		}
		$notice = get_transient( 'zagr_notice_' . $uid );
		if ( is_array( $notice ) ) {
			delete_transient( 'zagr_notice_' . $uid );
			$h .= '<div class="notice ' . ( $notice['ok'] ? 'notice-success' : 'notice-error' ) . '"><p>' . self::e( $notice['m'] ) . '</p></div>';
		}
		$mode = ZAGR_Settings::mode();
		// the per-address caps need each visitor's own address. Behind a proxy that isn't passed on, every visitor shares
		// one address and one budget; this administrator's own request shows it.
		$ip = ZAGR_People::client_ip();
		if ( '' !== $ip && ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			$h .= '<div class="notice notice-warning"><p>' . self::e( 'This site sees your address as ' . $ip . ', a private or reserved address. If customers\' addresses look the same, the limits on codes and sessions treat every visitor as one. Ask the host to pass the real address on, or set the zdz_client_ip filter (for every record) or zagr_client_ip (agreements only).' ) . '</p></div>';
		}

		// who gets agreements
		$opts = array(
			'off'  => __( 'Off: no agreements.', 'zorderz' ),
			'test' => __( 'Test: only estimates marked as tests (the default).', 'zorderz' ),
			'on'   => __( 'On: customers sign agreements.', 'zorderz' ),
		);
		$in   = '<h2>' . self::e( __( 'Who gets agreements', 'zorderz' ) ) . '</h2><fieldset>';
		foreach ( $opts as $k => $label ) {
			$in .= '<p><label><input type="radio" name="mode" value="' . esc_attr( $k ) . '"' . checked( $mode, $k, false ) . '> ' . self::e( $label ) . '</label></p>';
		}
		$in .= '</fieldset><h3>' . self::e( __( 'Before agreements can go to customers', 'zorderz' ) ) . '</h3>' . self::ready_list( ZAGR_Settings::checklist( 'on' ) )
			. '<p><button class="button button-primary">' . self::e( __( 'Save who gets agreements', 'zorderz' ) ) . '</button></p>';
		$h  .= self::post_form( 'mode', $in, 'zagr-card' );
		$h  .= self::packs_card();

		// facts
		$f   = ZAGR_Settings::stored_facts();
		$fb  = ZAGR_Settings::profile_fallbacks();
		$txt = function ( $name, $label, $value, $help = '', $inherit = '' ) {
			return '<p><label for="zagr-' . esc_attr( $name ) . '"><strong>' . esc_html( $label ) . '</strong></label><br><input class="regular-text" type="text" id="zagr-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '"' . ( '' !== $inherit ? ' placeholder="' . esc_attr( $inherit ) . '"' : '' ) . '>'
				. ( '' !== $help ? '<br><span class="description">' . esc_html( $help ) . '</span>' : '' ) . '</p>';
		};
		$sub = $f['subcontractors'];
		$in  = '<h2>' . self::e( __( 'Business facts on every agreement', 'zorderz' ) ) . '</h2><p class="description">' . self::e( __( 'Left blank, the legal name, address and phone come from the Business Profile (shown greyed). The email for cancellations and the license number are never filled in for you.', 'zorderz' ) ) . '</p>'
			. $txt( 'legal_name', __( 'Legal business name', 'zorderz' ), $f['legal_name'], __( 'Exactly as it should print on every agreement, notice, and email.', 'zorderz' ), (string) ( $fb['legal_name'] ?? '' ) )
			. '<p><label for="zagr-address"><strong>' . self::e( __( 'Business address', 'zorderz' ) ) . '</strong></label><br><textarea id="zagr-address" name="address_lines" rows="3" class="large-text" placeholder="' . esc_attr( implode( "\n", (array) ( $fb['address_lines'] ?? array() ) ) ) . '">' . esc_textarea( implode( "\n", $f['address_lines'] ) ) . '</textarea><br><span class="description">' . self::e( __( 'One line per row.', 'zorderz' ) ) . '</span></p>'
			. $txt( 'license', __( 'License number', 'zorderz' ), $f['license'], __( 'Where a legal pack asks for one (a state contractor\'s license, for one).', 'zorderz' ) )
			. $txt( 'cancel_email', __( 'Email address for cancellations', 'zorderz' ), $f['cancel_email'], __( 'Customers may cancel by writing here; staff are told here when they do.', 'zorderz' ) )
			. $txt( 'help_phone', __( 'Help phone (for the cancellation form and questions)', 'zorderz' ), $f['help_phone'], '', (string) ( $fb['help_phone'] ?? '' ) )
			. $txt( 'substantial_commencement', __( 'What counts as substantial commencement of the work', 'zorderz' ), $f['substantial_commencement'] )
			. '<h3>' . self::e( __( 'Liability insurance', 'zorderz' ) ) . '</h3>'
			. $txt( 'cgl_insurer', __( 'Insurance company', 'zorderz' ), $f['cgl_insurer'], __( 'For a template that prints an insurance statement. Until set it prints "[to come]".', 'zorderz' ) )
			. $txt( 'cgl_phone', __( 'Insurance company\'s phone', 'zorderz' ), $f['cgl_phone'] )
			. '<h3>' . self::e( __( 'Subcontractors', 'zorderz' ) ) . '</h3><p>' . self::e( __( 'Will a subcontractor be used?', 'zorderz' ) ) . ' '
			. '<label><input type="radio" name="subcontractors" value="yes"' . checked( $sub, 'yes', false ) . '> ' . self::e( __( 'Yes', 'zorderz' ) ) . '</label> '
			. '<label><input type="radio" name="subcontractors" value="no"' . checked( $sub, 'no', false ) . '> ' . self::e( __( 'No', 'zorderz' ) ) . '</label> '
			. '<label><input type="radio" name="subcontractors" value=""' . checked( $sub, '', false ) . '> ' . self::e( __( 'Not answered', 'zorderz' ) ) . '</label></p>'
			. '<h3>' . self::e( __( 'Test mode', 'zorderz' ) ) . '</h3>'
			. $txt( 'test_fixture', __( 'Name test pages and alerts use for a test customer', 'zorderz' ), (string) get_option( ZAGR_Settings::OPT_TEST, '' ), '', ZAGR_Settings::DEFAULT_TEST_FIXTURE )
			. '<h3>' . self::e( __( 'Cancellation period', 'zorderz' ) ) . '</h3><p>' . self::e( __( 'Set by law, from the legal packs that apply to the job and the answers they ask for (some ask the buyer whether anyone buying is 65 or older). It is never a setting here.', 'zorderz' ) ) . '</p>'
			. '<p><button class="button button-primary">' . self::e( __( 'Save the facts', 'zorderz' ) ) . '</button></p>';
		$h  .= self::post_form( 'facts', $in, 'zagr-card' );

		// lead times: the business's own groups of work
		$l   = array_values( ZAGR_Settings::lead() );
		$in  = '<h2>' . self::e( __( 'Lead times', 'zorderz' ) ) . '</h2><p class="description">' . self::e( __( 'Your own groups of work, as the estimate names them. For each: days after the cancellation period ends before the work can start (from and to), and the days the work takes (once, or per unit). The agreement\'s start date is a range from the slowest group on the estimate; its completion date is the range\'s end plus every group\'s days. No groups: the dates read "To be confirmed".', 'zorderz' ) ) . '</p>'
			. '<table class="widefat zagr-lead"><thead><tr><th>' . self::e( __( 'Id', 'zorderz' ) ) . '</th><th>' . self::e( __( 'Name', 'zorderz' ) ) . '</th><th>' . self::e( __( 'From (days)', 'zorderz' ) ) . '</th><th>' . self::e( __( 'To (days)', 'zorderz' ) ) . '</th><th>' . self::e( __( 'Work days', 'zorderz' ) ) . '</th><th>' . self::e( __( 'Per unit', 'zorderz' ) ) . '</th></tr></thead><tbody>';
		$rows = array_merge( $l, array( array( 'id' => '', 'label' => '', 'min' => '', 'max' => '', 'install' => '', 'per_unit' => false ), array( 'id' => '', 'label' => '', 'min' => '', 'max' => '', 'install' => '', 'per_unit' => false ) ) );
		foreach ( $rows as $i => $g ) {
			$n   = 'lead[' . $i . ']';
			$in .= '<tr><td><input type="text" class="small-text" name="' . esc_attr( $n . '[id]' ) . '" value="' . esc_attr( (string) $g['id'] ) . '" pattern="[a-z0-9][a-z0-9_-]{0,29}" aria-label="' . esc_attr__( 'Id', 'zorderz' ) . '"></td>'
				. '<td><input type="text" class="regular-text" name="' . esc_attr( $n . '[label]' ) . '" value="' . esc_attr( (string) $g['label'] ) . '" aria-label="' . esc_attr__( 'Name', 'zorderz' ) . '"></td>';
			foreach ( array( 'min', 'max', 'install' ) as $k ) {
				$in .= '<td><input type="number" min="0" max="365" class="small-text" name="' . esc_attr( $n . '[' . $k . ']' ) . '" value="' . esc_attr( (string) $g[ $k ] ) . '" aria-label="' . esc_attr( $k ) . '"></td>';
			}
			$in .= '<td><input type="checkbox" name="' . esc_attr( $n . '[per_unit]' ) . '" value="1"' . checked( ! empty( $g['per_unit'] ), true, false ) . ' aria-label="' . esc_attr__( 'Per unit', 'zorderz' ) . '"></td></tr>';
		}
		$in .= '</tbody></table><p class="description">' . self::e( __( 'Two empty rows are always offered; save to get more. Clear a row\'s id to remove it.', 'zorderz' ) ) . '</p><p><button class="button button-primary">' . self::e( __( 'Save the lead times', 'zorderz' ) ) . '</button></p>';
		$h  .= self::post_form( 'lead', $in, 'zagr-card' );

		// template: the version in use, then its approval as its own confirmed step
		$cur  = ZAGR_Settings::template_version();
		$in   = '<h2>' . self::e( __( 'The agreement template', 'zorderz' ) ) . '</h2><p><label for="zagr-version"><strong>' . self::e( __( 'Version in use', 'zorderz' ) ) . '</strong></label><br><select id="zagr-version" name="version">';
		foreach ( ZAGR_Template::versions() as $v ) {
			$in .= '<option value="' . esc_attr( $v ) . '"' . selected( $cur, $v, false ) . '>' . self::e( $v ) . '</option>';
		}
		$own = ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '' ) . '/zorderz-agreements/templates/';
		$in .= '</select></p><p><button class="button">' . self::e( __( 'Save the template choice', 'zorderz' ) ) . '</button></p><p class="description">'
			. self::e( sprintf( /* translators: %s: a folder path */ __( 'Choosing a version doesn\'t approve it. The bundled starter template is a skeleton with the legal packs\' notices: have your lawyer write your terms, then put your template in its own folder under %s (copy the starter\'s folder, rename it and its manifest\'s version).', 'zorderz' ), $own ) ) . '</p>';
		$h  .= self::post_form( 'template', $in, 'zagr-card' );
		$appr = ZAGR_Settings::approval( $cur );
		$ok   = ZAGR_Settings::template_approved();
		$in   = '<h2>' . self::e( __( 'The template\'s approval', 'zorderz' ) ) . '</h2>';
		try {
			$tpl = ZAGR_Template::load( $cur );
			$in .= '<p>' . self::e( 'Template ' . $cur . ' (' . ZAGR_Template::language_name( (string) $tpl['language'] ) . '), files fingerprint' ) . ' <code>' . self::e( $tpl['files_hash'] ) . '</code>.</p>';
			if ( ! empty( $tpl['starter'] ) ) {
				$h .= '<div class="zagr-card">' . $in . '<p class="zagr-bad">' . self::e( __( 'This is the starter template: a skeleton, never approved here. Have your lawyer write your terms in a template of your own.', 'zorderz' ) ) . '</p></div>';
			} elseif ( $ok ) {
				$in .= '<p class="zagr-ok">' . self::e( 'Approved by ' . (string) ( $appr['approver'] ?? '' ) . '; recorded by ' . ZAGR_People::name( (int) $appr['by'] ) . ' on ' . ZAGR_Clock::local_day( ZAGR_Clock::ts( (string) $appr['at'] ) ) . ', for these exact files.' ) . '</p>';
				$h  .= self::post_form( 'unapprove', $in . '<input type="hidden" name="version" value="' . esc_attr( $cur ) . '"><p><button class="button">' . self::e( __( 'Take the approval back', 'zorderz' ) ) . '</button></p>', 'zagr-card' );
			} else {
				if ( $appr ) {
					$in .= '<p class="zagr-bad">' . self::e( __( 'The template\'s files changed after the approval was recorded, so they are not approved. Record it again only after the new words are reviewed.', 'zorderz' ) ) . '</p>';
				}
				$in .= '<input type="hidden" name="version" value="' . esc_attr( $cur ) . '"><input type="hidden" name="files_hash" value="' . esc_attr( $tpl['files_hash'] ) . '">'
					. '<p><label for="zagr-approver"><strong>' . self::e( __( 'Approved by', 'zorderz' ) ) . '</strong></label><br><input type="text" class="regular-text" id="zagr-approver" name="approver" maxlength="120" required placeholder="' . esc_attr__( 'Your lawyer\'s name', 'zorderz' ) . '"></p>'
					. '<p><label><input type="checkbox" name="confirm" value="1"> ' . self::e( 'I confirm that the person named approved these exact words: template ' . $cur . ', files fingerprint ' . substr( $tpl['files_hash'], 0, 12 ) . '.' ) . '</label></p>'
					. '<p><button class="button button-primary">' . self::e( __( 'Record the approval', 'zorderz' ) ) . '</button></p>';
				$h  .= self::post_form( 'approve', $in, 'zagr-card' );
			}
		} catch ( \Throwable $e ) {
			$h .= '<div class="zagr-card">' . $in . '<p class="zagr-bad">' . self::e( __( 'This template doesn\'t load:', 'zorderz' ) . ' ' . $e->getMessage() ) . '</p></div>';
		}

		// the key
		$ks = ZAGR_Key::state();
		$in = '<h2>' . self::e( __( 'Key', 'zorderz' ) ) . '</h2><p><strong>' . self::e( ZAGR_Key::state_words() ) . '</strong></p>'
			. '<p class="description">' . self::e( __( 'The signer\'s typed names, email, IP address, and device are sealed under this key. Without it, no one can sign. Keep a copy in your password manager.', 'zorderz' ) ) . '</p>';
		if ( 'constant' !== $ks['source'] ) {
			$in .= '<div class="zagr-key" id="zagrKey">'
				. '<p><button type="button" class="button" id="zagrMake">' . self::e( __( 'Make a key', 'zorderz' ) ) . '</button> <button type="button" class="button" id="zagrEnter">' . self::e( __( 'Enter a key', 'zorderz' ) ) . '</button></p>'
				. '<div id="zagrMadeBox" hidden><p><strong>' . self::e( __( 'Copy this to your password manager now.', 'zorderz' ) ) . '</strong> ' . self::e( __( 'It is shown only this once and is not sent anywhere until you paste it back below.', 'zorderz' ) ) . '</p>'
				. '<textarea id="zagrMade" rows="2" class="large-text code" readonly autocomplete="off" spellcheck="false"></textarea>'
				. '<p><label for="zagrBack"><strong>' . self::e( __( 'Paste it back to confirm', 'zorderz' ) ) . '</strong></label><br><input type="password" id="zagrBack" class="large-text code" autocomplete="off" spellcheck="false"></p></div>'
				. '<div id="zagrEnterBox" hidden><p><label for="zagrPaste"><strong>' . self::e( __( 'Paste your saved key', 'zorderz' ) ) . '</strong></label><br><input type="password" id="zagrPaste" class="large-text code" autocomplete="off" spellcheck="false"></p></div>'
				. '<input type="hidden" name="zagr_key" id="zagrValue" value="">'
				. ( '' !== (string) ( ZAGR_Key::record()['check'] ?? '' ) && 0 === ZAGR_Store::count_all() ? '<p><label><input type="checkbox" name="replace" value="1"> ' . self::e( __( 'Replace the key (no agreements exist yet)', 'zorderz' ) ) . '</label></p>' : '' )
				. '<p><button class="button button-primary" id="zagrSave" disabled>' . self::e( __( 'Save the key', 'zorderz' ) ) . '</button></p></div>';
		}
		$h .= self::post_form( 'key', $in, 'zagr-card' );

		// the agreements
		$h .= '<div class="zagr-card"><h2>' . self::e( __( 'Latest agreements', 'zorderz' ) ) . '</h2><table class="widefat striped"><thead><tr><th>Estimate</th><th>Test</th><th>Rules</th><th>State</th><th>Signed</th><th>Cancel by</th><th>Record</th><th></th></tr></thead><tbody>';
		$rows = ZAGR_Store::recent( 50 );
		if ( ! $rows ) {
			$h .= '<tr><td colspan="8">' . self::e( __( 'No agreements yet.', 'zorderz' ) ) . '</td></tr>';
		}
		ZAGR_Paper::scope( true );   // the paper steps read once for the whole list (which photos are taken)
		try {
			foreach ( $rows as $r ) {
				$v   = ZAGR_Events::verify( (int) $r['id'] );
				$act = self::post_form( 'rotate', '<input type="hidden" name="agreement" value="' . (int) $r['id'] . '"><button class="button-link">Make a new link</button>' );
				if ( ZAGR_Codes::any_locked( (int) $r['id'] ) ) {
					$act .= self::post_form( 'codes', '<input type="hidden" name="agreement" value="' . (int) $r['id'] . '"><button class="button">Turn codes back on</button> <span class="description">Paused after too many wrong codes or code requests.</span>' );
				}
				if ( ! empty( $r['signed_at'] ) && empty( $r['copy_received_at'] ) && in_array( $r['state'], array( 'signed', 'change_pending' ), true ) ) {
					$act .= self::post_form( 'resend', '<input type="hidden" name="agreement" value="' . (int) $r['id'] . '"><button class="button">Email the signed copy again</button> <span class="description">The customer hasn\'t received it yet, so the cancellation period hasn\'t started.</span>' );
				}
				if ( in_array( $r['state'], array( 'signed', 'change_pending', 'window_closed' ), true ) ) {
					$act .= self::post_form( 'cancel', '<input type="hidden" name="agreement" value="' . (int) $r['id'] . '">'
						. '<label>Cancelled by <select name="how"><option value="email">email</option><option value="mail">letter</option></select></label> '
						. '<label>sent on <input type="date" name="sent_on" required></label> <button class="button">Record a cancellation</button>' );
				}
				$paper = self::paper_html( $r, $uid );
				// the estimate number staff know, not the billing system's internal id (kept in the cell's title)
				$num   = ZAGR_Alerts::number( $r );
				$doc   = ZAGR_Key::usable() ? ZAGR_Store::document( $r ) : null;
				$rules = null === $doc ? '' : implode( ' + ', ZAGR_Document::stack( $doc ) ) . ( '' !== (string) ( $doc['region'] ?? '' ) ? ' (' . $doc['region'] . ')' : '' );
				$h .= '<tr><td title="' . self::e( 'Billing id ' . (int) $r['estimate_id'] ) . '">' . ( '' !== $num ? self::e( '#' . $num ) : self::e( 'Billing id ' . (int) $r['estimate_id'] ) ) . '</td><td>' . ( $r['is_test'] ? 'Yes' : 'No' ) . '</td><td>' . self::e( $rules ) . '</td><td>' . self::e( str_replace( '_', ' ', (string) $r['state'] ) . ( ZAGR_Paper::is_paper( $r ) ? ' (on paper)' : '' ) ) . '</td>'
					. '<td>' . self::e( $r['signed_at'] ? ZAGR_Clock::local_day( ZAGR_Clock::ts( (string) $r['signed_at'] ) ) : '' ) . '</td><td>' . self::e( '' !== (string) $r['cancel_by'] ? (string) $r['cancel_by'] : ( $r['signed_at'] ? 'Not started (copy not received)' : '' ) ) . '</td>'
					. '<td>' . ( $v['ok'] ? self::e( $v['count'] . ' steps, intact' ) : ( ! empty( $v['unchecked'] ) ? self::e( $v['count'] . ' steps. ' . $v['reason'] ) : '<strong class="zagr-bad">' . self::e( $v['reason'] . ' (step ' . $v['bad_seq'] . ')' ) . '</strong>' ) ) . '</td><td>' . $act . '</td></tr>'
					. ( '' !== $paper ? '<tr class="zagr-paper-tr" id="zagr-a' . (int) $r['id'] . '"><td colspan="8">' . $paper . '</td></tr>' : '' );   // the alert's link lands here
			}
		} finally {
			ZAGR_Paper::scope( false );
		}
		return $h . '</tbody></table><p class="description">A paper signing counts at once: the cancellation period runs from the day the customer signed the paper form, and it is OK to order after that. One recorded once that period was over waits until an administrator confirms it here. Only administrators see the photos of the signature page here.</p><p class="description">Each record is a chain of keyed steps, and the site keeps a keyed note of its last step, so a changed, removed, or cut-off step shows here. Copies of each record\'s fingerprint are kept outside this site: in the signed copy the customer received, and in the cancellation email to the cancellations address.</p></div></div>';
	}
	/* ---------------------------------------------------------------- signed on paper */

	private static function when( string $utc ): string {
		return ZAGR_Clock::local_long( substr( $utc, 0, 19 ) );
	}

	/** One photo's thumbnail, through ZDZ_User_Media's gated links (never a share link), or a plain note when it is gone. */
	private static function photo_img( int $id, string $alt ): string {
		$m = ZAGR_Paper::store_ready() ? ZDZ_User_Media::get_by_id( $id ) : null;
		if ( ! is_array( $m ) ) {
			return '<span class="zagr-nophoto">' . self::e( 'Photo #' . $id . ' is gone' ) . '</span>';
		}
		$thumb = (string) ( $m['thumbnail_url'] ?? '' );
		$full  = (string) ( $m['file_url'] ?? '' );
		return '<a href="' . esc_url( $full ) . '" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer"><img src="' . esc_url( $thumb ) . '" alt="' . esc_attr( $alt ) . '" width="96" height="96" loading="lazy" referrerpolicy="no-referrer"></a>';
	}

	/** The row's paper signing: the form for an unsigned agreement, or the record of one signed on paper (with the undo). */
	public static function paper_html( array $r, int $uid ): string {
		$id   = (int) $r['id'];
		$hid  = '<input type="hidden" name="agreement" value="' . $id . '">';
		$p    = ZAGR_Paper::current( $r );
		if ( ! $p && ZAGR_Paper::is_paper( $r ) ) {   // signed on paper, its step not readable just now: it waits
			return '<div class="zagr-paper-done"><p><strong>' . self::e( 'Signed on paper on ' . ZAGR_Calendar::long_date( ZAGR_Paper::signed_on( $r ) ) . '.' ) . '</strong></p>'
				. ( 'cancelled' === (string) $r['state'] ? '' : '<div class="zagr-confirm"><p class="zagr-bad">' . self::e( ZAGR_Paper::UNREADABLE ) . '</p></div>' ) . '</div>';
		}
		if ( $p ) {
			$doc   = ZAGR_Store::document( $r );
			$days  = null === $doc ? 0 : ZAGR_Document::window_days( $doc, '' !== $p['older'] ? $p['older'] : 'na' );
			$late  = ZAGR_Paper::awaiting_confirm( $r );   // ordering waits for an administrator
			$conf  = $late ? null : ZAGR_Paper::confirmation( $r, $p );
			$h     = '<div class="zagr-paper-done"><p><strong>' . self::e( 'Signed on paper on ' . ZAGR_Calendar::long_date( ZAGR_Paper::signed_on( $r ) ) . '.' ) . '</strong> '
				. self::e( 'Recorded by ' . ( ZAGR_People::name( $p['by'] ) ?: 'staff' ) . ( 'admin' === $p['via'] ? ' in Settings' : ' in the app' ) . ' on ' . self::when( $p['at'] ) . '.' )
				. ( $days ? ' ' . self::e( 'Cancellation period: ' . $days . ' business days' . ( '' !== $p['older'] ? ' (' . ZAGR_Document::age_answer( $p['older'] ) . ')' : '' ) . ', to midnight on ' . ZAGR_Calendar::long_date( (string) $p['cancel_by'] ) . '.' ) : '' )
				. ( '' !== ZAGR_Paper::late_words( $p ) ? ' <strong class="zagr-late">' . self::e( ZAGR_Paper::late_words( $p ) ) . '</strong>' : '' ) . '</p>'
				. ( $conf ? '<p class="zagr-confirmed">' . self::e( 'Late paper signing confirmed by ' . ( ZAGR_People::name( $conf['by'] ) ?: 'an administrator' ) . ' on ' . self::when( $conf['at'] ) . ( '' !== $conf['note'] ? ': "' . $conf['note'] . '"' : '' ) . '.' ) . '</p>' : '' )
				. '<ul class="zagr-photos" aria-label="' . esc_attr( 'Photos of the signature page' ) . '">';
			foreach ( $p['photos'] as $i => $ph ) {
				$st  = ZAGR_Paper::photo_state( $ph );
				$say = array(
					'same'    => 'Photo unchanged since it was recorded',
					'changed' => 'Changed since it was recorded (a rotate in Media also counts as a change)',
					'missing' => 'Missing: deleted after it was recorded (its fingerprint stays in the record)',
				)[ $st ];
				$h  .= '<li class="zagr-photo is-' . $st . '">' . self::photo_img( (int) $ph['id'], 'Signature page, photo ' . ( $i + 1 ) ) . '<span class="zagr-pstate">' . self::e( $say ) . '</span></li>';
			}
			$h .= '</ul>';
			if ( $late && ! ZAGR_Paper::step_ok( $p ) ) {   // it waits, but what can't be read isn't confirmed
				$h .= '<div class="zagr-confirm"><p class="zagr-bad">' . self::e( ZAGR_Paper::UNREADABLE ) . '</p></div>';
			} elseif ( $late ) {   // its own deliberate step, whoever recorded it
				$h .= '<div class="zagr-confirm"><p><strong>' . self::e( 'Ordering waits until an administrator confirms this late paper signing.' ) . '</strong> ' . self::e( 'Look at the photo of the signature page and the paper date first.' ) . '</p>'
					. self::post_form( 'paper_confirm', $hid
						. '<p><label class="zagr-looked"><input type="checkbox" name="looked" value="1" required> <span>' . self::e( 'I looked at the photo of the signature page and the paper date (' . ZAGR_Calendar::long_date( (string) $p['date'] ) . ').' ) . '</span></label></p>'
						. '<p><label for="zagr-cn-' . $id . '">Note (optional)</label><br><input type="text" class="regular-text" id="zagr-cn-' . $id . '" name="note" maxlength="300"></p>'
						. '<p><button class="button button-primary">Confirm this late paper signing</button></p>', 'zagr-confirm-form' )
					. '</div>';
			}
			$open = ( in_array( (string) $r['state'], array( 'signed', 'change_pending' ), true ) && empty( $r['window_closed_at'] ) ) || $late;
			if ( $open && ZAGR_Store::changes( $id ) ) {   // a change order made after it (waiting, or signed): no undo here
				$h .= '<p class="description zagr-nochange">' . self::e( ZAGR_Paper::CHANGE_MADE ) . '</p>';
			} elseif ( $open && in_array( (string) $r['state'], $late ? array( 'signed', 'window_closed' ) : array( 'signed' ), true ) ) {
				$h .= '<details class="zagr-undo"><summary>Undo the paper signing</summary>'
					. self::post_form( 'paper_undo', $hid . '<p class="description">Only for a mistake, before it is OK to order. The agreement goes back to unsigned, the record keeps both steps, and a paper signing can be recorded again.</p>'
						. '<p><label for="zagr-why-' . $id . '"><strong>Why</strong></label><br><input type="text" class="regular-text" id="zagr-why-' . $id . '" name="reason" minlength="4" maxlength="300" required></p>'
						. '<p><button class="button">Undo the paper signing</button></p>' )
					. '</details>';
			}
			return $h . '</div>';
		}
		if ( ! in_array( (string) $r['state'], array( 'draft', 'ready', 'signing' ), true ) || ! empty( $r['signed_at'] ) ) {
			return '';
		}
		$h    = '';
		$undo = ZAGR_Paper::last_undo( $r );
		if ( $undo ) {
			$h .= '<p class="description zagr-undone">' . self::e( 'A paper signing was undone by ' . ( ZAGR_People::name( $undo['by'] ) ?: 'an administrator' ) . ' on ' . self::when( $undo['at'] ) . ( '' !== $undo['reason'] ? ': "' . $undo['reason'] . '"' : '' ) . '.' ) . '</p>';
		}
		$today = ZAGR_Clock::today();
		$first = ZAGR_Paper::first_day( $today, $r );
		$buyer = ZAGR_Paper::buyer_older( $r );   // the buyer's own "65 or older" stands
		$older = '';
		if ( ZAGR_Paper::asks_age( $r ) ) {
			$older = '<fieldset class="zagr-older"><legend><strong>Is any buyer 65 or older?</strong></legend>'
				. '<label><input type="radio" name="older" value="no" required> No</label> '
				. '<label><input type="radio" name="older" value="yes"' . ( $buyer ? ' checked' : '' ) . '> Yes (a longer time to cancel)</label>'
				. ( $buyer ? '<p class="description zagr-buyer-older">' . self::e( ZAGR_Paper::BUYER_OLDER ) . '</p>' : '' ) . '</fieldset>';
		}
		foreach ( ZAGR_Paper::open_questions( $r ) as $qid => $q ) {
			$older .= '<fieldset class="zagr-older"><legend><strong>' . self::e( (string) ( $q['label'] ?? $qid ) ) . '</strong></legend>'
				. '<label><input type="radio" name="q_' . esc_attr( $qid ) . '" value="no" required> ' . self::e( (string) ( $q['no'] ?? 'No' ) ) . '</label> '
				. '<label><input type="radio" name="q_' . esc_attr( $qid ) . '" value="yes"> ' . self::e( (string) ( $q['yes'] ?? 'Yes' ) ) . '</label>'
				. '<span class="description"> ' . self::e( (string) ( $q['citation'] ?? '' ) ) . '</span></fieldset>';
		}
		$in    = $hid . '<p class="description">For a customer who signed the paper agreement. It counts at once: the cancellation period runs from the day they signed, then it is OK to order. If that period is over by the time it is recorded, an administrator confirms it here first.</p>'
			. ( '' === ZAGR_Paper::language_of( $r )
				? '<fieldset class="zagr-language"><legend><strong>' . esc_html( sprintf( /* translators: %s: language name */ __( 'Was the sale negotiated in %s?', 'zorderz' ), ZAGR_Paper::language_name() ) ) . '</strong></legend>'
					. '<label><input type="radio" name="language_ok" value="yes" required> Yes</label> '
					. '<label><input type="radio" name="language_ok" value="no"> No</label>'
					. '<span class="description"> Only a yes makes an agreement.</span></fieldset>'
				: '' )
			. '<p><label for="zagr-pd-' . $id . '"><strong>The day the customer signed</strong></label><br><input type="date" id="zagr-pd-' . $id . '" name="paper_date" value="' . esc_attr( $today ) . '" min="' . esc_attr( $first ) . '" max="' . esc_attr( $today ) . '" required></p>'
			. $older
			. '<p><strong>A photo of the signature page</strong> (at least one)</p>'
			. '<p><label for="zagr-pf-' . $id . '">Upload from this computer</label><br><input type="file" id="zagr-pf-' . $id . '" name="paper_photo[]" accept="image/*" multiple></p>';
		$recent = ZAGR_Paper::recent_photos( $r, $uid );
		$in    .= '<fieldset class="zagr-recent"><legend>Or choose from recent photos (the last ' . ZAGR_Paper::RECENT_DAYS . ' days: yours, and those of whoever signed for the business)</legend>';
		if ( ! $recent ) {
			$in .= '<p class="description">No recent photos.</p>';
		}
		foreach ( $recent as $m ) {
			$who = ZAGR_People::name( (int) $m['user_id'] );
			$alt = 'Photo by ' . ( '' !== $who ? $who : 'staff' ) . ', ' . substr( (string) $m['created_at'], 0, 16 );
			$in .= '<label class="zagr-pick"><input type="checkbox" name="photos[]" value="' . (int) $m['id'] . '"><img src="' . esc_url( (string) $m['thumbnail_url'] ) . '" alt="' . esc_attr( $alt ) . '" width="96" height="96" loading="lazy" referrerpolicy="no-referrer"><span>' . self::e( ( '' !== $who ? $who . ', ' : '' ) . ZAGR_Calendar::short_date( substr( (string) $m['created_at'], 0, 10 ) ) ) . '</span></label>';
		}
		$in .= '</fieldset><p><button class="button button-primary">Record the paper signing</button></p>';
		return $h . '<details class="zagr-paper"><summary>Signed on paper</summary>' . self::post_form( 'paper', $in, 'zagr-paper-form', true ) . '</details>';
	}
}
