<?php
/**
 * ZSM_Report — the wp-admin dashboard widget and the digest.
 *
 * The digest is delivered per recipient, in this order:
 *   1) ZDZ_Alert_Router::send() — the in-app bell and e-mail, per the router's channel rules.
 *      This is what NOTIFIES.
 *   2) A copy in Messages as a note to self, when the messaging app is installed. A record, not
 *      a notification: a note to self arrives already read and sends no push.
 *   3) Plain e-mail, only when nothing above notified the person.
 * Only a person actually notified counts as delivered, so a digest that reached nobody is
 * tried again on the next run.
 *
 * A digest is sent only when something changed: the set of open critical/high findings, or
 * the feed alarm going up or down (ZSM_Policy::digest_signature / ::digest_due). The output
 * always states its scope and the feed's freshness and never invents a severity.
 *
 * @package Zorderz\Security_Monitor
 */

defined( 'ABSPATH' ) || exit;

class ZSM_Report {

	public static function init(): void {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'add_widget' ) );
		add_action( 'zdz_register_alert_types', array( __CLASS__, 'register_alert_type' ) );
	}

	/** Declare the digest's alert type, so the router resolves its channels. */
	public static function register_alert_type( $router ): void {
		if ( is_object( $router ) && method_exists( $router, 'register_type' ) ) {
			$router->register_type(
				ZSM::ALERT_TYPE,
				array(
					'label'            => __( 'Security digest', 'zorderz' ),
					'description'      => __( 'New or changed critical or high security findings, or the vulnerability feed going stale.', 'zorderz' ),
					'source_plugin'    => ZSM::APP_ID,
					'default_channels' => array( 'in_app', 'email' ),
					'category'         => 'system',
				)
			);
		}
	}

	public static function add_widget(): void {
		if ( ! ZSM::can_manage() ) {
			return;
		}
		wp_add_dashboard_widget( 'zsm_widget', __( 'Security Monitor', 'zorderz' ), array( __CLASS__, 'render_widget' ) );
	}

	public static function console_url(): string {
		return admin_url( 'admin.php?page=' . ZSM::PAGE );
	}

	public static function render_widget(): void {
		if ( ! ZSM::can_manage() ) {
			return;
		}
		$c = ZSM_Findings::counts();

		echo '<p style="font-size:15px;margin:.2em 0 .6em">';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an escaped format, integers and fixed markup
		printf(
			/* translators: 1: critical count, 2: high count, 3: medium count, 4: low count */
			esc_html__( '%1$s critical · %2$s high · %3$s medium · %4$s low', 'zorderz' ),
			'<strong' . ( $c['critical'] > 0 ? ' style="color:#b32d2e"' : '' ) . '>' . (int) $c['critical'] . '</strong>',
			'<strong>' . (int) $c['high'] . '</strong>',
			(int) $c['medium'],
			(int) $c['low']
		);
		echo '</p>';

		$top = array_slice( ZSM_Findings::list( 'active' ), 0, 5 );
		if ( empty( $top ) ) {
			$msg = 'not_connected' === ZSM_Feed::state() || '' === ZSM_Feed::last_sync()
				? __( 'No open findings. The vulnerability feed has not been matched, so only the local checks ran.', 'zorderz' )
				: __( 'No open findings in scope.', 'zorderz' );
			echo '<p>' . esc_html( $msg ) . '</p>';
		} else {
			echo '<ul style="margin:0 0 .6em;padding:0;list-style:none">';
			foreach ( $top as $f ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every argument is escaped
				printf(
					'<li style="margin:.15em 0">%s <strong>%s</strong> — %s%s</li>',
					esc_html( strtoupper( (string) $f['severity'] ) ),
					esc_html( (string) ( $f['name'] ? $f['name'] : $f['slug'] ) ),
					esc_html( self::trim_words( (string) $f['title'], 14 ) ),
					$f['cve'] ? ' (' . esc_html( (string) $f['cve'] ) . ')' : ''
				);
			}
			echo '</ul>';
		}

		echo '<p style="font-size:11px;color:#666;margin:.4em 0 0">';
		echo esc_html( self::scope_line() ) . ' ';
		$fresh = ZSM_Feed::freshness_line();
		echo ZSM_Feed::alarm() ? '<span style="color:#b32d2e">' . esc_html( $fresh ) . '</span>' : esc_html( $fresh ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in both branches
		if ( ! ZSM::enabled() ) {
			echo ' <em>' . esc_html__( 'The monitor is not armed.', 'zorderz' ) . '</em>';
		}
		echo ' <a href="' . esc_url( self::console_url() ) . '">' . esc_html__( 'Open the console', 'zorderz' ) . ' &rarr;</a></p>';
	}

	/** What the monitor covers, in one sentence. */
	public static function scope_line(): string {
		return ZSM::has_feed_key()
			? __( 'Scope: third-party plugins, themes and WordPress core against the vulnerability feed; your own code by the daily code check.', 'zorderz' )
			: __( 'Scope: installed versions, available updates and version drift; your own code by the daily code check. The vulnerability feed is not connected.', 'zorderz' );
	}

	/**
	 * Trim to N words WITHOUT stripping tags. Feed titles read "Example Plugin <= 1.2 - …", and a
	 * tag stripper takes "<= 1.2 - …" for a tag and cuts the title down to the plugin name.
	 */
	public static function trim_words( string $text, int $n ): string {
		$words = preg_split( '/\s+/u', trim( $text ) );
		if ( ! is_array( $words ) || count( $words ) <= $n ) {
			return trim( $text );
		}
		return implode( ' ', array_slice( $words, 0, $n ) ) . '…';
	}

	/**
	 * Send the digest to every recipient whose last one said something different. $force sends it
	 * whatever changed ("Send digest now", `wp zsm digest`). Returns how many people were notified.
	 * The digest reads the findings store, so $result (the scan just run) is informational only.
	 */
	public static function maybe_send_digest( array $result, bool $force = false ): int {
		$notable = array_values(
			array_filter(
				ZSM_Findings::list( 'active' ),
				function ( $f ) {
					return in_array( $f['severity'], array( 'critical', 'high' ), true );
				}
			)
		);
		$alarm   = ZSM_Feed::alarm();
		$sig     = ZSM_Policy::digest_signature( array_column( $notable, 'finding_key' ), $alarm );
		$by_user = get_option( ZSM::OPT . 'digest_sig_by_user', array() );
		$by_user = is_array( $by_user ) ? $by_user : array();
		$due     = ZSM_Policy::digest_due( ZSM::recipient_ids(), $by_user, $sig, $force );
		if ( ! $due ) {
			return 0; // nothing new since each recipient's last digest
		}

		$counts = ZSM_Findings::counts();
		/* translators: 1: critical count, 2: high count */
		$title = sprintf( __( 'Security Monitor: %1$d critical / %2$d high', 'zorderz' ), $counts['critical'], $counts['high'] )
			. ( $alarm ? ' ' . __( '(stale feed)', 'zorderz' ) : '' );
		$text  = self::compose( $notable, $counts );
		$got   = self::deliver( $title, $text, $due );
		foreach ( $got as $uid ) {
			$by_user[ $uid ] = $sig;
		}
		if ( $got ) {
			update_option( ZSM::OPT . 'digest_sig_by_user', $by_user, false );
		}
		return count( $got );
	}

	/** The digest's text. Plain text; the console link at the end. */
	public static function compose( array $notable, array $counts ): string {
		$site = class_exists( 'ZDZ_Business_Profile' ) && method_exists( 'ZDZ_Business_Profile', 'name' ) ? (string) ZDZ_Business_Profile::name() : '';
		$site = '' !== $site ? $site : (string) get_bloginfo( 'name' );

		$lines   = array();
		/* translators: 1: site or business name, 2: date and time */
		$lines[] = sprintf( __( 'Security Monitor digest for %1$s, %2$s UTC', 'zorderz' ), $site, gmdate( 'Y-m-d H:i' ) );
		/* translators: 1: critical, 2: high, 3: medium, 4: low */
		$lines[] = sprintf( __( '%1$d critical · %2$d high · %3$d medium · %4$d low (open, in scope)', 'zorderz' ), (int) $counts['critical'], (int) $counts['high'], (int) $counts['medium'], (int) $counts['low'] );
		$lines[] = '';
		if ( empty( $notable ) ) {
			$lines[] = __( 'No open critical or high findings.', 'zorderz' );
		} else {
			$lines[] = __( 'Critical / high:', 'zorderz' );
			foreach ( $notable as $f ) {
				/* translators: %s: version */
				$fix     = $f['fixed_version'] ? ' → ' . sprintf( __( 'fixed in %s', 'zorderz' ), $f['fixed_version'] ) : '';
				$cve     = $f['cve'] ? ' [' . $f['cve'] . ']' : '';
				$lines[] = sprintf( '- %s %s: %s%s%s', strtoupper( (string) $f['severity'] ), $f['name'] ? $f['name'] : $f['slug'], self::trim_words( (string) $f['title'], 18 ), $fix, $cve );
				if ( ! empty( $f['context_note'] ) ) {
					$lines[] = '   (' . $f['context_note'] . ')';
				}
			}
		}
		$lines[] = '';
		$lines[] = self::scope_line();
		$cs      = ZSM_Codescan::last();
		if ( empty( $cs['at'] ) ) {
			$lines[] = __( 'Code check: not run yet.', 'zorderz' );
		} else {
			$skipped = empty( $cs['skipped'] ) ? '' : ', ' . sprintf(
				/* translators: %d: number of files */
				__( '%d not read (see the console)', 'zorderz' ),
				count( (array) $cs['skipped'] )
			);
			/* translators: 1: number of files, 2: how long ago, 3: optional "not read" note */
			$lines[] = sprintf( __( 'Code check: %1$d files read %2$s ago%3$s.', 'zorderz' ), (int) $cs['files'], human_time_diff( (int) $cs['at'] ), $skipped );
		}
		$lines[] = ( ZSM_Feed::alarm() ? '! ' : '' ) . ZSM_Feed::freshness_line();
		/* translators: %s: URL */
		$lines[] = sprintf( __( 'Console: %s', 'zorderz' ), self::console_url() );
		return implode( "\n", $lines );
	}

	/**
	 * Deliver the digest to $ids (default: every recipient). Returns the ids actually NOTIFIED;
	 * a silent archive copy does not count, so an undelivered digest is tried again.
	 *
	 * @return int[]
	 */
	public static function deliver( string $title, string $text, ?array $ids = null ): array {
		$ids = null === $ids ? ZSM::recipient_ids() : $ids;
		if ( ! $ids ) {
			ZSM::log( 'digest: no recipient' );
			return array();
		}
		$got = array();
		foreach ( $ids as $uid ) {
			if ( ZSM::can_manage( (int) $uid ) && self::deliver_one( (int) $uid, $title, $text ) ) {
				$got[] = (int) $uid;
			}
		}
		return $got;
	}

	/**
	 * The digest as a note to self in Messages, sent on the Messaging app's own seam
	 * (`zim_dm_send`), which decides who may send and makes the post. Null when Messages
	 * is not there; otherwise the seam's answer (array with message_id, or WP_Error).
	 *
	 * @return array|WP_Error|null
	 */
	public static function messages_copy( int $uid, string $title, string $text ) {
		if ( $uid <= 0 || ! function_exists( 'has_filter' ) || false === has_filter( 'zim_dm_send' ) ) {
			return null;
		}
		return apply_filters( 'zim_dm_send', null, $uid, $uid, $title . "\n\n" . $text, array() );
	}

	private static function deliver_one( int $uid, string $title, string $text ): bool {
		$notified = false;

		// 1) The alert router: the in-app bell and e-mail, per the router's channel settings.
		if ( class_exists( 'ZDZ_Alert_Router' ) && method_exists( 'ZDZ_Alert_Router', 'send' ) ) {
			$used     = ZDZ_Alert_Router::send(
				ZSM::ALERT_TYPE,
				$uid,
				$title,
				$text,
				array(
					'plugin'    => ZSM::APP_ID,
					'sender_id' => $uid,
					'url'       => self::console_url(),
				)
			);
			$notified = is_array( $used ) && ! empty( $used );
			ZSM::log( 'digest: alert router channels = ' . ( $notified ? implode( ',', $used ) : 'none' ) );
		}

		// 2) A copy in Messages (a note to self), so the digest history sits with the person's messages.
		$copy = self::messages_copy( $uid, $title, $text );
		if ( null !== $copy ) {
			ZSM::log( 'digest: Messages copy ' . ( is_wp_error( $copy ) ? 'failed: ' . $copy->get_error_code() : ( is_array( $copy ) ? 'posted' : 'not answered' ) ) );
		}

		// 3) Plain e-mail, only when nothing above notified the person.
		if ( ! $notified ) {
			$u = get_userdata( $uid );
			if ( $u && ! empty( $u->user_email ) ) {
				$notified = (bool) wp_mail( $u->user_email, $title, $text );
				ZSM::log( 'digest: wp_mail ' . ( $notified ? 'sent' : 'failed' ) );
			}
		}
		return $notified;
	}
}
