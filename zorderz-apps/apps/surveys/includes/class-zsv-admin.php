<?php
/**
 * Zorderz Surveys — settings page.
 *
 * Every business-specific value (the operator's name, the exclusion list, the review
 * URLs + routing rule, the grace windows, the resurvey cooldown, the safety-floor
 * policy) is a field here. Review destinations + the sender identity are NOT duplicated:
 * those live in the theme's Business Profile and are only shown as read-only hints.
 *
 * @package Zorderz\Surveys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSV_Admin {

	const PAGE = 'zsv-settings';
	const GROUP = 'zsv_settings';

	/** How many e-mailed customers the "send again" list shows (the most recently e-mailed). */
	const SENT_LISTED = 50;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/** The script behind "Send again", on this screen only. */
	public static function assets( $hook = '' ): void {
		if ( 'settings_page_' . self::PAGE !== (string) $hook || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_enqueue_script( 'zsv-admin', ZSV_URL . 'assets/js/admin.js', array(), zsv_asset_ver( 'assets/js/admin.js' ), true );
		wp_localize_script(
			'zsv-admin',
			'zsvAdmin',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( ZSV_NONCE ),
				'words'   => self::send_again_words(),
			)
		);
	}

	/** What the "send again" question and its answers say. %1$s the customer, %2$s when they were e-mailed, %3$s which send it was. */
	public static function send_again_words(): array {
		return array(
			/* translators: 1: customer's name, 2: when they were e-mailed */
			'ask'         => __( "%1\$s was ALREADY E-MAILED (%2\$s).\n\nSend this customer the review request AGAIN?\n\nOK = send again.    Cancel = send nothing.", 'zorderz' ),
			'sending'     => __( 'Sending…', 'zorderz' ),
			/* translators: %3$s: which send this was (2, 3, …) */
			'sent'        => __( 'Sent again (send #%3$s). The CRM has a note saying so.', 'zorderz' ),
			'unrecorded'  => __( 'Sent again, but the time could not be saved on the record. Do not send again.', 'zorderz' ),
			'in_progress' => __( 'Another send to this customer is running right now: not sent again.', 'zorderz' ),
			'lock'        => __( 'Could not make sure this customer is e-mailed only once just now: not sent. Try again in a minute.', 'zorderz' ),
			'unreadable'  => __( 'Could not read this customer\'s record just now: not sent.', 'zorderz' ),
			'crm_closed'  => __( 'The lead is closed as lost or cancelled in the CRM: not sent.', 'zorderz' ),
			'held'        => __( 'Not sent: this customer\'s call outcome, address or review destination holds the e-mail back.', 'zorderz' ),
			'failed'      => __( 'The mail service did not take it. Nothing was sent.', 'zorderz' ),
			'refused'     => __( 'Not sent again: only an administrator can ask for a second e-mail.', 'zorderz' ),
			'error'       => __( 'That did not work. Nothing is known to have been sent; check the list before trying again.', 'zorderz' ),
		);
	}

	/**
	 * The customers most recently e-mailed, for the "send again" list: [ id, name, at, sends ].
	 * A customer whose e-mail went but whose record would not take the time (the remembered list)
	 * is e-mailed all the same, so they are listed too, first.
	 *
	 * @return array[]
	 */
	public static function sent_rows(): array {
		global $wpdb;
		$t   = ZSV_DB::leads_table();
		$out = array();

		$remembered = array();
		foreach ( ZSV_Send_Once::unrecorded() as $id => $when ) {
			if ( (int) $id > 0 && is_scalar( $when ) && '' !== trim( (string) $when ) ) {
				$remembered[ (int) $id ] = trim( (string) $when );
			}
		}
		if ( $remembered ) {
			$ids   = array_slice( array_reverse( array_keys( $remembered ) ), 0, self::SENT_LISTED );
			$marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, first_name, last_name, email_sent_at, email_send_log FROM {$t} WHERE id IN ({$marks})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $t is $wpdb->prefix plus a fixed table name; $marks is one %d for each id handed to prepare().
					$ids
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $r ) {
				$id = (int) $r['id'];
				if ( '' !== ZSV_Send_Once::emailed_at( (array) $r ) ) {
					continue;                                            // the record has the time after all: listed below
				}
				$out[ $id ] = array(
					'id'    => $id,
					'name'  => trim( ( $r['first_name'] ?? '' ) . ' ' . ( $r['last_name'] ?? '' ) ),
					'at'    => substr( $remembered[ $id ], 0, 16 ),
					'sends' => 1,
				);
			}
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, first_name, last_name, email_sent_at, email_send_log FROM {$t} WHERE email_sent_at IS NOT NULL ORDER BY email_sent_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is $wpdb->prefix plus a fixed table name.
				self::SENT_LISTED
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $r ) {
			$at = ZSV_Send_Once::emailed_at( (array) $r );
			if ( '' === $at ) {
				continue;
			}
			$out[ (int) $r['id'] ] = array(
				'id'    => (int) $r['id'],
				'name'  => trim( ( $r['first_name'] ?? '' ) . ' ' . ( $r['last_name'] ?? '' ) ),
				'at'    => substr( $at, 0, 16 ),
				'sends' => max( 1, count( ZSV_Send_Once::send_log( (array) $r ) ) ),
			);
		}
		return array_slice( array_values( $out ), 0, self::SENT_LISTED );
	}

	/**
	 * "Review e-mails already sent": the one place a person can ask for a second e-mail to a
	 * customer. One customer at a time, behind a question (assets/js/admin.js); the site then
	 * sends it as "send again" (ZSV_Dashboard::asked_again, an administrator only).
	 */
	private static function render_sent(): void {
		$rows = self::sent_rows();
		?>
		<h2><?php esc_html_e( 'Review e-mails already sent', 'zorderz' ); ?></h2>
		<p class="description"><?php esc_html_e( 'A customer is sent the review request once. To send it to one of them again, press Send again: you are asked first, and the CRM lead gets a note saying which send it was.', 'zorderz' ); ?></p>
		<?php if ( ! $rows ) : ?>
			<p><?php esc_html_e( 'No review e-mail has been sent yet.', 'zorderz' ); ?></p>
			<?php
			return;
		endif;
		?>
		<table class="widefat striped" id="zsv-sent" style="max-width:860px">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Customer', 'zorderz' ); ?></th>
				<th scope="col"><?php esc_html_e( 'E-mailed', 'zorderz' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Sends', 'zorderz' ); ?></th>
				<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Send again', 'zorderz' ); ?></span></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $r ) : ?>
				<tr>
					<td><?php echo esc_html( '' !== $r['name'] ? $r['name'] : '#' . $r['id'] ); ?></td>
					<td><?php echo esc_html( $r['at'] ); ?></td>
					<td class="zsv-sends"><?php echo esc_html( (string) $r['sends'] ); ?></td>
					<td>
						<button type="button" class="button zsv-send-again" data-id="<?php echo esc_attr( (string) $r['id'] ); ?>" data-name="<?php echo esc_attr( '' !== $r['name'] ? $r['name'] : '#' . $r['id'] ); ?>" data-at="<?php echo esc_attr( $r['at'] ); ?>"><?php esc_html_e( 'Send again…', 'zorderz' ); ?></button>
						<span class="zsv-send-again-result" role="status" aria-live="polite"></span>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public static function menu(): void {
		add_options_page(
			__( 'Surveys', 'zorderz' ),
			__( 'Surveys', 'zorderz' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/** Register every option with a sanitizer. */
	public static function register(): void {
		$opts = array(
			'zsv_operator_user_id'       => 'intval',
			'zsv_operator_crm_user_id'   => 'intval',
			'zsv_operator_name'          => 'sanitize_text_field',
			'zsv_operator_match'         => 'sanitize_text_field',
			'zsv_excluded_companies'     => array( __CLASS__, 'sanitize_lines' ),
			'zsv_do_not_survey_tag'      => 'sanitize_text_field',
			'zsv_review_routing'         => 'sanitize_text_field',
			'zsv_review_deeplink_domains' => array( __CLASS__, 'sanitize_lines' ),
			'zsv_review_url_primary'     => 'esc_url_raw',
			'zsv_review_url_custom'      => 'esc_url_raw',
			'zsv_grace_hours_callback'   => 'intval',
			'zsv_grace_hours_default'    => 'intval',
			'zsv_resurvey_cooldown_days' => 'intval',
			'zsv_fetch_lookback_days'    => 'intval',
			'zsv_batch_size'             => 'intval',
			'zsv_allow_system_close_won' => array( __CLASS__, 'sanitize_bool' ),
			'zsv_crm_status_won'         => 'intval',
			'zsv_ai_model'               => 'sanitize_text_field',
			'zsv_generic_work_phrase'    => 'sanitize_text_field',
			'zsv_greviews_lookback_days'  => array( __CLASS__, 'sanitize_lookback_days' ),
			'zsv_greviews_candidate_days' => array( __CLASS__, 'sanitize_candidate_days' ),
		);
		foreach ( $opts as $name => $cb ) {
			register_setting( self::GROUP, $name, array( 'sanitize_callback' => $cb ) );
		}
	}

	public static function sanitize_lines( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\r\n,]+/', $value );
		}
		$out = array();
		foreach ( (array) $value as $v ) {
			$v = trim( sanitize_text_field( (string) $v ) );
			if ( '' !== $v ) {
				$out[] = $v;
			}
		}
		return $out;
	}

	public static function sanitize_bool( $value ): bool {
		return ! empty( $value );
	}

	/** Whole days inside a range; anything else (blank, a word, out of range) is the default. */
	public static function days_in( $value, int $min, int $max, int $default ): int {
		$v = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' === $v || ! ctype_digit( $v ) ) {
			return $default;
		}
		$n = (int) $v;
		return ( $n < $min || $n > $max ) ? $default : $n;
	}

	public static function sanitize_lookback_days( $value ): int {
		return self::days_in( $value, 1, 365, 60 );
	}

	public static function sanitize_candidate_days( $value ): int {
		return self::days_in( $value, 1, 730, 120 );
	}

	/** One line saying where the business's own Google reviews stand, for the settings screen. */
	public static function google_reviews_line(): string {
		if ( ! class_exists( 'ZSV_Google_Reviews' ) || ! ZSV_Google_Reviews::ready() ) {
			return __( 'Not connected. Connect Google Business Profile under Zorderz → Credentials and the Surveys widget starts listing new reviews; until then nothing is asked of Google.', 'zorderz' );
		}
		return ZSV_Google_Reviews::automatic()
			? __( 'Connected. Automatic recording is ON: a review that matches exactly one surveyed customer by full name is recorded without a tap. An administrator switches this in the Surveys widget.', 'zorderz' )
			: __( 'Connected. Watch-only: new reviews are listed in the Surveys widget and nothing is recorded until a person presses Confirm. An administrator can switch automatic recording on there.', 'zorderz' );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$profile_hint = '';
		if ( class_exists( 'ZDZ_Business_Profile' ) ) {
			$g = (string) ZDZ_Business_Profile::get( 'web.review_google', '' );
			$p = (string) ZDZ_Business_Profile::get( 'web.review_page', '' );
			$profile_hint = sprintf(
				/* translators: 1: primary review URL, 2: custom review URL */
				__( 'From Business Profile, primary: %1$s · custom: %2$s', 'zorderz' ),
				$g !== '' ? esc_html( $g ) : '(unset)',
				$p !== '' ? esc_html( $p ) : '(unset)'
			);
		}
		$excluded = (array) get_option( 'zsv_excluded_companies', array() );
		$domains  = (array) get_option( 'zsv_review_deeplink_domains', array() );
		$routing  = (string) get_option( 'zsv_review_routing', 'auto' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Satisfaction Surveys', 'zorderz' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">

					<tr><th colspan="2"><h2><?php esc_html_e( 'Survey operator', 'zorderz' ); ?></h2>
						<p class="description"><?php esc_html_e( 'The person who calls customers and logs outcomes in the CRM. New survey leads are assigned to them; their CRM notes drive the status. This replaces a hardcoded operator name.', 'zorderz' ); ?></p></th></tr>
					<tr><th><label for="zsv_operator_user_id"><?php esc_html_e( 'Operator user', 'zorderz' ); ?></label></th>
						<td><?php wp_dropdown_users( array( 'name' => 'zsv_operator_user_id', 'id' => 'zsv_operator_user_id', 'selected' => ZSV_Settings::operator_user_id(), 'show_option_none' => __( '(none)', 'zorderz' ), 'option_none_value' => 0 ) ); ?></td></tr>
					<tr><th><label for="zsv_operator_name"><?php esc_html_e( 'Operator name (optional)', 'zorderz' ); ?></label></th>
						<td><input type="text" id="zsv_operator_name" name="zsv_operator_name" class="regular-text" value="<?php echo esc_attr( get_option( 'zsv_operator_name', '' ) ); ?>" /></td></tr>
					<tr><th><label for="zsv_operator_match"><?php esc_html_e( 'Author-match pattern (optional)', 'zorderz' ); ?></label></th>
						<td><input type="text" id="zsv_operator_match" name="zsv_operator_match" class="regular-text" value="<?php echo esc_attr( get_option( 'zsv_operator_match', '' ) ); ?>" />
						<p class="description"><?php esc_html_e( 'Case-insensitive substring used to recognise the operator\'s CRM notes. Blank = derive from the operator name.', 'zorderz' ); ?></p></td></tr>
					<tr><th><label for="zsv_operator_crm_user_id"><?php esc_html_e( 'Operator CRM user id (optional)', 'zorderz' ); ?></label></th>
						<td><input type="number" id="zsv_operator_crm_user_id" name="zsv_operator_crm_user_id" value="<?php echo esc_attr( (string) get_option( 'zsv_operator_crm_user_id', 0 ) ); ?>" min="0" /></td></tr>

					<tr><th colspan="2"><h2><?php esc_html_e( 'Exclusions', 'zorderz' ); ?></h2></th></tr>
					<tr><th><label for="zsv_excluded_companies"><?php esc_html_e( 'Excluded name/email fragments', 'zorderz' ); ?></label></th>
						<td><textarea id="zsv_excluded_companies" name="zsv_excluded_companies" rows="4" class="large-text" placeholder="<?php esc_attr_e( 'one fragment per line; ships empty', 'zorderz' ); ?>"><?php echo esc_textarea( implode( "\n", $excluded ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'A customer whose name or email contains any fragment is skipped. Empty by default. Add your own commercial/property-management accounts.', 'zorderz' ); ?></p></td></tr>
					<tr><th><label for="zsv_do_not_survey_tag"><?php esc_html_e( 'Do-not-survey CRM tag', 'zorderz' ); ?></label></th>
						<td><input type="text" id="zsv_do_not_survey_tag" name="zsv_do_not_survey_tag" class="regular-text" value="<?php echo esc_attr( get_option( 'zsv_do_not_survey_tag', 'Do Not Survey' ) ); ?>" /></td></tr>

					<tr><th colspan="2"><h2><?php esc_html_e( 'Review destination', 'zorderz' ); ?></h2>
						<?php if ( $profile_hint ) : ?><p class="description"><?php echo esc_html( $profile_hint ); ?></p><?php endif; ?></th></tr>
					<tr><th><label for="zsv_review_routing"><?php esc_html_e( 'Routing rule', 'zorderz' ); ?></label></th>
						<td><select id="zsv_review_routing" name="zsv_review_routing">
							<?php foreach ( array( 'auto' => __( 'Auto (deep-link for configured domains)', 'zorderz' ), 'primary_only' => __( 'Always primary', 'zorderz' ), 'custom_only' => __( 'Always custom page', 'zorderz' ), 'off' => __( 'Off (no review link)', 'zorderz' ) ) as $k => $label ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $routing, $k ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select></td></tr>
					<tr><th><label for="zsv_review_deeplink_domains"><?php esc_html_e( 'Deep-link domains', 'zorderz' ); ?></label></th>
						<td><textarea id="zsv_review_deeplink_domains" name="zsv_review_deeplink_domains" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'one email domain per line', 'zorderz' ); ?>"><?php echo esc_textarea( implode( "\n", $domains ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Under the Auto rule, recipients on these email domains get the primary (deep-link) destination; everyone else gets the custom page. Empty = nobody is singled out by address.', 'zorderz' ); ?></p></td></tr>
					<tr><th><label for="zsv_review_url_primary"><?php esc_html_e( 'Primary review URL (fallback)', 'zorderz' ); ?></label></th>
						<td><input type="url" id="zsv_review_url_primary" name="zsv_review_url_primary" class="regular-text" value="<?php echo esc_attr( get_option( 'zsv_review_url_primary', '' ) ); ?>" />
						<p class="description"><?php esc_html_e( 'Only used if the Business Profile primary review URL is unset.', 'zorderz' ); ?></p></td></tr>
					<tr><th><label for="zsv_review_url_custom"><?php esc_html_e( 'Custom review URL (fallback)', 'zorderz' ); ?></label></th>
						<td><input type="url" id="zsv_review_url_custom" name="zsv_review_url_custom" class="regular-text" value="<?php echo esc_attr( get_option( 'zsv_review_url_custom', '' ) ); ?>" /></td></tr>

					<tr><th colspan="2"><h2><?php esc_html_e( 'Lifecycle rules', 'zorderz' ); ?></h2></th></tr>
					<tr><th><label for="zsv_grace_hours_callback"><?php esc_html_e( 'Callback grace (hours)', 'zorderz' ); ?></label></th>
						<td><input type="number" id="zsv_grace_hours_callback" name="zsv_grace_hours_callback" value="<?php echo esc_attr( (string) get_option( 'zsv_grace_hours_callback', 120 ) ); ?>" min="1" /></td></tr>
					<tr><th><label for="zsv_grace_hours_default"><?php esc_html_e( 'Default grace (hours)', 'zorderz' ); ?></label></th>
						<td><input type="number" id="zsv_grace_hours_default" name="zsv_grace_hours_default" value="<?php echo esc_attr( (string) get_option( 'zsv_grace_hours_default', 96 ) ); ?>" min="1" /></td></tr>
					<tr><th><label for="zsv_resurvey_cooldown_days"><?php esc_html_e( 'Resurvey cooldown (days)', 'zorderz' ); ?></label></th>
						<td><input type="number" id="zsv_resurvey_cooldown_days" name="zsv_resurvey_cooldown_days" value="<?php echo esc_attr( (string) get_option( 'zsv_resurvey_cooldown_days', 365 ) ); ?>" min="0" /></td></tr>
					<tr><th><label for="zsv_fetch_lookback_days"><?php esc_html_e( 'Billing lookback (days)', 'zorderz' ); ?></label></th>
						<td><input type="number" id="zsv_fetch_lookback_days" name="zsv_fetch_lookback_days" value="<?php echo esc_attr( (string) get_option( 'zsv_fetch_lookback_days', 90 ) ); ?>" min="1" /></td></tr>
					<tr><th><label for="zsv_batch_size"><?php esc_html_e( 'Batch size', 'zorderz' ); ?></label></th>
						<td><input type="number" id="zsv_batch_size" name="zsv_batch_size" value="<?php echo esc_attr( (string) get_option( 'zsv_batch_size', 10 ) ); ?>" min="1" max="50" /></td></tr>

					<tr><th colspan="2"><h2><?php esc_html_e( 'Safety floor', 'zorderz' ); ?></h2></th></tr>
					<tr><th><?php esc_html_e( 'Auto-close policy', 'zorderz' ); ?></th>
						<td><label><input type="checkbox" name="zsv_allow_system_close_won" value="1" <?php checked( ZSV_Settings::allow_system_close_won() ); ?> />
						<?php esc_html_e( 'Allow the system to auto-close GENUINELY SATISFIED leads as Won after the grace window.', 'zorderz' ); ?></label>
						<p class="description"><strong><?php esc_html_e( 'Non-overridable floor:', 'zorderz' ); ?></strong> <?php esc_html_e( 'a survey is NEVER auto-closed as Won without human review. Leads needing attention, excluded, in follow-up, or never contacted are always escalated, never system-Won, regardless of this setting.', 'zorderz' ); ?></p></td></tr>
					<tr><th><label for="zsv_crm_status_won"><?php esc_html_e( 'CRM "won" status value', 'zorderz' ); ?></label></th>
						<td><input type="number" id="zsv_crm_status_won" name="zsv_crm_status_won" value="<?php echo esc_attr( (string) get_option( 'zsv_crm_status_won', 1 ) ); ?>" min="0" />
						<p class="description"><?php esc_html_e( 'The CRM status integer that means "won" (a CRM mapping). Default 1.', 'zorderz' ); ?></p></td></tr>

					<tr><th colspan="2"><h2><?php esc_html_e( 'Google reviews', 'zorderz' ); ?></h2>
						<p class="description"><?php echo esc_html( self::google_reviews_line() ); ?></p></th></tr>
					<tr><th><label for="zsv_greviews_lookback_days"><?php esc_html_e( 'Reviews listed (days back)', 'zorderz' ); ?></label></th>
						<td><input type="number" id="zsv_greviews_lookback_days" name="zsv_greviews_lookback_days" value="<?php echo esc_attr( (string) ZSV_Settings::greviews_lookback_days() ); ?>" min="1" max="365" />
						<p class="description"><?php esc_html_e( 'Only a review this recent is listed and matched. Default 60.', 'zorderz' ); ?></p></td></tr>
					<tr><th><label for="zsv_greviews_candidate_days"><?php esc_html_e( 'Customers considered (days before the review)', 'zorderz' ); ?></label></th>
						<td><input type="number" id="zsv_greviews_candidate_days" name="zsv_greviews_candidate_days" value="<?php echo esc_attr( (string) ZSV_Settings::greviews_candidate_days() ); ?>" min="1" max="730" />
						<p class="description"><?php esc_html_e( 'Only a customer whose follow-up was opened at most this long before a review can be its writer. Default 120.', 'zorderz' ); ?></p></td></tr>

					<tr><th colspan="2"><h2><?php esc_html_e( 'Ai + copy', 'zorderz' ); ?></h2></th></tr>
					<tr><th><label for="zsv_ai_model"><?php esc_html_e( 'Ai model (optional)', 'zorderz' ); ?></label></th>
						<td><input type="text" id="zsv_ai_model" name="zsv_ai_model" class="regular-text" value="<?php echo esc_attr( get_option( 'zsv_ai_model', '' ) ); ?>" />
						<p class="description"><?php esc_html_e( 'Blank = use the platform default model from the Core Ai service.', 'zorderz' ); ?></p></td></tr>
					<tr><th><label for="zsv_generic_work_phrase"><?php esc_html_e( 'Generic work phrase', 'zorderz' ); ?></label></th>
						<td><input type="text" id="zsv_generic_work_phrase" name="zsv_generic_work_phrase" class="regular-text" value="<?php echo esc_attr( get_option( 'zsv_generic_work_phrase', '' ) ); ?>" placeholder="<?php esc_attr_e( 'your recent service', 'zorderz' ); ?>" /></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<?php self::render_sent(); ?>
		</div>
		<?php
	}
}
