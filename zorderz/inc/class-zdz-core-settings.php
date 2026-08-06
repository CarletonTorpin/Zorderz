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

	public function register_settings() {
		$fields = [
			'poe_api_key', 'fb_client_id', 'fb_client_secret', 'fb_access_token',
			'fb_refresh_token', 'fb_account_id', 'ns_email', 'ns_api_key', 'ai_model',
			'review_bridge_url', 'review_bridge_key', // v2.14.5: Review Bridge
		];

		$secret_fields = self::secret_fields();
		foreach ( $fields as $field ) {
			$optname = self::OPTION_PREFIX . $field;
			if ( in_array( $field, $secret_fields, true ) ) {
				register_setting( 'zdz_core_settings_group', $optname, [
					'type'              => 'string',
					'sanitize_callback' => function ( $value ) use ( $optname ) {
						$value = is_string( $value ) ? trim( $value ) : '';
						// Blank submit = keep the existing secret (the field renders empty by design).
						return ( '' === $value ) ? (string) get_option( $optname ) : sanitize_text_field( $value );
					},
				] );
			} else {
				register_setting( 'zdz_core_settings_group', $optname, [
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				] );
			}
		}

		// v2.10.1: Admin-editable review counts (integer fields)
		register_setting( 'zdz_core_settings_group', self::OPTION_PREFIX . 'google_reviews_count', [
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		] );
		register_setting( 'zdz_core_settings_group', self::OPTION_PREFIX . 'website_reviews_count', [
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		] );

		// v2.20.0 r4: Company-level settings for cross-plugin use
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
				
				$fields = [
					'poe_api_key' => 'Poe API Key',
					'fb_client_id' => 'FreshBooks Client ID',
					'fb_client_secret' => 'FreshBooks Client Secret',
					'fb_access_token' => 'FreshBooks Access Token',
					'fb_refresh_token' => 'FreshBooks Refresh Token',
					'fb_account_id' => 'FreshBooks Account ID',
					'ns_email' => 'Nutshell Email',
					'ns_api_key' => 'Nutshell API Key',
					'review_bridge_url' => 'Review Bridge URL',
					'review_bridge_key' => 'Review Bridge API Key',
				];
				?>
				<table class="form-table">
					<?php
					$secret_fields = self::secret_fields();
					foreach ( $fields as $key => $label ) :
						$optname   = self::OPTION_PREFIX . $key;
						$is_secret = in_array( $key, $secret_fields, true );
						$current   = (string) get_option( $optname );
						?>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( $optname ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td>
								<?php if ( $is_secret ) : ?>
									<input type="password" id="<?php echo esc_attr( $optname ); ?>" name="<?php echo esc_attr( $optname ); ?>" value="" autocomplete="off" class="regular-text" placeholder="<?php echo '' !== $current ? esc_attr__( 'Enter a new value to replace', 'zorderz' ) : esc_attr__( 'Not set', 'zorderz' ); ?>" />
									<?php if ( '' !== $current ) : ?>
										<span class="description" style="margin-left:6px"><?php esc_html_e( 'Currently set (hidden). Leave blank to keep it.', 'zorderz' ); ?></span>
									<?php endif; ?>
								<?php else : ?>
									<input type="text" id="<?php echo esc_attr( $optname ); ?>" name="<?php echo esc_attr( $optname ); ?>" value="<?php echo esc_attr( $current ); ?>" class="regular-text" />
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPTION_PREFIX . 'ai_model' ); ?>"><?php esc_html_e( 'AI Model', 'zorderz' ); ?></label></th>
						<td>
							<select id="<?php echo esc_attr( self::OPTION_PREFIX . 'ai_model' ); ?>" name="<?php echo esc_attr( self::OPTION_PREFIX . 'ai_model' ); ?>">
								<?php
								$models = [ 'Gemini-3.1-Pro', 'Claude-Opus-4.6', 'Claude-Sonnet-4.5' ];
								$current = get_option( self::OPTION_PREFIX . 'ai_model', 'Gemini-3.1-Pro' );
								foreach ( $models as $model ) {
									echo '<option value="' . esc_attr( $model ) . '" ' . selected( $current, $model, false ) . '>' . esc_html( $model ) . '</option>';
								}
								?>
							</select>
						</td>
					</tr>
				</table>

				<!-- v2.20.0 r4: Company Contact Settings -->
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

				<h2><?php esc_html_e( 'Dashboard Review Counts', 'zorderz' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Manually enter the current number of reviews on each platform. These counts are displayed on the KPI dashboard.', 'zorderz' ); ?></p>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPTION_PREFIX . 'google_reviews_count' ); ?>"><?php esc_html_e( 'Google Reviews Count', 'zorderz' ); ?></label></th>
						<td>
							<input type="number" id="<?php echo esc_attr( self::OPTION_PREFIX . 'google_reviews_count' ); ?>" name="<?php echo esc_attr( self::OPTION_PREFIX . 'google_reviews_count' ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_PREFIX . 'google_reviews_count', 0 ) ); ?>" class="small-text" min="0" step="1" />
							<p class="description"><?php esc_html_e( 'Total number of Google Reviews for Zorderz.', 'zorderz' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPTION_PREFIX . 'website_reviews_count' ); ?>"><?php esc_html_e( 'Website Reviews Count (Ovation)', 'zorderz' ); ?></label></th>
						<td>
							<input type="number" id="<?php echo esc_attr( self::OPTION_PREFIX . 'website_reviews_count' ); ?>" name="<?php echo esc_attr( self::OPTION_PREFIX . 'website_reviews_count' ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_PREFIX . 'website_reviews_count', 0 ) ); ?>" class="small-text" min="0" step="1" />
							<p class="description"><?php esc_html_e( 'Total number of reviews on the Thrive Ovation review page.', 'zorderz' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public static function get_credential( string $key ): string {
		$val = get_option( self::OPTION_PREFIX . $key, '' );
		if ( ! empty( $val ) ) {
			return $val;
		}

		$fallback_prefixes = [ 'tscc_', 'tsa_', 'tsl_', 'zdz_surveys_', 'tsec_' ];
		foreach ( $fallback_prefixes as $prefix ) {
			$val = get_option( $prefix . $key, '' );
			if ( ! empty( $val ) ) {
				return $val;
			}
		}

		return '';
	}

	public static function get_google_reviews_count(): int { return (int) get_option( self::OPTION_PREFIX . 'google_reviews_count', 0 ); }
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
	 * Reads the credential cascade (honours a pre-rename tsa_ai_model on upgrade)
	 * and defaults to the same value the Settings UI shows when nothing is set.
	 */
	public static function get_ai_model(): string {
		$model = self::get_credential( 'ai_model' );
		return '' !== $model ? $model : 'Gemini-3.1-Pro';
	}

	// v2.14.5: Review Bridge credentials (bridge URL is not in the credential
	// cascade — it's only stored under the zdz_core_ prefix).
	public static function get_review_bridge_url(): string {
		return get_option( self::OPTION_PREFIX . 'review_bridge_url', '' );
	}
	public static function get_review_bridge_key(): string {
		return get_option( self::OPTION_PREFIX . 'review_bridge_key', '' );
	}

	// v2.20.0 r4: Company-level settings getters
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
	 * Get the user's salesperson code (for lead generator matching).
	 */
	public static function get_salesperson_code( int $user_id ): string {
		return (string) get_user_meta( $user_id, 'tsl_salesperson_code', true );
	}

	// ── v2.20.2: Field Preferences — per-salesperson notation, walkthrough, and estimation habits ──

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
				'hint'  => 'Personal shorthand. One per line in "ABBR = meaning" format. E.g., "FD/SD = Front Door Screen Door"',
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
				'hint'  => 'Your shorthand for service categories. One per line. E.g., "RIP = rescreen in place, small windows only"',
			],
			'walkthrough_pattern' => [
				'type'  => 'string',
				'label' => 'Walkthrough Pattern',
				'hint'  => 'How you physically walk through a job site (front-to-back, clockwise, etc.)',
			],
			'default_behaviors' => [
				'type'  => 'string_array',
				'label' => 'Default Behaviors',
				'hint'  => 'Implicit assumptions you don\'t write down. One per line. E.g., "No mesh type noted = 18/16 standard"',
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
	 * This is the cross-plugin contract: TSA and TSEC both call this method to get
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
					update_option( self::OPTION_PREFIX . $field, $val );
				}
			}
		}
	}
}