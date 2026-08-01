<?php
/**
 * Zorderz — Business Profile admin screens
 *
 * Two screens under the Zorderz menu:
 *
 *   Business Profile — the form where a business says who it is. Every field here
 *   replaces something that used to be a hardcoded string in PHP.
 *
 *   Identity Pack — load a business's identity from files instead of typing it.
 *   Preview shows the actual diff; applying requires a typed confirmation; a
 *   snapshot is taken first so it can be reverted.
 *
 * WHY THE APPLY FLOW IS THIS DELIBERATE
 * The previous architecture seeded one company's business data into every fresh
 * install on activation, silently. Nobody chose it and nothing recorded it. So
 * here: nothing is written without a preview, a typed confirmation, a snapshot,
 * and a log entry. That is not ceremony — it is the specific defect being fixed.
 *
 * @package Zorderz
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Business_Profile_Admin {

	const PARENT = 'zdz-core-settings';
	const SLUG   = 'zdz-business-profile';
	const PACK   = 'zdz-identity-pack';

	/** Notices to render on the next page load of this request. */
	private static $notices = [];

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_pages' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle_post' ] );
	}

	public static function add_pages() {
		add_submenu_page(
			self::PARENT,
			__( 'Business Profile', 'zorderz' ),
			__( 'Business Profile', 'zorderz' ),
			'manage_options',
			self::SLUG,
			[ __CLASS__, 'render_profile' ]
		);
		add_submenu_page(
			self::PARENT,
			__( 'Identity Pack', 'zorderz' ),
			__( 'Identity Pack', 'zorderz' ),
			'manage_options',
			self::PACK,
			[ __CLASS__, 'render_pack' ]
		);
	}

	/**
	 * The editable field map: profile dot-path => [label, type, help].
	 *
	 * Grouped for rendering, but the paths are the authority — the form posts dot
	 * paths, so adding a field here is the only change needed to expose it.
	 */
	public static function fields() {
		return [
			'identity' => [
				'label'  => __( 'Identity', 'zorderz' ),
				'intro'  => __( 'Anything left blank falls back to this site\'s own title and tagline, so a fresh install is still coherent.', 'zorderz' ),
				'fields' => [
					'identity.trading_name' => [ __( 'Business name', 'zorderz' ), 'text', __( 'The name customers know. Used everywhere the app names the business.', 'zorderz' ) ],
					'identity.legal_name'   => [ __( 'Legal name', 'zorderz' ), 'text', __( 'For contracts and documents, if it differs.', 'zorderz' ) ],
					'identity.short_name'   => [ __( 'Short name', 'zorderz' ), 'text', __( 'For tight spaces and the home-screen icon label.', 'zorderz' ) ],
					'identity.short_code'   => [ __( 'Short code', 'zorderz' ), 'text', __( 'An internal abbreviation, e.g. on paperwork. Reserved so it can never be read as a person\'s initials.', 'zorderz' ) ],
					'identity.tagline'      => [ __( 'Tagline', 'zorderz' ), 'text', '' ],
					'identity.industry'     => [ __( 'Industry', 'zorderz' ), 'text', __( 'Plain words, e.g. "screen enclosure installation". Used to describe the business, not to categorise it.', 'zorderz' ) ],
					'identity.license'      => [ __( 'Licence line', 'zorderz' ), 'text', __( 'Rendered as-is where a registration number is shown.', 'zorderz' ) ],
					'identity.former_names' => [ __( 'Former names', 'zorderz' ), 'list', __( 'One per line. Shown as "formerly …" where old paperwork may not match.', 'zorderz' ) ],
				],
			],
			'contact' => [
				'label'  => __( 'Contact', 'zorderz' ),
				'fields' => [
					'contact.phone'            => [ __( 'Main phone', 'zorderz' ), 'text', '' ],
					'contact.phone_alt'        => [ __( 'Alternate phone', 'zorderz' ), 'text', '' ],
					'contact.email'            => [ __( 'Contact email', 'zorderz' ), 'email', __( 'Falls back to the WordPress admin email.', 'zorderz' ) ],
					'contact.hours'            => [ __( 'Office hours', 'zorderz' ), 'text', __( 'As you\'d write them to a customer.', 'zorderz' ) ],
					'contact.address.street'   => [ __( 'Street', 'zorderz' ), 'text', '' ],
					'contact.address.locality' => [ __( 'City', 'zorderz' ), 'text', '' ],
					'contact.address.region'   => [ __( 'State / region', 'zorderz' ), 'text', '' ],
					'contact.address.postal'   => [ __( 'Postal code', 'zorderz' ), 'text', '' ],
					'contact.address.country'  => [ __( 'Country', 'zorderz' ), 'text', '' ],
				],
			],
			'web' => [
				'label'  => __( 'Web', 'zorderz' ),
				'fields' => [
					'web.app_domain'       => [ __( 'App domain', 'zorderz' ), 'text', __( 'Where this app lives. Falls back to this site\'s host.', 'zorderz' ) ],
					'web.marketing_domain' => [ __( 'Marketing domain', 'zorderz' ), 'text', __( 'The public website, if it is a different host.', 'zorderz' ) ],
					'web.review_google'    => [ __( 'Google review link', 'zorderz' ), 'url', '' ],
					'web.review_page'      => [ __( 'Own review page', 'zorderz' ), 'url', '' ],
				],
			],
			'senders' => [
				'label'  => __( 'Outgoing mail', 'zorderz' ),
				'intro'  => __( 'Who mail appears to come from. Leave a purpose blank and it inherits the default; leave the default blank and it inherits WordPress. This exists because per-plugin hardcoded senders once put one employee\'s name on an entire category of company mail.', 'zorderz' ),
				'fields' => [
					'senders.default.name'    => [ __( 'Default from-name', 'zorderz' ), 'text', '' ],
					'senders.default.email'   => [ __( 'Default from-address', 'zorderz' ), 'email', '' ],
					'senders.alerts.name'     => [ __( 'Alerts from-name', 'zorderz' ), 'text', '' ],
					'senders.alerts.email'    => [ __( 'Alerts from-address', 'zorderz' ), 'email', '' ],
					'senders.surveys.name'    => [ __( 'Surveys from-name', 'zorderz' ), 'text', '' ],
					'senders.surveys.email'   => [ __( 'Surveys from-address', 'zorderz' ), 'email', '' ],
					'senders.messaging.name'  => [ __( 'Messaging from-name', 'zorderz' ), 'text', '' ],
					'senders.messaging.email' => [ __( 'Messaging from-address', 'zorderz' ), 'email', '' ],
					'senders.documents.name'  => [ __( 'Documents from-name', 'zorderz' ), 'text', '' ],
					'senders.documents.email' => [ __( 'Documents from-address', 'zorderz' ), 'email', '' ],
				],
			],
			'locale' => [
				'label'  => __( 'Locale', 'zorderz' ),
				'fields' => [
					'locale.timezone'      => [ __( 'Timezone', 'zorderz' ), 'text', __( 'Falls back to the WordPress timezone.', 'zorderz' ) ],
					'locale.currency'      => [ __( 'Currency code', 'zorderz' ), 'text', '' ],
					'locale.currency_sign' => [ __( 'Currency symbol', 'zorderz' ), 'text', '' ],
					'locale.date_format'   => [ __( 'Date format', 'zorderz' ), 'text', __( 'PHP date format, e.g. n/j/Y.', 'zorderz' ) ],
				],
			],
			'people' => [
				'label'  => __( 'People', 'zorderz' ),
				'fields' => [
					'people.staff_email_pattern' => [ __( 'Staff email pattern', 'zorderz' ), 'text', __( 'e.g. {first}@example.com. Used to suggest an address, never to assume one.', 'zorderz' ) ],
				],
			],
			'logo' => [
				'label'  => __( 'Logo artwork', 'zorderz' ),
				'intro'  => __( 'Two shapes, each drawn in two ink colours. Paste the URL of an uploaded PNG — Media → Add New, then copy the file URL. "Dark ink" means the artwork itself is dark, so it goes on light surfaces; "light ink" is the pale or white version for dark surfaces like the topbar. Most brands need both, because a navy wordmark disappears on a navy topbar. Supplying only one of anything is fine — the app falls back in a declared order and lays out whatever it actually found rather than stretching it.', 'zorderz' ),
				'fields' => [
					'brand.logo.wide.dark'    => [ __( 'Wide 2:1 — dark ink', 'zorderz' ), 'url', __( 'The main one. Your lockup — wordmark, or mark plus words — in its normal colours, for light surfaces: the login screen, document headers, email headers.', 'zorderz' ) ],
					'brand.logo.wide.light'   => [ __( 'Wide 2:1 — light ink', 'zorderz' ), 'url', __( 'The same lockup in white or pale, for the topbar and other dark surfaces.', 'zorderz' ) ],
					'brand.logo.square.dark'  => [ __( 'Square 1:1 — dark ink', 'zorderz' ), 'url', __( 'The mark alone, in its normal colours. Used for the home-screen icon, the favicon, avatars, and any tight square slot.', 'zorderz' ) ],
					'brand.logo.square.light' => [ __( 'Square 1:1 — light ink', 'zorderz' ), 'url', __( 'The mark in white or pale, for square slots on dark surfaces.', 'zorderz' ) ],
					'brand.logo.favicon'      => [ __( 'Favicon', 'zorderz' ), 'url', __( 'Optional. Falls back to the square mark.', 'zorderz' ) ],
				],
			],
		];
	}

	// ─────────────────────────────────────────────────── POST handling

	public static function handle_post() {
		if ( ! isset( $_POST['zdz_bp_action'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change the business profile.', 'zorderz' ) );
		}
		$action = sanitize_key( wp_unslash( $_POST['zdz_bp_action'] ) );
		check_admin_referer( 'zdz_bp_' . $action );

		switch ( $action ) {
			case 'save_profile':
				self::save_profile();
				break;
			case 'save_brand':
				self::save_brand();
				break;
			case 'apply_pack':
				self::apply_pack();
				break;
			case 'revert_pack':
				$r = ZDZ_Identity_Pack::revert();
				self::notice( implode( ' ', $r['messages'] ), $r['ok'] ? 'success' : 'error' );
				break;
			case 'reset_profile':
				delete_option( ZDZ_Business_Profile::OPTION );
				self::notice( __( 'Business profile cleared. Every value is back to the neutral default.', 'zorderz' ), 'success' );
				break;
		}
	}

	private static function save_profile() {
		$posted = isset( $_POST['zdz_bp'] ) && is_array( $_POST['zdz_bp'] ) ? wp_unslash( $_POST['zdz_bp'] ) : [];
		$values = [];
		$count  = 0;

		foreach ( self::fields() as $group ) {
			foreach ( $group['fields'] as $path => $def ) {
				$key = self::form_key( $path );
				if ( ! array_key_exists( $key, $posted ) ) {
					continue;
				}
				$raw = $posted[ $key ];
				switch ( $def[1] ) {
					case 'email':
						$clean = '' === trim( (string) $raw ) ? '' : sanitize_email( (string) $raw );
						break;
					case 'url':
						$clean = '' === trim( (string) $raw ) ? '' : esc_url_raw( (string) $raw );
						break;
					case 'list':
						// One per line, blanks dropped. A list replaces wholesale
						// rather than merging, so removing an entry actually removes it.
						$clean = array_values( array_filter( array_map(
							'sanitize_text_field',
							preg_split( '/\r\n|\r|\n/', (string) $raw )
						), fn( $v ) => '' !== trim( $v ) ) );
						break;
					default:
						$clean = sanitize_text_field( (string) $raw );
				}
				self::plant( $values, $path, $clean );
				$count++;
			}
		}

		ZDZ_Business_Profile::set( $values, 'manual' );
		/* translators: %d: number of fields saved. */
		self::notice( sprintf( _n( '%d field saved.', '%d fields saved.', $count, 'zorderz' ), $count ), 'success' );
	}

	private static function save_brand() {
		$posted   = isset( $_POST['zdz_brand'] ) && is_array( $_POST['zdz_brand'] ) ? wp_unslash( $_POST['zdz_brand'] ) : [];
		$defaults = ZDZ_Business_Profile::defaults()['brand'];
		$values   = [];
		$bad      = [];

		foreach ( [ 'ramp', 'categories' ] as $group ) {
			foreach ( array_keys( $defaults[ $group ] ) as $key ) {
				$form = $group . '_' . $key;
				if ( ! array_key_exists( $form, $posted ) ) {
					continue;
				}
				$hex = strtoupper( trim( (string) $posted[ $form ] ) );
				if ( '' === $hex ) {
					$hex = $defaults[ $group ][ $key ];   // blank means "back to default"
				}
				if ( ! preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', $hex ) ) {
					$bad[] = $group . '.' . $key;
					continue;
				}
				$values['brand'][ $group ][ $key ] = $hex;
			}
		}

		if ( $bad ) {
			self::notice(
				sprintf(
					/* translators: %s: comma-separated list of field names. */
					__( 'Not saved — these are not valid hex colours: %s', 'zorderz' ),
					implode( ', ', $bad )
				),
				'error'
			);
			return;
		}
		ZDZ_Business_Profile::set( $values, 'manual' );
		self::notice( __( 'Palette saved. Reload the app to see it.', 'zorderz' ), 'success' );
	}

	private static function apply_pack() {
		$slug    = isset( $_POST['pack'] ) ? sanitize_key( wp_unslash( $_POST['pack'] ) ) : '';
		$typed   = isset( $_POST['confirm'] ) ? trim( (string) wp_unslash( $_POST['confirm'] ) ) : '';
		$expect  = 'APPLY';

		if ( 0 !== strcasecmp( $typed, $expect ) ) {
			self::notice(
				sprintf(
					/* translators: %s: the word the user must type, e.g. APPLY. */
					__( 'Nothing was applied. Type %s in the confirmation box to proceed.', 'zorderz' ),
					'<code>' . esc_html( $expect ) . '</code>'
				),
				'warning'
			);
			return;
		}
		$r = ZDZ_Identity_Pack::apply( $slug, true );
		self::notice(
			esc_html( $r['ok'] ? __( 'Pack applied.', 'zorderz' ) : __( 'Pack not applied.', 'zorderz' ) )
				. '<br>' . esc_html( implode( ' ', $r['messages'] ) ),
			$r['ok'] ? 'success' : 'error'
		);
	}

	// ─────────────────────────────────────────────────── rendering

	public static function render_profile() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$applied = ZDZ_Identity_Pack::applied();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Business Profile', 'zorderz' ); ?></h1>
			<p style="max-width:46em">
				<?php esc_html_e( 'This is where Zorderz learns who it is working for. Every field here replaces something that used to be typed into the code — a name in a page title, an address in an email footer, a colour in a stylesheet.', 'zorderz' ); ?>
			</p>
			<?php self::render_notices(); ?>
			<?php if ( $applied ) : ?>
				<div class="notice notice-info inline">
					<p>
						<?php
						printf(
							/* translators: 1: pack label, 2: date applied. */
							esc_html__( 'Identity Pack %1$s was applied on %2$s. Editing a field here overrides that value; the pack itself is untouched.', 'zorderz' ),
							'<strong>' . esc_html( $applied['label'] ) . '</strong>',
							esc_html( $applied['applied_at'] )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'zdz_bp_save_profile' ); ?>
				<input type="hidden" name="zdz_bp_action" value="save_profile">
				<?php foreach ( self::fields() as $group ) : ?>
					<h2><?php echo esc_html( $group['label'] ); ?></h2>
					<?php if ( ! empty( $group['intro'] ) ) : ?>
						<p class="description" style="max-width:46em"><?php echo esc_html( $group['intro'] ); ?></p>
					<?php endif; ?>
					<table class="form-table" role="presentation"><tbody>
					<?php foreach ( $group['fields'] as $path => $def ) : ?>
						<?php
						list( $label, $type, $help ) = $def;
						$stored   = self::stored_value( $path );
						$resolved = ZDZ_Business_Profile::get( $path, '' );
						$name     = 'zdz_bp[' . self::form_key( $path ) . ']';
						?>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( self::form_key( $path ) ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td>
								<?php if ( 'list' === $type ) : ?>
									<textarea id="<?php echo esc_attr( self::form_key( $path ) ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="3" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $stored ) ); ?></textarea>
								<?php else : ?>
									<input type="text" id="<?php echo esc_attr( self::form_key( $path ) ); ?>" name="<?php echo esc_attr( $name ); ?>"
										value="<?php echo esc_attr( is_array( $stored ) ? '' : (string) $stored ); ?>" class="regular-text"
										<?php if ( '' === (string) ( is_array( $stored ) ? '' : $stored ) && ! is_array( $resolved ) && '' !== (string) $resolved ) : ?>
											placeholder="<?php echo esc_attr( (string) $resolved ); ?>"
										<?php endif; ?>>
								<?php endif; ?>
								<?php if ( $help ) : ?>
									<p class="description"><?php echo esc_html( $help ); ?></p>
								<?php endif; ?>
								<?php
								if ( str_starts_with( $path, 'brand.logo.' ) && is_string( $stored ) && '' !== $stored ) {
									self::render_logo_note( $path, $stored );
								}
								// Show the inherited value when the field is empty, so it is
								// obvious that blank means "inherit" and not "nothing".
								if ( ! is_array( $stored ) && '' === (string) $stored && ! is_array( $resolved ) && '' !== (string) $resolved ) :
									?>
									<p class="description" style="color:#646970">
										<?php
										printf(
											/* translators: %s: the value currently being inherited. */
											esc_html__( 'Currently inheriting: %s', 'zorderz' ),
											'<code>' . esc_html( (string) $resolved ) . '</code>'
										);
										?>
									</p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody></table>
				<?php endforeach; ?>
				<?php self::render_logo_resolution(); ?>
				<?php submit_button( __( 'Save Business Profile', 'zorderz' ) ); ?>
			</form>

			<hr>
			<?php self::render_brand_form(); ?>

			<hr>
			<h2><?php esc_html_e( 'Start over', 'zorderz' ); ?></h2>
			<p class="description" style="max-width:46em">
				<?php esc_html_e( 'Clears every stored value and returns to the neutral defaults. It does not touch roles, users, or app data.', 'zorderz' ); ?>
			</p>
			<form method="post" action="">
				<?php wp_nonce_field( 'zdz_bp_reset_profile' ); ?>
				<input type="hidden" name="zdz_bp_action" value="reset_profile">
				<?php submit_button( __( 'Clear the profile', 'zorderz' ), 'delete', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * A thumbnail and an honest note under a logo field.
	 *
	 * The note WARNS about an off-ratio image; it never rejects one. The business
	 * owns its artwork, and a 2.3:1 lockup is a design decision, not an error —
	 * it just gets letterboxed rather than stretched, and the person uploading it
	 * deserves to know that before they see it in the topbar.
	 *
	 * Nothing can be measured for an off-site URL, so nothing is claimed about it.
	 */
	private static function render_logo_note( $path, $url ) {
		printf(
			'<p style="margin:.5em 0"><img src="%s" alt="" style="max-height:44px;max-width:220px;object-fit:contain;background:#f0f0f1;border:1px solid #dcdcde;padding:4px"></p>',
			esc_url( $url )
		);

		$slot = str_contains( $path, '.wide.' ) ? 'wide' : ( str_contains( $path, '.square.' ) ? 'square' : '' );
		if ( '' === $slot ) {
			return;
		}
		$m = ZDZ_Business_Profile::measure_logo( $url );
		if ( null === $m ) {
			printf(
				'<p class="description" style="color:#646970">%s</p>',
				esc_html__( 'Hosted elsewhere, so its proportions cannot be checked from here.', 'zorderz' )
			);
			return;
		}
		$want = ZDZ_Business_Profile::LOGO_SHAPES[ $slot ];
		$off  = abs( $m['ratio'] - $want ) > ( 'wide' === $slot ? 0.25 : 0.08 );
		printf(
			'<p class="description" style="color:%s">%s</p>',
			$off ? '#996800' : '#646970',
			esc_html(
				$off
					? sprintf(
						/* translators: 1: width, 2: height, 3: actual ratio, 4: expected ratio. */
						__( '%1$d×%2$d — that is %3$s:1, not the %4$s:1 this slot expects. It will be fitted inside the space with room left over rather than stretched, which usually looks fine but may not be what you drew.', 'zorderz' ),
						$m['w'], $m['h'], number_format( $m['ratio'], 2 ), number_format( $want, 0 )
					)
					: sprintf(
						/* translators: 1: width, 2: height. */
						__( '%1$d×%2$d — the right proportions for this slot.', 'zorderz' ),
						$m['w'], $m['h']
					)
			)
		);
	}

	/**
	 * What the app will actually put where, given the artwork on hand.
	 *
	 * Worth showing plainly. The fallback chain is the part people get surprised
	 * by — "I uploaded a logo, why is my topbar showing a square?" — and a table
	 * answers that better than documentation does.
	 */
	private static function render_logo_resolution() {
		$slots = [
			[ 'wide', 'light', __( 'Login screen, document and email headers', 'zorderz' ) ],
			[ 'wide', 'dark', __( 'Topbar and other dark surfaces', 'zorderz' ) ],
			[ 'square', 'light', __( 'Home-screen icon, favicon, avatars', 'zorderz' ) ],
			[ 'square', 'dark', __( 'Square slots on dark surfaces', 'zorderz' ) ],
		];
		?>
		<h3><?php esc_html_e( 'What gets used where', 'zorderz' ); ?></h3>
		<table class="widefat striped" style="max-width:64em"><thead><tr>
			<th style="width:26%"><?php esc_html_e( 'Where it appears', 'zorderz' ); ?></th>
			<th style="width:18%"><?php esc_html_e( 'Wants', 'zorderz' ); ?></th>
			<th><?php esc_html_e( 'What the app will actually use', 'zorderz' ); ?></th>
		</tr></thead><tbody>
		<?php foreach ( $slots as $s ) : ?>
			<?php
			$r    = ZDZ_Business_Profile::logo( $s[0], $s[1] );
			$want = $s[0] . ', ' . ( 'dark' === $s[1] ? __( 'light ink', 'zorderz' ) : __( 'dark ink', 'zorderz' ) );
			$got  = $r['shape'] . ', ' . ( 'light' === $r['ink'] ? __( 'light ink', 'zorderz' ) : __( 'dark ink', 'zorderz' ) );
			?>
			<tr>
				<td><?php echo esc_html( $s[2] ); ?></td>
				<td><code><?php echo esc_html( $want ); ?></code></td>
				<td>
					<?php if ( '' === $r['url'] ) : ?>
						<em><?php esc_html_e( 'the business name, as text', 'zorderz' ); ?></em>
					<?php elseif ( $r['exact'] ) : ?>
						<span style="color:#00622e">✓ <?php esc_html_e( 'exactly what it wants', 'zorderz' ); ?></span>
					<?php else : ?>
						<span style="color:#996800">
							<?php
							printf(
								/* translators: %s: the artwork actually used, e.g. "square, dark ink". */
								esc_html__( 'falling back to %s', 'zorderz' ),
								'<code>' . esc_html( $got ) . '</code>'
							);
							?>
						</span>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody></table>
		<p class="description" style="max-width:46em">
			<?php esc_html_e( 'Fallback order: the exact slot, then the same shape in the other ink, then the other shape in the right ink, then whatever exists, then the business name as text. A square standing in for a wide slot is laid out as a square and centred — never stretched to fill it. The home-screen icon is the one exception: it takes a square or nothing, because a wordmark squashed into a launcher tile looks worse than the default icon.', 'zorderz' ); ?>
		</p>
		<?php
	}

	private static function render_brand_form() {
		$defaults = ZDZ_Business_Profile::defaults()['brand'];
		$groups   = [
			'ramp'       => [
				__( 'Brand ramp', 'zorderz' ),
				__( 'Eleven steps from lightest to darkest. Every themed colour in the app is derived from these, in all four display modes — so replacing them re-skins the whole interface without touching CSS. 500 is the main brand colour and 600 is its hover and chrome shade.', 'zorderz' ),
			],
			'categories' => [
				__( 'App categories', 'zorderz' ),
				__( 'The dashboard tile colours. Change these only if your business has its own colour language.', 'zorderz' ),
			],
		];
		?>
		<h2><?php esc_html_e( 'Appearance', 'zorderz' ); ?></h2>
		<p class="description" style="max-width:46em">
			<?php esc_html_e( 'Leave a swatch blank to return it to the shipped default. Only colours you actually change are emitted, so an untouched palette costs nothing.', 'zorderz' ); ?>
		</p>
		<form method="post" action="">
			<?php wp_nonce_field( 'zdz_bp_save_brand' ); ?>
			<input type="hidden" name="zdz_bp_action" value="save_brand">
			<?php foreach ( $groups as $group => $meta ) : ?>
				<h3><?php echo esc_html( $meta[0] ); ?></h3>
				<p class="description" style="max-width:46em"><?php echo esc_html( $meta[1] ); ?></p>
				<p>
				<?php foreach ( $defaults[ $group ] as $key => $default_hex ) : ?>
					<?php
					$current = (string) ZDZ_Business_Profile::get( "brand.$group.$key", $default_hex );
					$changed = 0 !== strcasecmp( $current, $default_hex );
					?>
					<label style="display:inline-block;margin:0 1.25em 1em 0;text-align:center;font-size:11px">
						<input type="color" name="<?php echo esc_attr( 'zdz_brand[' . $group . '_' . $key . ']' ); ?>"
							value="<?php echo esc_attr( $current ); ?>"
							style="display:block;width:64px;height:34px;padding:0;border:1px solid #8c8f94;cursor:pointer">
						<span style="display:block;margin-top:4px"><?php echo esc_html( $key ); ?></span>
						<span style="display:block;color:<?php echo $changed ? '#996800' : '#646970'; ?>">
							<?php echo $changed ? esc_html__( 'changed', 'zorderz' ) : esc_html( $default_hex ); ?>
						</span>
					</label>
				<?php endforeach; ?>
				</p>
			<?php endforeach; ?>
			<?php submit_button( __( 'Save palette', 'zorderz' ) ); ?>
		</form>
		<?php
	}

	public static function render_pack() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$packs   = ZDZ_Identity_Pack::discover();
		$applied = ZDZ_Identity_Pack::applied();
		$preview = isset( $_GET['preview'] ) ? sanitize_key( wp_unslash( $_GET['preview'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Identity Pack', 'zorderz' ); ?></h1>
			<p style="max-width:46em">
				<?php esc_html_e( 'An Identity Pack is a business as data — names, brand, contact details, role labels — in plain files you can read, review and version. Applying one to a stock Zorderz install turns it into that business\'s app. Nothing is ever applied on its own; you preview the exact changes first.', 'zorderz' ); ?>
			</p>
			<?php self::render_notices(); ?>

			<?php if ( $applied ) : ?>
				<div class="notice notice-info inline">
					<p>
						<?php
						printf(
							/* translators: 1: pack label, 2: timestamp. */
							esc_html__( 'Currently applied: %1$s (%2$s).', 'zorderz' ),
							'<strong>' . esc_html( $applied['label'] ) . '</strong>',
							esc_html( $applied['applied_at'] )
						);
						?>
					</p>
					<form method="post" action="" style="margin-bottom:1em">
						<?php wp_nonce_field( 'zdz_bp_revert_pack' ); ?>
						<input type="hidden" name="zdz_bp_action" value="revert_pack">
						<?php submit_button( __( 'Revert to the snapshot taken before it was applied', 'zorderz' ), 'secondary', 'submit', false ); ?>
					</form>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Available packs', 'zorderz' ); ?></h2>
			<?php if ( ! $packs ) : ?>
				<p><?php esc_html_e( 'No packs found. Put one in either of these folders and reload:', 'zorderz' ); ?></p>
				<ul class="ul-disc">
					<?php foreach ( ZDZ_Identity_Pack::search_paths() as $path ) : ?>
						<li><code><?php echo esc_html( $path . '/<your-business>/' ); ?></code></li>
					<?php endforeach; ?>
				</ul>
				<p class="description">
					<?php esc_html_e( 'A pack is a folder of .yml or .json files. Only profile and org are read by this version; anything else is reported rather than ignored.', 'zorderz' ); ?>
				</p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:60em">
					<thead><tr>
						<th><?php esc_html_e( 'Pack', 'zorderz' ); ?></th>
						<th><?php esc_html_e( 'Files', 'zorderz' ); ?></th>
						<th><?php esc_html_e( 'Readable now', 'zorderz' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $packs as $slug => $pack ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $pack['label'] ); ?></strong><br>
								<code><?php echo esc_html( $slug ); ?></code>
							</td>
							<td><?php echo esc_html( implode( ', ', $pack['files'] ) ); ?></td>
							<td>
								<?php echo $pack['supported'] ? esc_html( implode( ', ', $pack['supported'] ) ) : '<em>' . esc_html__( 'nothing this version can read', 'zorderz' ) . '</em>'; ?>
							</td>
							<td>
								<a class="button" href="<?php echo esc_url( add_query_arg( [ 'page' => self::PACK, 'preview' => $slug ], admin_url( 'admin.php' ) ) ); ?>">
									<?php esc_html_e( 'Preview changes', 'zorderz' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( $preview && isset( $packs[ $preview ] ) ) : ?>
				<?php self::render_preview( $preview ); ?>
			<?php elseif ( $preview ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'That pack no longer exists.', 'zorderz' ); ?></p></div>
			<?php endif; ?>

			<?php self::render_log(); ?>
		</div>
		<?php
	}

	private static function render_preview( $slug ) {
		$p = ZDZ_Identity_Pack::preview( $slug );
		?>
		<hr>
		<h2>
			<?php
			printf(
				/* translators: %s: pack label. */
				esc_html__( 'What applying %s would change', 'zorderz' ),
				esc_html( $p['label'] )
			);
			?>
		</h2>

		<?php foreach ( $p['errors'] as $err ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $err ); ?></p></div>
		<?php endforeach; ?>

		<?php if ( ! $p['changes'] ) : ?>
			<p><?php esc_html_e( 'Nothing would change — every value in this pack already matches what is stored.', 'zorderz' ); ?></p>
		<?php else : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %d: number of changes. */
					esc_html( _n( '%d value would change.', '%d values would change.', count( $p['changes'] ), 'zorderz' ) ),
					count( $p['changes'] )
				);
				?>
			</p>
			<table class="widefat striped" style="max-width:80em">
				<thead><tr>
					<th style="width:8%"><?php esc_html_e( 'File', 'zorderz' ); ?></th>
					<th style="width:26%"><?php esc_html_e( 'Setting', 'zorderz' ); ?></th>
					<th style="width:33%"><?php esc_html_e( 'Now', 'zorderz' ); ?></th>
					<th style="width:33%"><?php esc_html_e( 'Would become', 'zorderz' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $p['changes'] as $c ) : ?>
					<tr>
						<td><?php echo esc_html( $c['file'] ); ?></td>
						<td><code><?php echo esc_html( $c['path'] ); ?></code></td>
						<td><?php echo '' === $c['from'] ? '<em style="color:#646970">' . esc_html__( '(empty)', 'zorderz' ) . '</em>' : esc_html( $c['from'] ); ?></td>
						<td><strong><?php echo esc_html( $c['to'] ); ?></strong></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( ! empty( $p['unsupported'] ) ) : ?>
			<h3><?php esc_html_e( 'In the pack, but not readable by this version', 'zorderz' ); ?></h3>
			<p><?php echo esc_html( implode( ', ', $p['unsupported'] ) ); ?></p>
			<p class="description" style="max-width:46em">
				<?php esc_html_e( 'These files stay exactly as they are. Later versions read catalog, pricing, compensation and the rest; this one reads identity and org shape only.', 'zorderz' ); ?>
			</p>
		<?php endif; ?>

		<?php if ( ! empty( $p['unconsumed'] ) ) : ?>
			<h3><?php esc_html_e( 'Carried in the profile, but not used yet', 'zorderz' ); ?></h3>
			<p><code><?php echo esc_html( implode( ' · ', $p['unconsumed'] ) ); ?></code></p>
			<p class="description" style="max-width:46em">
				<?php esc_html_e( 'Values this pack declares that no part of this version reads. Listed so it is never a surprise which parts of a pack took effect.', 'zorderz' ); ?>
			</p>
		<?php endif; ?>

		<?php if ( $p['changes'] ) : ?>
			<h3><?php esc_html_e( 'Apply it', 'zorderz' ); ?></h3>
			<div style="background:#fff;border-left:4px solid #dba617;padding:12px 16px;max-width:46em">
				<p style="margin-top:0">
					<?php esc_html_e( 'A snapshot of the current profile and role labels is taken first, so this can be reverted. Roles themselves are not created, renamed or removed — a pack may relabel a role and set which apps it opens with, nothing more.', 'zorderz' ); ?>
				</p>
				<form method="post" action="">
					<?php wp_nonce_field( 'zdz_bp_apply_pack' ); ?>
					<input type="hidden" name="zdz_bp_action" value="apply_pack">
					<input type="hidden" name="pack" value="<?php echo esc_attr( $p['pack'] ); ?>">
					<p>
						<label for="zdz-bp-confirm">
							<?php
							printf(
								/* translators: %s: the confirmation word, APPLY. */
								esc_html__( 'Type %s to confirm:', 'zorderz' ),
								'<code>APPLY</code>'
							);
							?>
						</label><br>
						<input type="text" id="zdz-bp-confirm" name="confirm" value="" class="regular-text" autocomplete="off" spellcheck="false">
					</p>
					<?php submit_button( __( 'Apply this Identity Pack', 'zorderz' ), 'primary', 'submit', false ); ?>
				</form>
			</div>
		<?php endif; ?>
		<?php
	}

	private static function render_log() {
		$log = (array) get_option( ZDZ_Identity_Pack::LOG_OPTION, [] );
		if ( ! $log ) {
			return;
		}
		?>
		<hr>
		<h2><?php esc_html_e( 'History', 'zorderz' ); ?></h2>
		<table class="widefat striped" style="max-width:80em">
			<thead><tr>
				<th style="width:18%"><?php esc_html_e( 'When', 'zorderz' ); ?></th>
				<th style="width:14%"><?php esc_html_e( 'Pack', 'zorderz' ); ?></th>
				<th style="width:14%"><?php esc_html_e( 'By', 'zorderz' ); ?></th>
				<th><?php esc_html_e( 'What happened', 'zorderz' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( array_reverse( $log ) as $row ) : ?>
				<?php $user = get_userdata( (int) ( $row['by'] ?? 0 ) ); ?>
				<tr>
					<td><?php echo esc_html( (string) ( $row['at'] ?? '' ) ); ?></td>
					<td><code><?php echo esc_html( (string) ( $row['pack'] ?? '' ) ); ?></code></td>
					<td><?php echo esc_html( $user ? $user->display_name : __( 'unknown', 'zorderz' ) ); ?></td>
					<td><?php echo esc_html( implode( ' ', (array) ( $row['messages'] ?? [] ) ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	// ─────────────────────────────────────────────────── plumbing

	/** The stored (not resolved) value at a path, so the form shows what was typed. */
	private static function stored_value( $path ) {
		$node = get_option( ZDZ_Business_Profile::OPTION, [] );
		foreach ( explode( '.', $path ) as $seg ) {
			if ( ! is_array( $node ) || ! array_key_exists( $seg, $node ) ) {
				return '';
			}
			$node = $node[ $seg ];
		}
		return $node;
	}

	/** A dot path as a form-safe key, and back. */
	private static function form_key( $path ) {
		return str_replace( '.', '__', $path );
	}

	private static function plant( array &$a, $path, $value ) {
		$node = &$a;
		foreach ( explode( '.', $path ) as $seg ) {
			if ( ! isset( $node[ $seg ] ) || ! is_array( $node[ $seg ] ) ) {
				$node[ $seg ] = [];
			}
			$node = &$node[ $seg ];
		}
		$node = $value;
	}

	private static function notice( $html, $type = 'info' ) {
		self::$notices[] = [ $html, $type ];
	}

	private static function render_notices() {
		foreach ( self::$notices as $n ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $n[1] ),
				wp_kses( $n[0], [ 'code' => [], 'strong' => [], 'em' => [], 'br' => [] ] )
			);
		}
		self::$notices = [];
	}
}

ZDZ_Business_Profile_Admin::init();
