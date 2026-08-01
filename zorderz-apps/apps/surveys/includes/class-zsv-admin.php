<?php
/**
 * Zorderz Surveys — settings page.
 *
 * Every value the source plugin baked in (the operator's name, the exclusion list, the
 * review URLs + routing rule, the grace windows, the resurvey cooldown, the safety-floor
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

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
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
				__( 'From Business Profile — primary: %1$s · custom: %2$s', 'zorderz' ),
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
						<td><?php wp_dropdown_users( array( 'name' => 'zsv_operator_user_id', 'id' => 'zsv_operator_user_id', 'selected' => ZSV_Settings::operator_user_id(), 'show_option_none' => __( '— none —', 'zorderz' ), 'option_none_value' => 0 ) ); ?></td></tr>
					<tr><th><label for="zsv_operator_name"><?php esc_html_e( 'Operator name (optional)', 'zorderz' ); ?></label></th>
						<td><input type="text" id="zsv_operator_name" name="zsv_operator_name" class="regular-text" value="<?php echo esc_attr( get_option( 'zsv_operator_name', '' ) ); ?>" /></td></tr>
					<tr><th><label for="zsv_operator_match"><?php esc_html_e( 'Author-match pattern (optional)', 'zorderz' ); ?></label></th>
						<td><input type="text" id="zsv_operator_match" name="zsv_operator_match" class="regular-text" value="<?php echo esc_attr( get_option( 'zsv_operator_match', '' ) ); ?>" />
						<p class="description"><?php esc_html_e( 'Case-insensitive substring used to recognise the operator\'s CRM notes. Blank = derive from the operator name.', 'zorderz' ); ?></p></td></tr>
					<tr><th><label for="zsv_operator_crm_user_id"><?php esc_html_e( 'Operator CRM user id (optional)', 'zorderz' ); ?></label></th>
						<td><input type="number" id="zsv_operator_crm_user_id" name="zsv_operator_crm_user_id" value="<?php echo esc_attr( (string) get_option( 'zsv_operator_crm_user_id', 0 ) ); ?>" min="0" /></td></tr>

					<tr><th colspan="2"><h2><?php esc_html_e( 'Exclusions', 'zorderz' ); ?></h2></th></tr>
					<tr><th><label for="zsv_excluded_companies"><?php esc_html_e( 'Excluded name/email fragments', 'zorderz' ); ?></label></th>
						<td><textarea id="zsv_excluded_companies" name="zsv_excluded_companies" rows="4" class="large-text" placeholder="<?php esc_attr_e( 'one fragment per line — ships empty', 'zorderz' ); ?>"><?php echo esc_textarea( implode( "\n", $excluded ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'A customer whose name or email contains any fragment is skipped. Empty by default — add your own commercial/property-management accounts.', 'zorderz' ); ?></p></td></tr>
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
						<p class="description"><strong><?php esc_html_e( 'Non-overridable floor:', 'zorderz' ); ?></strong> <?php esc_html_e( 'a survey is NEVER auto-closed as Won without human review. Leads needing attention, excluded, in follow-up, or never contacted are always escalated, never system-Won — regardless of this setting.', 'zorderz' ); ?></p></td></tr>
					<tr><th><label for="zsv_crm_status_won"><?php esc_html_e( 'CRM "won" status value', 'zorderz' ); ?></label></th>
						<td><input type="number" id="zsv_crm_status_won" name="zsv_crm_status_won" value="<?php echo esc_attr( (string) get_option( 'zsv_crm_status_won', 1 ) ); ?>" min="0" />
						<p class="description"><?php esc_html_e( 'The CRM status integer that means "won" (a tenant Mapping). Default 1.', 'zorderz' ); ?></p></td></tr>

					<tr><th colspan="2"><h2><?php esc_html_e( 'AI + copy', 'zorderz' ); ?></h2></th></tr>
					<tr><th><label for="zsv_ai_model"><?php esc_html_e( 'AI model (optional)', 'zorderz' ); ?></label></th>
						<td><input type="text" id="zsv_ai_model" name="zsv_ai_model" class="regular-text" value="<?php echo esc_attr( get_option( 'zsv_ai_model', '' ) ); ?>" />
						<p class="description"><?php esc_html_e( 'Blank = use the platform default model from the Core AI service.', 'zorderz' ); ?></p></td></tr>
					<tr><th><label for="zsv_generic_work_phrase"><?php esc_html_e( 'Generic work phrase', 'zorderz' ); ?></label></th>
						<td><input type="text" id="zsv_generic_work_phrase" name="zsv_generic_work_phrase" class="regular-text" value="<?php echo esc_attr( get_option( 'zsv_generic_work_phrase', '' ) ); ?>" placeholder="<?php esc_attr_e( 'your recent service', 'zorderz' ); ?>" /></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
