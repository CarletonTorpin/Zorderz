<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Core_Settings {

	private static $instance = null;
	const OPTION_PREFIX = 'zdz_core_';

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'after_switch_theme', [ $this, 'migrate_credentials' ] );
	}

	public function add_settings_page() {
		add_menu_page(
			__( 'Zorderz Core', 'zorderz' ),
			__( 'Zorderz', 'zorderz' ),
			'manage_options',
			'zdz-core-settings',
			[ $this, 'render_settings' ],
			'dashicons-smartphone',
			2
		);
	}

	/**
	 * Credential fields that hold secrets. These are rendered MASKED (never printed
	 * into the settings form) and, when submitted blank, keep their stored value - so
	 * a normal Save never wipes a key you did not retype, and the secret is never
	 * echoed back to the browser in the clear.
	 */
	public static function secret_fields(): array { // public: single source of truth for the data-portability secret exclusion
		return [ 'poe_api_key', 'fb_client_secret', 'fb_access_token', 'fb_refresh_token', 'ns_api_key', 'review_bridge_key' ];
	}

	/**
	 * The options this form saves. The Ai gateway, CRM and billing credentials are not among them: they live on
	 * Zorderz → Credentials (ZDZ_Core_Credentials), which writes every app's copy in the format that app reads. They
	 * must not be REGISTERED here either: options.php writes every registered option of the group, so a registered
	 * credential missing from the form would be saved as empty. Reads are unchanged (get_credential()).
	 */
	public static function form_fields(): array {
		return [ 'ai_model', 'review_bridge_url', 'review_bridge_key' ];
	}

	public function register_settings() {
		$secret_fields = self::secret_fields();
		foreach ( self::form_fields() as $field ) {
			$optname = self::OPTION_PREFIX . $field;
			if ( in_array( $field, $secret_fields, true ) ) {
				register_setting( 'zdz_core_settings_group', $optname, [
					'type'              => 'string',
					'sanitize_callback' => function ( $value ) use ( $optname ) {
						return self::sanitize_secret_submit( $value, (string) get_option( $optname ) );
					},
				] );
			} else {
				register_setting( 'zdz_core_settings_group', $optname, [
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				] );
			}
		}

		// The Google review count is typed in until the site reads Google itself; saving it also keeps the date it
		// was typed (or confirmed), which the home tile shows. The website's count is not typed: the home tile asks
		// the website through the Review Bridge.
		register_setting( 'zdz_core_settings_group', self::OPTION_PREFIX . 'google_reviews_count', [
			'type'              => 'integer',
			'sanitize_callback' => [ __CLASS__, 'sanitize_google_reviews_count' ],
			'default'           => 0,
		] );

		// Company-level settings for cross-plugin use
		register_setting( 'zdz_core_settings_group', 'zdz_company_phone', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		] );
		register_setting( 'zdz_core_settings_group', 'zdz_receptionist_hours', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'Mon–Fri 9am–5pm',
		] );
	}

	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Zorderz Core Settings', 'zorderz' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'zdz_core_settings_group' );
				do_settings_sections( 'zdz_core_settings_group' );
				
				$cred_url = class_exists( 'ZDZ_Core_Credentials' ) ? ZDZ_Core_Credentials::page_url() : admin_url( 'admin.php?page=zdz-core-credentials' );
				?>
				<p><?php esc_html_e( 'The Ai gateway, CRM and billing keys are kept on one screen, which gives every app its copy:', 'zorderz' ); ?>
					<a class="button" href="<?php echo esc_url( $cred_url ); ?>"><?php esc_html_e( 'Zorderz → Credentials', 'zorderz' ); ?></a></p>
				<table class="form-table">
					<?php
					$rb_url_opt = self::OPTION_PREFIX . 'review_bridge_url';
					$rb_key_opt = self::OPTION_PREFIX . 'review_bridge_key';
					$rb_key_set = '' !== (string) get_option( $rb_key_opt );
					?>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $rb_url_opt ); ?>"><?php esc_html_e( 'Review Bridge URL', 'zorderz' ); ?></label></th>
						<td><input type="text" id="<?php echo esc_attr( $rb_url_opt ); ?>" name="<?php echo esc_attr( $rb_url_opt ); ?>" value="<?php echo esc_attr( (string) get_option( $rb_url_opt ) ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $rb_key_opt ); ?>"><?php esc_html_e( 'Review Bridge API Key', 'zorderz' ); ?></label></th>
						<td>
							<input type="password" id="<?php echo esc_attr( $rb_key_opt ); ?>" name="<?php echo esc_attr( $rb_key_opt ); ?>" value="" autocomplete="new-password" class="regular-text" placeholder="<?php echo $rb_key_set ? esc_attr__( 'Enter a new value to replace', 'zorderz' ) : esc_attr__( 'Not set', 'zorderz' ); ?>" />
							<?php if ( $rb_key_set ) : ?>
								<span class="description" style="margin-left:6px"><?php esc_html_e( 'Currently set (hidden). Leave blank to keep it.', 'zorderz' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPTION_PREFIX . 'ai_model' ); ?>"><?php esc_html_e( 'Ai model', 'zorderz' ); ?></label></th>
						<td>
														<?php
							// The AI-model roster is a vendor/tenant fact: Core names no model. The
							// choices come from ZDZ_Model_Registry::choices() (the zdz_model_choices
							// filter - EMPTY in Core, filled by the connections pack). When empty,
							// render a free-text field so an operator on any gateway can type the
							// handle their pack resolves.
							$model_optname = self::OPTION_PREFIX . 'ai_model';
							$current       = (string) get_option( $model_optname, '' );
							$model_choices = class_exists( 'ZDZ_Model_Registry' ) ? ZDZ_Model_Registry::choices() : array();
							if ( ! empty( $model_choices ) ) {
								// Normalize to value => label (accept a flat list or handle => label).
								$options = array();
								foreach ( $model_choices as $ck => $cv ) {
									if ( is_int( $ck ) ) {
										$options[ (string) $cv ] = (string) $cv;
									} else {
										$options[ (string) $ck ] = (string) $cv;
									}
								}
								// Never lose a saved handle that is no longer in the roster.
								if ( '' !== $current && ! isset( $options[ $current ] ) ) {
									$options[ $current ] = $current;
								}
								echo '<select id="' . esc_attr( $model_optname ) . '" name="' . esc_attr( $model_optname ) . '">';
								foreach ( $options as $opt_val => $opt_label ) {
									echo '<option value="' . esc_attr( $opt_val ) . '" ' . selected( $current, $opt_val, false ) . '>' . esc_html( $opt_label ) . '</option>';
								}
								echo '</select>';
							} else {
								?>
								<input type="text" id="<?php echo esc_attr( $model_optname ); ?>" name="<?php echo esc_attr( $model_optname ); ?>" value="<?php echo esc_attr( $current ); ?>" class="regular-text" autocomplete="off" placeholder="<?php esc_attr_e( 'Enter the model handle from your connections pack', 'zorderz' ); ?>" />
								<p class="description"><?php esc_html_e( 'The exact model/bot handle your AI gateway expects. Your connections pack can supply a picklist here.', 'zorderz' ); ?></p>
								<?php
							}
							?>
						</td>
					</tr>
				</table>

				<!-- Company Contact Settings -->
				<h2><?php esc_html_e( 'Company Contact Info', 'zorderz' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Used by the Lead Generator, email templates, and customer-facing communications across all plugins.', 'zorderz' ); ?></p>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="zdz_company_phone"><?php esc_html_e( 'Main Office Phone', 'zorderz' ); ?></label></th>
						<td>
							<input type="tel" id="zdz_company_phone" name="zdz_company_phone" value="<?php echo esc_attr( get_option( 'zdz_company_phone', '' ) ); ?>" class="regular-text" placeholder="e.g. 555-0100" />
							<p class="description"><?php esc_html_e( 'The main office or receptionist phone number. Used in email sign-offs when no salesperson phone is set.', 'zorderz' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zdz_receptionist_hours"><?php esc_html_e( 'Office Hours', 'zorderz' ); ?></label></th>
						<td>
							<input type="text" id="zdz_receptionist_hours" name="zdz_receptionist_hours" value="<?php echo esc_attr( get_option( 'zdz_receptionist_hours', 'Mon–Fri 9am–5pm' ) ); ?>" class="regular-text" placeholder="Mon–Fri 9am–5pm" />
							<p class="description"><?php esc_html_e( 'Office availability hours. Used in customer-facing emails and landing pages.', 'zorderz' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Google reviews (home screen)', 'zorderz' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Until the site is connected to the Google Business Profile, this number is typed in. The home screen shows it with the date it was typed. The website\'s own review count is read from the website through the Review Bridge.', 'zorderz' ); ?></p>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPTION_PREFIX . 'google_reviews_count' ); ?>"><?php esc_html_e( 'Google Reviews Count', 'zorderz' ); ?></label></th>
						<td>
							<input type="number" id="<?php echo esc_attr( self::OPTION_PREFIX . 'google_reviews_count' ); ?>" name="<?php echo esc_attr( self::OPTION_PREFIX . 'google_reviews_count' ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_PREFIX . 'google_reviews_count', 0 ) ); ?>" class="small-text" min="0" step="1" />
							<?php $zdz_g_at = self::get_google_reviews_entered_at(); ?>
							<p class="description"><?php
								echo esc_html( $zdz_g_at > 0
									/* translators: %s: a date */
									? sprintf( __( 'Entered %s.', 'zorderz' ), wp_date( 'j M Y', $zdz_g_at ) )
									: __( 'No date kept yet: the next save keeps one.', 'zorderz' ) );
							?></p>
							<p class="description"><?php esc_html_e( 'Once the site is connected to Google Business Profile (Zorderz → Credentials), the home screen shows Google\'s own total instead. This number is shown until then, while Google has not approved access, and on a copy of the site.', 'zorderz' ); ?></p>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_PREFIX . 'google_reviews_confirm' ); ?>" value="1" /> <?php esc_html_e( 'I checked Google today and the number is still right', 'zorderz' ); ?></label>
						</td>
					</tr>
					<?php
					/**
					 * Core services add their own rows to this page (each registers its option in
					 * zdz_core_settings_group). Fires inside the settings table.
					 */
					do_action( 'zdz_core_settings_rows' );
					?>
				</table>
				<?php submit_button(); ?>
			</form>
			<?php
			/** Core services add their own forms after the settings form (a form cannot sit inside another). */
			do_action( 'zdz_core_settings_after' );
			?>
		</div>
		<?php
	}

	/**
	 * Secrets are kept encrypted at rest (the fixed-IV format ZDZ_Core_Crypto reads). WordPress may run a
	 * setting's sanitize callback twice on the first save, so a value that is already sealed is kept as it is
	 * rather than sealed again.
	 */
	public static function seal_secret( string $plain ): string {
		if ( '' === $plain || ! class_exists( 'ZDZ_Core_Crypto' ) ) {
			return $plain;
		}
		if ( ZDZ_Core_Crypto::FIXED === ZDZ_Core_Crypto::decode( $plain )[0] ) {
			return $plain;
		}
		return ZDZ_Core_Crypto::encode( $plain, ZDZ_Core_Crypto::FIXED );
	}

	/**
	 * A credential as the service expects it: decrypted when it was stored encrypted (by exact round trip),
	 * returned as stored when it is plain. A value that cannot be read under this site's key returns ''.
	 */
	public static function get_credential( string $key ): string {
		$val = (string) get_option( self::OPTION_PREFIX . $key, '' );
		if ( '' === $val ) {
			// Upgrade shim: on an install carried across by ZDZ_Rename_Migration, a credential may still sit only
			// under an app's pre-rename option name (copied, not moved, until cleanup). Read it there rather than
			// report the service as unconfigured. A fresh install has none of these and reads the Core option only.
			$fallback_prefixes = [ 'tscc_', 'tsa_', 'tsl_', 'zdz_surveys_', 'tsec_' ];
			foreach ( $fallback_prefixes as $prefix ) {
				$val = (string) get_option( $prefix . $key, '' );
				if ( '' !== $val ) {
					break;
				}
			}
		}
		if ( '' === $val ) {
			return '';
		}
		return class_exists( 'ZDZ_Core_Crypto' ) ? ZDZ_Core_Crypto::plain( $val ) : $val;
	}

	public static function get_google_reviews_count(): int { return (int) get_option( self::OPTION_PREFIX . 'google_reviews_count', 0 ); }

	/** When the Google count was last typed or confirmed (unix); 0 when no date was kept. */
	public static function get_google_reviews_entered_at(): int { return (int) get_option( self::OPTION_PREFIX . 'google_reviews_entered_at', 0 ); }

	/**
	 * The Google count's sanitize step. Keeps the date it was typed: when the number changes, or when the person ticks
	 * "I checked Google today" (options.php has checked the form's nonce before this runs). Saving the page for another
	 * field with the same number leaves the date alone, so the date means "looked at Google", not "saved".
	 */
	public static function sanitize_google_reviews_count( $value ): int {
		$new     = absint( $value );
		$old     = (int) get_option( self::OPTION_PREFIX . 'google_reviews_count', 0 );
		$confirm = ! empty( $_POST[ self::OPTION_PREFIX . 'google_reviews_confirm' ] ); // phpcs:ignore WordPress.Security.NonceVerification -- options.php verified it
		if ( $new !== $old || $confirm ) {
			update_option( self::OPTION_PREFIX . 'google_reviews_entered_at', time(), false );
		}
		return $new;
	}

	/**
	 * A secret field's submitted value: blank keeps what is stored (the field renders empty by design, so a normal Save
	 * never wipes a key nobody retyped and the secret is never echoed to the browser); anything else is sealed at rest.
	 * PURE apart from seal_secret().
	 */
	public static function sanitize_secret_submit( $value, string $stored ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			return $stored;
		}
		return self::seal_secret( sanitize_text_field( $value ) );
	}
	public static function get_website_reviews_count(): int { return (int) get_option( self::OPTION_PREFIX . 'website_reviews_count', 0 ); }

	public static function get_poe_api_key(): string { return self::get_credential( 'poe_api_key' ); }
	public static function get_fb_client_id(): string { return self::get_credential( 'fb_client_id' ); }
	public static function get_fb_client_secret(): string { return self::get_credential( 'fb_client_secret' ); }
	public static function get_fb_access_token(): string { return self::get_credential( 'fb_access_token' ); }
	public static function get_fb_refresh_token(): string { return self::get_credential( 'fb_refresh_token' ); }
	public static function get_fb_account_id(): string { return self::get_credential( 'fb_account_id' ); }
	public static function get_ns_email(): string { return self::get_credential( 'ns_email' ); }
	public static function get_ns_api_key(): string { return self::get_credential( 'ns_api_key' ); }

	/**
	 * The configured AI model/bot name. Single source both the Poe gateway
	 * (ZDZ_Core_Poe) and the Model Registry (ZDZ_Model_Registry::base_model) read.
	 * Reads the credential cascade (see get_credential() for the upgrade shim).
	 * Returns '' when nothing is configured - Core names NO model; the Model
	 * Registry / gateway then resolve the neutral base handle from config
	 * (the zdz_model_base_default filter), so a fresh install is never handed a
	 * retired vendor literal.
	 */
	public static function get_ai_model(): string {
		return self::get_credential( 'ai_model' );
	}

	// Review Bridge credentials (bridge URL is not in the credential
	// cascade — it's only stored under the zdz_core_ prefix).
	public static function get_review_bridge_url(): string {
		return get_option( self::OPTION_PREFIX . 'review_bridge_url', '' );
	}
	public static function get_review_bridge_key(): string {
		// Sealed at rest like every secret field: read through get_credential(), which opens it.
		return self::get_credential( 'review_bridge_key' );
	}

	// Company-level settings getters
	public static function get_company_phone(): string {
		return get_option( 'zdz_company_phone', '' );
	}

	public static function get_receptionist_hours(): string {
		return get_option( 'zdz_receptionist_hours', 'Mon–Fri 9am–5pm' );
	}

	/**
	 * Get the best phone number for a user: their personal number,
	 * falling back to the company phone.
	 */
	public static function get_user_phone( int $user_id ): string {
		$phone = get_user_meta( $user_id, 'zdz_user_phone', true );
		return ! empty( $phone ) ? $phone : self::get_company_phone();
	}

	/**
	 * Get the user's preferred email sign-off name, falling back
	 * to their display_name.
	 */
	public static function get_user_email_name( int $user_id ): string {
		$name = get_user_meta( $user_id, 'zdz_user_email_name', true );
		if ( ! empty( $name ) ) {
			return $name;
		}
		$user = get_userdata( $user_id );
		return $user ? $user->display_name : '';
	}

	/**
	 * Get the user's territory codes as an array.
	 */
	public static function get_user_territories( int $user_id ): array {
		$territories = get_user_meta( $user_id, 'zdz_user_territories', true );
		return is_array( $territories ) ? $territories : [];
	}

	/**
	 * The person's salesperson code: the profile's "Salesperson Code" (the lead generator's match key), else their
	 * party code (ZDZ_Salesperson_Code reads both, and the keys an upgraded install kept them under before).
	 */
	public static function get_salesperson_code( int $user_id ): string {
		if ( class_exists( 'ZDZ_Salesperson_Code' ) ) {
			return ZDZ_Salesperson_Code::get( $user_id );
		}
		return trim( (string) get_user_meta( $user_id, 'zl_salesperson_code', true ) );
	}

	// ── Field Preferences — per-salesperson notation, walkthrough, and estimation habits ──

	/**
	 * Single source of truth for the field preferences schema.
	 *
	 * Every consumer reads this: the POST validation, the prompt block builder,
	 * and the front-end form (via the /field-preferences-schema REST endpoint).
	 * Adding a field here automatically makes it saveable, renderable in the AI
	 * prompt, and editable in the Settings UI. No plugin changes required.
	 *
	 * @return array<string, array{type: string, label: string, hint: string}>
	 */
	public static function get_field_preferences_schema(): array {
		return [
			'measurement_conventions' => [
				'type'  => 'string_array',
				'label' => 'Measurement Conventions',
				'hint'  => 'How you write dimensions. One convention per line. E.g., "× between numbers means Width × Height"',
			],
			'abbreviations' => [
				'type'  => 'string_array',
				'label' => 'Abbreviations',
				'hint'  => 'Personal shorthand. One per line in "ABBR = meaning" format. E.g., "NC = no charge"',
			],
			'tally_system' => [
				'type'  => 'string',
				'label' => 'Tally System',
				'hint'  => 'How you count items (tally marks, circled numbers, etc.)',
			],
			'layout_style' => [
				'type'  => 'string',
				'label' => 'Layout Style',
				'hint'  => 'How you organize notes on paper — boxed unit numbers, section separators, etc.',
			],
			'service_types' => [
				'type'  => 'string_array',
				'label' => 'Service Types',
				'hint'  => 'Your shorthand for service categories. One per line. E.g., "STD = standard service"',
			],
			'walkthrough_pattern' => [
				'type'  => 'string',
				'label' => 'Walkthrough Pattern',
				'hint'  => 'How you physically walk through a job site (front-to-back, clockwise, etc.)',
			],
			'default_behaviors' => [
				'type'  => 'string_array',
				'label' => 'Default Behaviors',
				'hint'  => 'Implicit assumptions you don\'t write down. One per line. E.g., "No type noted = standard grade"',
			],
			'freeform_notes' => [
				'type'  => 'string',
				'label' => 'Freeform Notes',
				'hint'  => 'Anything else the AI should know about how you do your work',
			],
		];
	}

	/**
	 * Get the user's field preferences as a decoded array.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array Decoded JSON preferences, or empty array if none set.
	 */
	public static function get_field_preferences( int $user_id ): array {
		$raw = get_user_meta( $user_id, 'zdz_field_preferences', true );
		if ( empty( $raw ) ) {
			return [];
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Build a formatted text block suitable for injection into an AI system prompt.
	 *
	 * This is the cross-plugin contract: Analytics and Estimates both call this method to get
	 * a ready-to-inject prompt section. The theme owns the formatting; the plugins
	 * just inject the returned string into their system prompts.
	 *
	 * Reads the schema to determine which fields exist and how to label them.
	 * Adding a field to get_field_preferences_schema() automatically includes it
	 * in the prompt output — no changes needed here or in consuming plugins.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string Markdown-formatted preference block, or empty string if none set.
	 */
	public static function get_field_preferences_prompt_block( int $user_id ): string {
		$prefs  = self::get_field_preferences( $user_id );
		if ( empty( $prefs ) ) {
			return '';
		}

		$schema = self::get_field_preferences_schema();
		$user   = get_userdata( $user_id );
		$name   = $user ? $user->display_name : 'Unknown';

		$block = "## SALESPERSON-SPECIFIC FIELD PREFERENCES\n";
		$block .= "**Salesperson:** {$name}\n\n";

		foreach ( $schema as $key => $def ) {
			if ( empty( $prefs[ $key ] ) ) {
				continue;
			}

			$block .= "### {$def['label']}\n";
			if ( 'string_array' === $def['type'] && is_array( $prefs[ $key ] ) ) {
				foreach ( $prefs[ $key ] as $item ) {
					$block .= "- {$item}\n";
				}
			} else {
				$block .= $prefs[ $key ] . "\n";
			}
			$block .= "\n";
		}

		return $block;
	}

	public function migrate_credentials() {
		// Auto-detect and migrate on first activation
		$fields = [ 'poe_api_key', 'fb_client_id', 'fb_client_secret', 'fb_access_token', 'fb_refresh_token', 'fb_account_id', 'ns_email', 'ns_api_key' ];
		foreach ( $fields as $field ) {
			if ( ! get_option( self::OPTION_PREFIX . $field ) ) {
				$val = self::get_credential( $field );
				if ( ! empty( $val ) ) {
					// A secret is kept sealed at rest, like every save through this class.
					update_option( self::OPTION_PREFIX . $field, in_array( $field, self::secret_fields(), true ) ? self::seal_secret( $val ) : $val );
				}
			}
		}
	}
}