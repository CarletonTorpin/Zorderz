<?php
/**
 * ZDZ_Staging_Guard — a copy of the live site never reaches the business's customers.
 *
 * A staging or test copy carries the live site's database: its customers, its mail
 * settings, its provider sign-ins. Left alone, the copy's cron sends the same reminder
 * e-mails and surveys, its apps write to the same CRM and billing system, and a token
 * refresh on the copy spends the live site's single-use refresh token, logging the live
 * site out of its billing system.
 *
 * On a copy this guard stops:
 *   - e-mail (wp_mail refused before anything is sent; PHPMailer left with no recipients);
 *   - every outbound request to a provider's sign-in or write host (billing sign-in and
 *     token refresh, CRM, Microsoft and Google sign-in, Stripe, SMS, web push), and every
 *     other non-GET request except to the Ai gateways, whose reads must POST;
 *   - every background job (WP-Cron and Action Scheduler's async runner), except jobs an
 *     app declares safe on a copy (zdz_staging_guard_cron_allow).
 * Reads (GET) still work, so the copy can be looked at and tested.
 *
 * WHEN IT IS ON — fail-safe: a copy must never write to real customers.
 *   1. ZDZ_STAGING_GUARD in wp-config.php: 'on' forces it on; 'off' forces it off (the
 *      only way to switch it off on a non-live site, and an explicit, logged decision).
 *   2. The site's host is listed (ZDZ_STAGING_HOSTS, comma-separated, or the
 *      zdz_staging_guard_hosts filter), or the host environment's name is (PWP_NAME on
 *      hosts that define it; ZDZ_STAGING_ENVS or zdz_staging_guard_envs).
 *   3. WordPress says this is not production (wp_get_environment_type(): staging,
 *      development or local; managed hosts set this on their staging copies).
 *   4. The site no longer matches the LIVE-SITE PIN: on the first administrator request
 *      on a production-type site, the guard records a fingerprint of where it is running
 *      (home host, site host, database name, host environment name). A copy carries that
 *      pin in its database, but runs on another host or database, so it differs — and the
 *      guard switches on from the copy's first request, including the moments mid-copy
 *      before the host has replaced the live domain.
 * Otherwise (the pinned live site) it is inert.
 *
 * Moving the live site to a new host or domain also differs from the pin: the guard then
 * holds mail and writes until an administrator presses "This is the live site" (typing the
 * site's host to confirm), which records the new pin. Failing towards "quiet" is the point.
 *
 * Nothing here is business data: hosts and environment names are each site's own
 * configuration, never compiled in.
 *
 * Check it on the live site: wp eval 'var_dump( ZDZ_Staging_Guard::status() );' must show
 * active => false, and live mail must still arrive.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ZDZ_Staging_Guard {

	const PIN    = 'zdz_staging_pin';   // the live site's fingerprint: [ fp, host, at, by ]
	const ACTION = 'zdz_staging_pin';
	const LOG    = '[zdz-staging-guard] ';

	/** Hosts that never get a request from a copy, whatever the method. */
	const BLOCK_ALL = array(
		'auth.freshbooks.com',          // billing sign-in: refresh tokens are single-use
		'api.nutshell.com', 'app.nutshell.com',
		'login.microsoftonline.com',    // Microsoft sign-in and token refresh
		'oauth2.googleapis.com',        // Google token refresh
		'api.stripe.com',
		'api.twilio.com',
		'fcm.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com', 'api.push.apple.com',
	);

	/** Host suffixes that never get a request (push services with per-region hosts). */
	const BLOCK_ALL_SUFFIX = array( '.notify.windows.com', '.push.apple.com' );

	/** Hosts allowed to receive non-GET requests: the Ai gateways, whose reads must POST. */
	const ALLOW_WRITE = array( 'api.poe.com', 'api.anthropic.com' );

	/** Paths that are always blocked, even on otherwise-readable hosts (token endpoints). */
	const BLOCK_PATHS = array( '#/auth/oauth/token#i', '#/oauth2?/v[0-9.]+/token#i', '#/oauth/token#i' );

	/** @var array{active:bool,reason:string}|null this request's decision */
	private static $status = null;

	/* =====================================================================
	 * THE DECISION (pure)
	 * ===================================================================== */

	/** Lower-cased, trimmed, non-empty items of a comma-separated setting or a list. */
	public static function items( $v ): array {
		$list = is_array( $v ) ? $v : explode( ',', (string) $v );
		$out  = array();
		foreach ( $list as $i ) {
			$i = strtolower( trim( (string) $i ) );
			if ( '' !== $i ) {
				$out[] = $i;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Where this request runs. */
	public static function environment(): array {
		$type = function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : 'production';
		return array(
			'host'      => strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
			'site_host' => strtolower( (string) wp_parse_url( site_url(), PHP_URL_HOST ) ),
			'db'        => defined( 'DB_NAME' ) ? (string) DB_NAME : '',
			'env'       => defined( 'PWP_NAME' ) ? strtolower( trim( (string) PWP_NAME ) ) : '',
			'type'      => strtolower( $type ),
		);
	}

	/** The configuration an operator set: [ force => ''|'on'|'off', hosts => [], envs => [] ]. */
	public static function config(): array {
		$force = defined( 'ZDZ_STAGING_GUARD' ) ? strtolower( trim( (string) ZDZ_STAGING_GUARD ) ) : '';
		return array(
			'force' => in_array( $force, array( 'on', 'off' ), true ) ? $force : '',
			'hosts' => self::items( apply_filters( 'zdz_staging_guard_hosts', self::items( defined( 'ZDZ_STAGING_HOSTS' ) ? ZDZ_STAGING_HOSTS : '' ) ) ),
			'envs'  => self::items( apply_filters( 'zdz_staging_guard_envs', self::items( defined( 'ZDZ_STAGING_ENVS' ) ? ZDZ_STAGING_ENVS : '' ) ) ),
		);
	}

	/** A fingerprint of where the site runs (16 hex; no secret in it, none derivable). */
	public static function fingerprint( array $env ): string {
		return substr( hash( 'sha256', 'zdz-live-pin-v1|' . ( $env['host'] ?? '' ) . '|' . ( $env['site_host'] ?? '' ) . '|' . ( $env['db'] ?? '' ) . '|' . ( $env['env'] ?? '' ) ), 0, 16 );
	}

	/**
	 * Is the guard on, and why?
	 *
	 * @param array      $env    environment()
	 * @param array|null $pin    the stored live-site pin, or null
	 * @param array      $config config()
	 * @return array{active:bool,reason:string} reason: forced_off|forced_on|listed|environment_type|copy|live|unpinned
	 */
	public static function decide( array $env, $pin, array $config ): array {
		if ( 'off' === ( $config['force'] ?? '' ) ) {
			return array( 'active' => false, 'reason' => 'forced_off' );
		}
		if ( 'on' === ( $config['force'] ?? '' ) ) {
			return array( 'active' => true, 'reason' => 'forced_on' );
		}
		$host = (string) ( $env['host'] ?? '' );
		$name = (string) ( $env['env'] ?? '' );
		if ( ( '' !== $host && in_array( $host, (array) ( $config['hosts'] ?? array() ), true ) )
			|| ( '' !== $name && in_array( $name, (array) ( $config['envs'] ?? array() ), true ) ) ) {
			return array( 'active' => true, 'reason' => 'listed' );
		}
		if ( in_array( (string) ( $env['type'] ?? 'production' ), array( 'staging', 'development', 'local' ), true ) ) {
			return array( 'active' => true, 'reason' => 'environment_type' );
		}
		if ( is_array( $pin ) && ! empty( $pin['fp'] ) ) {
			return hash_equals( (string) $pin['fp'], self::fingerprint( $env ) )
				? array( 'active' => false, 'reason' => 'live' )
				: array( 'active' => true, 'reason' => 'copy' );
		}
		return array( 'active' => false, 'reason' => 'unpinned' );
	}

	/**
	 * May an outbound request leave a copy? 'allow' | 'block'.
	 *
	 * @param string $url       the request URL
	 * @param string $method    HTTP method
	 * @param string $self      this site's own host
	 * @param bool   $self_ok   may loopbacks to $self write (true when $self is not the live host)
	 * @param array  $lists     [ block_all, block_suffix, allow_write, block_paths ] (defaults: the constants)
	 */
	public static function decide_http( string $url, string $method, string $self, bool $self_ok, array $lists = array() ): string {
		$block_all = $lists['block_all'] ?? self::BLOCK_ALL;
		$suffixes  = $lists['block_suffix'] ?? self::BLOCK_ALL_SUFFIX;
		$allow_w   = $lists['allow_write'] ?? self::ALLOW_WRITE;
		$paths     = $lists['block_paths'] ?? self::BLOCK_PATHS;

		$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$path   = (string) wp_parse_url( $url, PHP_URL_PATH );
		$method = strtoupper( '' !== $method ? $method : 'GET' );
		if ( false !== stripos( $url, 'as_async_request_queue_runner' ) ) {
			return 'block'; // Action Scheduler's async runner
		}
		if ( '' === $host || ( $host === strtolower( $self ) && $self_ok ) ) {
			return 'allow'; // loopbacks on the copy's own host (cron spawn, the apps' own relays)
		}
		// Mid-copy, before the host replaces the live domain, home_url() still names the
		// live site: then the "own host" is the live site and gets no writes either.
		if ( in_array( $host, $block_all, true ) ) {
			return 'block';
		}
		foreach ( $suffixes as $suffix ) {
			if ( '' !== $suffix && substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return 'block';
			}
		}
		foreach ( $paths as $re ) {
			if ( preg_match( $re, $path ) ) {
				return 'block';
			}
		}
		if ( in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) {
			return 'allow';
		}
		if ( in_array( $host, $allow_w, true ) ) {
			return 'allow';
		}
		return 'block'; // any other write leaves nothing on a copy
	}

	/** Which due cron hooks may run on a copy: [ timestamp => [ hook => events ] ] filtered to $allow. */
	public static function filter_jobs( array $crons, array $allow, float $now ): array {
		$out = array();
		foreach ( $crons as $timestamp => $hooks ) {
			if ( $timestamp > $now ) {
				break;
			}
			foreach ( (array) $hooks as $hook => $events ) {
				if ( in_array( $hook, $allow, true ) ) {
					$out[ $timestamp ][ $hook ] = $events;
				}
			}
		}
		return $out;
	}

	/* =====================================================================
	 * BOOT
	 * ===================================================================== */

	public static function pin(): ?array {
		$p = get_option( self::PIN, null );
		return is_array( $p ) ? $p : null;
	}

	/** This request's decision. */
	public static function status(): array {
		if ( null === self::$status ) {
			self::$status = self::decide( self::environment(), self::pin(), self::config() );
		}
		return self::$status;
	}

	public static function is_active(): bool {
		return ! empty( self::status()['active'] );
	}

	public static function boot(): void {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_pin' ) );
		$st = self::status();
		if ( ! $st['active'] ) {
			if ( 'unpinned' === $st['reason'] ) {
				add_action( 'admin_init', array( __CLASS__, 'maybe_pin' ) );
			}
			if ( 'forced_off' === $st['reason'] ) {
				add_action( 'admin_notices', array( __CLASS__, 'forced_off_notice' ) );
			}
			return;
		}
		add_filter( 'pre_wp_mail', array( __CLASS__, 'block_mail' ), PHP_INT_MAX, 2 );
		add_action( 'phpmailer_init', array( __CLASS__, 'empty_mailer' ), PHP_INT_MAX );
		add_filter( 'pre_http_request', array( __CLASS__, 'filter_http' ), PHP_INT_MAX, 3 );
		add_filter( 'pre_get_ready_cron_jobs', array( __CLASS__, 'ready_jobs' ), PHP_INT_MAX );
		// Action Scheduler also runs jobs through an async loopback request, not only
		// through WP-Cron: switch that runner off too.
		add_filter( 'action_scheduler_allow_async_request_runner', '__return_false', PHP_INT_MAX );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 1 );
		add_action( 'admin_notices', array( __CLASS__, 'active_notice' ) );
	}

	/** First administrator request on an unpinned production-type site: record where it runs. */
	public static function maybe_pin(): void {
		if ( null !== self::pin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::record_pin( (int) get_current_user_id() );
		self::log( 'recorded this site as the live site (' . self::environment()['host'] . ')' );
	}

	private static function record_pin( int $by ): void {
		$env = self::environment();
		update_option( self::PIN, array( 'fp' => self::fingerprint( $env ), 'host' => $env['host'], 'at' => time(), 'by' => $by ), false );
		self::$status = null;
	}

	/** "This is the live site": an administrator re-pins after moving the live site. */
	public static function handle_pin(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'zorderz' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$typed = strtolower( trim( sanitize_text_field( (string) wp_unslash( $_POST['confirm_host'] ?? '' ) ) ) );
		$env   = self::environment();
		$st    = self::status();
		$back  = wp_get_referer() ? wp_get_referer() : admin_url();
		if ( 'copy' !== $st['reason'] || '' === $env['host'] || ! hash_equals( $env['host'], $typed ) ) {
			self::log( 'live-site pin NOT changed (reason ' . $st['reason'] . '; typed host did not match)' );
			wp_safe_redirect( add_query_arg( 'zdz_staging_pin', '0', $back ) );
			exit;
		}
		self::record_pin( (int) get_current_user_id() );
		self::log( 'live-site pin moved to ' . $env['host'] . ' by user ' . (int) get_current_user_id() );
		wp_safe_redirect( add_query_arg( 'zdz_staging_pin', '1', $back ) );
		exit;
	}

	private static function log( string $msg ): void {
		error_log( self::LOG . $msg );
	}

	/* =====================================================================
	 * WHAT IT BLOCKS
	 * ===================================================================== */

	/** wp_mail(): refuse before anything is sent. */
	public static function block_mail( $return, $atts ) {
		$to = isset( $atts['to'] ) ? ( is_array( $atts['to'] ) ? count( $atts['to'] ) : count( array_filter( explode( ',', (string) $atts['to'] ) ) ) ) : 0;
		self::log( 'blocked an e-mail to ' . (int) $to . ' recipient(s)' );
		return false;
	}

	/** Second belt: if something bypasses pre_wp_mail, leave PHPMailer with nobody to send to. */
	public static function empty_mailer( $phpmailer ): void {
		if ( is_object( $phpmailer ) && method_exists( $phpmailer, 'clearAllRecipients' ) ) {
			$phpmailer->clearAllRecipients();
			self::log( 'cleared PHPMailer recipients' );
		}
	}

	/** The lists in force (each filterable: an app adds the provider hosts it talks to). */
	public static function lists(): array {
		return array(
			'block_all'    => self::items( apply_filters( 'zdz_staging_guard_block_hosts', self::BLOCK_ALL ) ),
			'block_suffix' => self::items( apply_filters( 'zdz_staging_guard_block_suffixes', self::BLOCK_ALL_SUFFIX ) ),
			'allow_write'  => self::items( apply_filters( 'zdz_staging_guard_allow_write', self::ALLOW_WRITE ) ),
			'block_paths'  => self::BLOCK_PATHS,
		);
	}

	public static function filter_http( $pre, $args, $url ) {
		if ( false !== $pre ) {
			return $pre;
		}
		$url    = (string) $url;
		$method = isset( $args['method'] ) ? (string) $args['method'] : 'GET';
		$body   = isset( $args['body'] ) && is_array( $args['body'] ) ? $args['body'] : array();
		if ( isset( $body['action'] ) && 'as_async_request_queue_runner' === $body['action'] ) {
			$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . 'action=as_async_request_queue_runner';
		}
		$self    = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$pin     = self::pin();
		$self_ok = '' !== $self && ! ( is_array( $pin ) && isset( $pin['host'] ) && $self === (string) $pin['host'] );
		if ( 'allow' === self::decide_http( $url, $method, $self, $self_ok, self::lists() ) ) {
			return $pre;
		}
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		self::log( 'blocked ' . strtoupper( $method ) . ' ' . $host . (string) wp_parse_url( $url, PHP_URL_PATH ) );
		/* translators: 1: HTTP method, 2: host */
		return new WP_Error( 'zdz_staging_guard', sprintf( __( 'Blocked on a copy of the live site by the staging guard: %1$s %2$s', 'zorderz' ), strtoupper( $method ), $host ) );
	}

	/** Only jobs an app declares safe on a copy run; everything else stays queued and untouched. */
	public static function ready_jobs( $pre ) {
		if ( null !== $pre ) {
			return $pre;
		}
		$crons = function_exists( '_get_cron_array' ) ? (array) _get_cron_array() : array();
		$allow = self::items( apply_filters( 'zdz_staging_guard_cron_allow', array() ) );
		$out   = self::filter_jobs( $crons, $allow, microtime( true ) );
		$held  = 0;
		foreach ( $crons as $timestamp => $hooks ) {
			if ( $timestamp > microtime( true ) ) {
				break;
			}
			$held += count( array_diff( array_keys( (array) $hooks ), $allow ) );
		}
		if ( $held ) {
			self::log( 'held ' . $held . ' due background job(s)' );
		}
		return $out;
	}

	/* =====================================================================
	 * WHAT ADMINISTRATORS SEE
	 * ===================================================================== */

	public static function admin_bar( $bar ): void {
		if ( ! is_object( $bar ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$bar->add_node( array(
			'id'    => 'zdz-staging-guard',
			'title' => esc_html__( 'STAGING GUARD ON', 'zorderz' ),
			'meta'  => array( 'title' => __( 'E-mail, outbound writes and background jobs are blocked on this copy.', 'zorderz' ) ),
		) );
	}

	private static function reason_text( string $reason ): string {
		switch ( $reason ) {
			case 'forced_on':
				return __( 'ZDZ_STAGING_GUARD is set to on in wp-config.php.', 'zorderz' );
			case 'listed':
				return __( 'this host or environment is listed as a staging copy.', 'zorderz' );
			case 'environment_type':
				return __( 'WordPress says this environment is not production.', 'zorderz' );
			default:
				return __( 'this site no longer runs where the live site was recorded (another host or database): it looks like a copy.', 'zorderz' );
		}
	}

	public static function active_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$st = self::status();
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'The staging guard is on', 'zorderz' ) . '</strong>: ' . esc_html( self::reason_text( $st['reason'] ) ) . ' '
			. esc_html__( 'E-mail, outbound writes (billing, CRM, Microsoft, Google, Stripe, SMS, push) and background jobs are blocked. Each blocked attempt is written to the PHP error log with the prefix [zdz-staging-guard].', 'zorderz' ) . '</p>';
		if ( 'copy' === $st['reason'] ) {
			$pin = self::pin();
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><p>';
			wp_nonce_field( self::ACTION );
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
			/* translators: %s: the recorded live host */
			echo esc_html( sprintf( __( 'The live site was recorded at %s. Only if THIS site is now the live site (it was moved), type its host to confirm:', 'zorderz' ), (string) ( $pin['host'] ?? '' ) ) ) . ' ';
			echo '<input type="text" name="confirm_host" value="" class="regular-text" autocomplete="off" placeholder="' . esc_attr( self::environment()['host'] ) . '"> ';
			echo '<button class="button">' . esc_html__( 'This is the live site', 'zorderz' ) . '</button></p></form>';
		}
		echo '</div>';
	}

	public static function forced_off_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$env = self::environment();
		$pin = self::pin();
		if ( is_array( $pin ) && ! empty( $pin['fp'] ) && hash_equals( (string) $pin['fp'], self::fingerprint( $env ) ) ) {
			return; // switched off on the live site itself: nothing to warn about
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'The staging guard is switched off', 'zorderz' ) . '</strong> '
			. esc_html__( '(ZDZ_STAGING_GUARD is off in wp-config.php) on a site that is not the recorded live site. E-mail, provider writes and background jobs here reach real customers.', 'zorderz' ) . '</p></div>';
	}
}
