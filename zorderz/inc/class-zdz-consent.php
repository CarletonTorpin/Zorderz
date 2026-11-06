<?php
/**
 * ZDZ_Consent — one consent, for every app, for records of how the app is used.
 *
 *   ZDZ_Consent::has( 'usage_records' [, $uid] )         may usage records be kept about this person?
 *   ZDZ_Consent::may_record( 'usage_records' [, $uid] )  what a recorder asks before each per-person row:
 *                                                        has() once the card is switched on; until then
 *                                                        records are kept as before.
 *   filter zdz_consent_scopes                             apps say what they record: [ scope => one line ].
 *
 *   The record   user meta zdz_consent = { version, accepted_at }. "Not now" is kept apart
 *                (zdz_consent_seen = { version, at }) so the card is not shown again until the
 *                notice's version changes; Settings → Usage records lets a person agree or
 *                withdraw at any time.
 *   The card     shown once in the app's own sheet the next time the person opens the app,
 *                and again only when the notice's version changes. GET/POST /zorderz/v1/consent.
 *   Retention    the notice states how many days detailed records are kept ({raw_days}). That
 *                number is part of the version people agree to (version()): when a pack or an
 *                administrator changes it, everyone is asked again.
 *   Never asked  of a shared device or a device in Demo Mode: has() is always false there.
 *   Off until the business switches it on: Zorderz → Settings, "Show the usage-records
 *                card", default off. While off, has() is false for everyone, no card shows,
 *                and may_record() keeps the platform's existing records. An admin can
 *                preview the card with ?zdz-consent-preview=1 (nothing is saved).
 *
 * THE NOTICE IS THE BUSINESS'S. Core ships a neutral, factual default that describes
 * what the platform's own usage records hold. A business supplies its own wording in
 * its Business Profile (`notices.usage_records`: version, wording, summary, headings) or
 * with the `zdz_consent_*` filters, and should have it read by its own counsel before
 * switching the card on — employment and privacy law differ by place.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Consent {

	const META   = 'zdz_consent';
	const SEEN   = 'zdz_consent_seen';
	const OPT_ON = 'zdz_consent_card_on';

	/**
	 * Core's default notice version. A business's own notice carries its own version. 2: usage records carry no network
	 * address, record the kind of device and how long an app stays open, and are kept a set number of days before only
	 * daily totals remain.
	 */
	const VERSION = 2;

	/**
	 * Core's default notice. {business} is replaced with the business's name and {raw_days} with how many days detailed
	 * records are kept (ZDZ_Admin_Dashboard::raw_days()). Paragraphs are separated by a blank line. Every claim in it
	 * must stay true of the platform's own code: when what is recorded, or how long it is kept, changes, change this
	 * text and raise VERSION.
	 */
	const WORDING = "{business} keeps a record of how you use the app: which parts you open, when and for how long, the actions apps count (such as sending a document) and the kind of device you used (phone, desktop app or browser). These records are used to fix problems, improve the app and keep it secure. Signing in and out also records the device's network (IP) address, for security; usage records themselves never do.\n\nThese usage records never include what you type, say or upload, or the contents of your messages, chats, photos or files. A search is recorded only as having happened, never its words. They don't include your screen, your keystrokes or anything you do outside the app. Features that use your location ask your device for it themselves.\n\nOnly the app's administrators can see them, and you can see your own in Settings → Your app use. Detailed records are deleted after {raw_days} days; after that only daily totals are kept. Records of work you do in the apps are kept as before. You can change your answer any time in Settings → Usage records.";

	/** Core's default "In short" lines: each only restates the notice, never more. */
	const SUMMARY = array(
		'yes' => 'We record which parts of the app you open, when and for how long, and the kind of device, to fix problems, improve the app and keep it secure.',
		'no'  => 'These records never include what you type, say or upload, the words you search for, your messages, photos or files, or your screen.',
		'you' => 'Only administrators can see them, and you can see your own. Detailed records are deleted after {raw_days} days. You can change your answer any time.',
	);

	/** A heading over each paragraph of the notice, in order. */
	const HEADINGS = array( 'What we record, and why', 'What we never record', 'Who sees it, and how long we keep it' );

	public static function boot() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_action( 'zdz_core_settings_rows', array( __CLASS__, 'render_setting_row' ) );
	}

	/** The switch on Zorderz → Settings. */
	public static function register_setting() {
		register_setting( 'zdz_core_settings_group', self::OPT_ON, array(
			'type'              => 'string',
			'default'           => '0',
			'sanitize_callback' => function ( $v ) { return '1' === (string) $v ? '1' : '0'; },
		) );
	}

	public static function render_setting_row() {
		$on = self::enabled();
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( self::OPT_ON ); ?>"><?php esc_html_e( 'Show the usage-records card', 'zorderz' ); ?></label></th>
			<td>
				<input type="hidden" name="<?php echo esc_attr( self::OPT_ON ); ?>" value="0" />
				<label><input type="checkbox" id="<?php echo esc_attr( self::OPT_ON ); ?>" name="<?php echo esc_attr( self::OPT_ON ); ?>" value="1" <?php checked( $on ); ?> />
				<?php esc_html_e( 'Ask each person once whether usage records may be kept about them.', 'zorderz' ); ?></label>
				<p class="description">
					<?php esc_html_e( 'Off by default. Have your own notice read by your counsel before you switch this on; preview it first by opening the app with ?zdz-consent-preview=1.', 'zorderz' ); ?>
					<?php if ( ! self::is_custom() ) : ?>
						<br /><strong><?php esc_html_e( 'The notice is still the platform default. Set your own in the Business Profile (notices.usage_records).', 'zorderz' ); ?></strong>
					<?php endif; ?>
				</p>
			</td>
		</tr>
		<?php
	}

	public static function enabled() {
		return '1' === (string) get_option( self::OPT_ON, '0' );
	}

	/** The business's own notice from its profile, if it gave one. */
	private static function profile_notice(): array {
		if ( ! class_exists( 'ZDZ_Business_Profile' ) ) {
			return array();
		}
		$n = ZDZ_Business_Profile::get( 'notices.usage_records', array() );
		return is_array( $n ) ? $n : array();
	}

	/** True when the business supplied its own wording. */
	public static function is_custom(): bool {
		$n = self::profile_notice();
		return ! empty( $n['wording'] ) && is_string( $n['wording'] );
	}

	/**
	 * The version people agree to: the notice's own (the business's, or Core's), and, when the notice states how long
	 * detailed records are kept, that number too. Raising the notice's version asks everyone again, and so does changing
	 * the retention days (a pack's rules.yml usage_records.raw_days, or the zdz_audit_raw_days filter): an agreement
	 * to "deleted after 180 days" is not an agreement to another number. With the days Core's notice was written for
	 * (ZDZ_Admin_Dashboard::RAW_DAYS) the version is the notice's own, so nobody is asked again for nothing.
	 */
	public static function version(): int {
		$n = self::profile_notice();
		$v = ( self::is_custom() && ! empty( $n['version'] ) ) ? (int) $n['version'] : self::VERSION;
		$v = max( 1, (int) apply_filters( 'zdz_consent_version', $v ) );
		return self::with_retention( $v, self::states_retention() ? self::raw_days() : 0 );
	}

	/**
	 * A notice version with the retention days it states folded in: the version alone for the days Core's notice was
	 * written for (or a notice that states none), else version × 100000 + days. PURE.
	 */
	public static function with_retention( int $version, int $days ): int {
		$core = defined( 'ZDZ_Admin_Dashboard::RAW_DAYS' ) ? (int) ZDZ_Admin_Dashboard::RAW_DAYS : 180;
		if ( $days <= 0 || $days === $core ) {
			return $version;
		}
		return $version * 100000 + min( 99999, $days );
	}

	/** Does the notice people read state the retention days? Core's always does; a business's own when it uses {raw_days}. */
	private static function states_retention(): bool {
		if ( ! self::is_custom() ) {
			return true;
		}
		$n = self::profile_notice();
		foreach ( array_merge( array( (string) $n['wording'] ), is_array( $n['summary'] ?? null ) ? array_map( 'strval', $n['summary'] ) : array() ) as $t ) {
			if ( false !== strpos( $t, '{raw_days}' ) ) {
				return true;
			}
		}
		return false;
	}

	/** How many days detailed usage records are kept, for {raw_days}. */
	private static function raw_days(): int {
		return class_exists( 'ZDZ_Admin_Dashboard' ) && method_exists( 'ZDZ_Admin_Dashboard', 'raw_days' ) ? (int) ZDZ_Admin_Dashboard::raw_days() : 180;
	}

	/** Core's placeholders filled in: {business}, {raw_days}. */
	private static function fill( string $s ): string {
		return str_replace( array( '{business}', '{raw_days}' ), array( self::business_name(), (string) self::raw_days() ), $s );
	}

	private static function business_name(): string {
		$name = class_exists( 'ZDZ_Business_Profile' ) ? (string) ZDZ_Business_Profile::name() : '';
		return '' !== trim( $name ) ? $name : __( 'This app', 'zorderz' );
	}

	public static function wording() {
		$n = self::profile_notice();
		$w = self::fill( self::is_custom() ? (string) $n['wording'] : self::WORDING );
		return (string) apply_filters( 'zdz_consent_wording', $w );
	}

	/** The three lines at the top of the card: [ 'yes' => …, 'no' => …, 'you' => … ]. */
	public static function summary() {
		$n = self::profile_notice();
		$s = array_map( array( __CLASS__, 'fill' ), array_map( 'strval', ( self::is_custom() && ! empty( $n['summary'] ) && is_array( $n['summary'] ) ) ? $n['summary'] : self::SUMMARY ) );
		$s = apply_filters( 'zdz_consent_summary', $s );
		return is_array( $s ) ? $s : array();
	}

	/** One heading per paragraph (a notice with more paragraphs gets fewer headings, never wrong ones). */
	public static function headings() {
		$n = self::profile_notice();
		$h = ( self::is_custom() && ! empty( $n['headings'] ) && is_array( $n['headings'] ) ) ? $n['headings'] : self::HEADINGS;
		$h = apply_filters( 'zdz_consent_headings', $h );
		return is_array( $h ) ? array_values( $h ) : array();
	}

	/** What apps record under this consent: [ scope => a line a person reads ]. */
	public static function scopes() {
		$s = apply_filters( 'zdz_consent_scopes', array(
			'usage_records' => __( 'Records of how the app is used: features, times and how long they take. Never what you type, say or upload.', 'zorderz' ),
		) );
		return is_array( $s ) ? $s : array();
	}

	/** May this person be asked at all? Never a shared device or a device in Demo Mode. */
	public static function eligible( $uid ) {
		$uid = (int) $uid;
		if ( $uid <= 0 ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Kiosk_Demo' ) && method_exists( 'ZDZ_Kiosk_Demo', 'is_active' ) && ZDZ_Kiosk_Demo::is_active() ) {
			return false;
		}
		if ( class_exists( 'ZDZ_User_Roles' ) && ZDZ_User_Roles::is_shared_device( $uid ) ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Capabilities' ) && 'kiosk' === ZDZ_Capabilities::tier_of( $uid ) ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $uid ) ) {
			return false;
		}
		return true;
	}

	private static function record( $uid ) {
		$r = get_user_meta( (int) $uid, self::META, true );
		return is_array( $r ) ? $r : array();
	}

	/**
	 * May records under $scope be kept about $uid (default: the signed-in person)? False while the card is
	 * off, for a shared device always, for a scope no app registered, and until this person accepted the
	 * CURRENT notice.
	 */
	public static function has( $scope = 'usage_records', $uid = 0 ) {
		$uid = (int) ( $uid ?: get_current_user_id() );
		if ( ! self::enabled() || ! self::eligible( $uid ) || ! isset( self::scopes()[ (string) $scope ] ) ) {
			return false;
		}
		$r = self::record( $uid );
		return (int) ( $r['version'] ?? 0 ) === self::version() && ! empty( $r['accepted_at'] );
	}

	/**
	 * The gate every usage recorder calls before writing a per-person row:
	 *   - card off                       true: the records the platform already keeps carry on;
	 *   - a shared device / Demo Mode    true: not a person, never asked; its rows read "Shared device";
	 *   - card on                        has(): only people who agreed to the CURRENT notice.
	 * A signed-out request ($uid 0) is never recorded.
	 */
	public static function may_record( $scope = 'usage_records', $uid = 0 ) {
		$uid = (int) ( $uid ?: get_current_user_id() );
		if ( $uid <= 0 ) {
			return false;
		}
		if ( ! self::enabled() || ! self::eligible( $uid ) ) {
			return true;
		}
		return self::has( $scope, $uid );
	}

	/** Should the card show for $uid now? On, eligible, and no answer yet (agreed or "Not now") to this version. */
	public static function should_ask( $uid ) {
		if ( ! self::enabled() || ! self::eligible( $uid ) ) {
			return false;
		}
		$v = self::version();
		$r = self::record( $uid );
		if ( (int) ( $r['version'] ?? 0 ) === $v && ! empty( $r['accepted_at'] ) ) {
			return false;
		}
		$s = get_user_meta( (int) $uid, self::SEEN, true );
		return ! ( is_array( $s ) && (int) ( $s['version'] ?? 0 ) === $v );
	}

	/** 'accept' | 'later' | 'withdraw'. True when it was written. */
	public static function answer( $uid, $answer ) {
		$uid = (int) $uid;
		if ( ! self::enabled() || ! self::eligible( $uid ) ) {
			return false;
		}
		$v   = self::version();
		$now = gmdate( 'c' );
		if ( 'accept' === $answer ) {
			update_user_meta( $uid, self::SEEN, array( 'version' => $v, 'at' => $now ) );
			return (bool) update_user_meta( $uid, self::META, array( 'version' => $v, 'accepted_at' => $now ) ) || self::has( 'usage_records', $uid );
		}
		if ( 'later' === $answer ) {
			update_user_meta( $uid, self::SEEN, array( 'version' => $v, 'at' => $now ) );
			return true;
		}
		if ( 'withdraw' === $answer ) {
			delete_user_meta( $uid, self::META );
			update_user_meta( $uid, self::SEEN, array( 'version' => $v, 'at' => $now ) );
			return true;
		}
		return false;
	}

	/** What the page needs (zdzData.consent): nothing at all for a shared device, or while off (except an admin's preview). */
	public static function for_page( $uid ) {
		$uid     = (int) $uid;
		$preview = $uid > 0 && function_exists( 'user_can' ) && user_can( $uid, 'manage_options' ) && self::eligible( $uid );
		if ( ! self::eligible( $uid ) || ( ! self::enabled() && ! $preview ) ) {
			return array( 'on' => false );
		}
		$v = self::version();
		$r = self::record( $uid );
		return array(
			'on'       => self::enabled(),
			'ask'      => self::should_ask( $uid ),
			'preview'  => $preview,
			'version'  => $v,
			'draft'    => ! self::is_custom(),
			'wording'  => self::wording(),
			'summary'  => self::summary(),
			'headings' => self::headings(),
			'agreed'   => ( (int) ( $r['version'] ?? 0 ) === $v && ! empty( $r['accepted_at'] ) ) ? substr( (string) $r['accepted_at'], 0, 10 ) : '',
		);
	}

	public static function routes() {
		register_rest_route( ZDZ_REST_NS, '/consent', array(
			array(
				'methods'             => 'GET',
				'permission_callback' => function () { return is_user_logged_in(); },
				'callback'            => function () { return rest_ensure_response( self::for_page( get_current_user_id() ) ); },
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => function () { return is_user_logged_in(); },
				'callback'            => array( __CLASS__, 'rest_answer' ),
			),
		) );
	}

	public static function rest_answer( $req ) {
		$uid = (int) get_current_user_id();
		if ( ! self::eligible( $uid ) ) {
			return new WP_Error( 'zdz_consent_kiosk', __( 'A shared device is never asked.', 'zorderz' ), array( 'status' => 403 ) );
		}
		if ( ! self::enabled() ) {
			return new WP_Error( 'zdz_consent_off', __( 'This notice is not switched on yet. Nothing was saved.', 'zorderz' ), array( 'status' => 409 ) );
		}
		$a = (string) $req->get_param( 'answer' );
		if ( ! in_array( $a, array( 'accept', 'later', 'withdraw' ), true ) ) {
			return new WP_Error( 'zdz_consent_answer', __( 'Unknown answer.', 'zorderz' ), array( 'status' => 400 ) );
		}
		if ( ! self::answer( $uid, $a ) ) {
			return new WP_Error( 'zdz_consent_save', __( 'That was not saved. Try again.', 'zorderz' ), array( 'status' => 500 ) );
		}
		return rest_ensure_response( self::for_page( $uid ) );
	}
}
