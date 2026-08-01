<?php
/**
 * Zorderz — Business Profile
 *
 * The single place a Zorderz install describes the business it serves: names,
 * contact details, brand palette, senders, locale. Out of the box every value
 * is neutral or derived from WordPress itself, so a fresh install is genuinely
 * a blank slate. A business fills this in — by hand, by importing an Identity
 * Pack, or eventually by letting an Ai read its connected systems and propose
 * the answers.
 *
 * WHY THIS EXISTS
 * Before this class, the company's identity was ~150 hardcoded strings spread
 * across the theme and every plugin: a name in a page title, a colour in a
 * stylesheet, an address in an email footer, a phone number in a placeholder.
 * Removing them without somewhere to put them would only have produced a
 * nameless app. This is that somewhere.
 *
 * THE LAYER RULE
 * Core reads from this profile. Core never contains a company's values. If you
 * find yourself typing a business's name, colour, phone number or address into
 * a PHP file, it belongs here instead.
 *
 * USAGE
 *   ZDZ_Business_Profile::get( 'identity.trading_name' )      // one value
 *   ZDZ_Business_Profile::get( 'contact.phone', '' )          // with a default
 *   ZDZ_Business_Profile::sender( 'surveys' )                 // [name, email]
 *   ZDZ_Business_Profile::name()                              // best display name
 *   ZDZ_Business_Profile::all()                               // whole profile
 *   ZDZ_Business_Profile::set( [ 'identity' => [ ... ] ] )    // merge + save
 *
 * @package Zorderz
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Business_Profile {

	const OPTION = 'zdz_business_profile';

	/** @var array|null Request-level cache of the resolved profile. */
	private static $resolved = null;

	/**
	 * Neutral defaults — the blank slate.
	 *
	 * Anything that can reasonably come from WordPress does (site name, admin
	 * email, timezone), so a fresh install is coherent rather than empty. The
	 * brand ramp ships with the platform's default palette; a business overrides
	 * it wholesale, which is what re-skins the entire app (see css_variables()).
	 */
	public static function defaults() {
		return [
			'identity' => [
				'legal_name'    => '',                        // falls back to site name
				'trading_name'  => '',                        // falls back to site name
				'short_name'    => '',                        // falls back to trading name
				'former_names'  => [],                        // shown as "formerly …" where relevant
				'industry'      => '',                        // e.g. "field service"
				'tagline'       => '',                        // falls back to site tagline
				'license'       => '',                        // e.g. a contractor licence line
				'founded'       => '',
				/*
				 * A short internal token for the business — the thing that appears on
				 * paperwork as shorthand. It is registered as a RESERVED token so the
				 * attribution parser can never mistake the company's own abbreviation
				 * for a person's initials, which is a real defect this platform had.
				 */
				'short_code'    => '',
				'publisher'     => '',                        // display-only "published by" string
			],
			'contact' => [
				'phone'     => '',
				'phone_alt' => '',
				'hours'     => '',
				'email'     => '',                            // falls back to admin email
				'address'   => [
					'street'   => '',
					'locality' => '',
					'region'   => '',
					'postal'   => '',
					'country'  => '',
				],
			],
			'web' => [
				'app_domain'       => '',                     // falls back to this site's host
				'marketing_domain' => '',
				'review_google'    => '',
				'review_page'      => '',
				'asset_cdn_host'   => '',                     // optional; unset ⇒ local URLs
			],
			/*
			 * Sending identities, by purpose. Any purpose left blank inherits
			 * 'default'; 'default' itself falls back to WordPress. This replaced
			 * per-plugin hardcoded from-names, which is how one person's name
			 * ended up as the sender on a whole category of mail.
			 */
			'senders' => [
				'default'   => [ 'name' => '', 'email' => '' ],
				'alerts'    => [ 'name' => '', 'email' => '' ],
				'messaging' => [ 'name' => '', 'email' => '' ],
				'surveys'   => [ 'name' => '', 'email' => '' ],
				'documents' => [ 'name' => '', 'email' => '' ],
			],
			'brand' => [
				/*
				 * The reference ramp. Every themed colour in the app derives from
				 * these eleven values through the --sys-* layer, so replacing the
				 * ramp re-skins the whole interface in all four theme modes.
				 *
				 * NOTE for v1.1: these defaults are the palette the platform was
				 * built and contrast-tested against. They are deliberately left
				 * as the default rather than swapped for something more neutral,
				 * because the four-mode WCAG tint-pair contract is tuned to these
				 * values and re-deriving it needs verification in a real browser.
				 * Overriding the ramp works today; changing the shipped default
				 * is a v1.1 task with a contrast pass attached.
				 */
				'ramp' => [
					'50'  => '#EBF5FF', '100' => '#D6EBFF', '200' => '#ADCFFF',
					'300' => '#7AB3FF', '400' => '#4796F7', '500' => '#2C5F8A',
					'600' => '#1E3A5F', '700' => '#162D4A', '800' => '#0F1F35',
					'900' => '#091526', '950' => '#050D18',
				],
				/*
				 * App-category tile colours. These are a second, independent skin
				 * lever: the ramp above themes the chrome, this themes the dashboard.
				 * Core ships the palette and the tenant only chooses which category
				 * each app sits in — but a business with its own colour language can
				 * override the palette itself here.
				 */
				'categories' => [
					'sales'   => '#7C3AED', 'finance' => '#059669', 'service' => '#2563EB',
					'field'   => '#EA580C', 'admin'   => '#DC2626', 'ops'     => '#0891B2',
					'team'    => '#DB2777',
				],
				/*
				 * Logo artwork, in two shapes × two modes.
				 *
				 *   wide   — a 2:1 PNG. The lockup: wordmark, or mark plus words.
				 *            Used wherever there is horizontal room: the topbar,
				 *            the login screen, document headers, email headers.
				 *   square — a 1:1 PNG. The mark alone. Used wherever the space is
				 *            square or tiny: the home-screen icon, the favicon,
				 *            an avatar, a collapsed sidebar.
				 *
				 * `light` and `dark` name the artwork's OWN INK, not the background
				 * it sits on — the same convention the login template and the
				 * wider design world already use. A "light logo" is drawn in light
				 * ink and therefore belongs on a DARK surface; a "dark logo" is
				 * dark ink for a light surface.
				 *
				 * That inversion trips everyone up, so nothing in this class asks
				 * callers to think about it: logo() takes the BACKGROUND you are
				 * drawing on and picks the ink itself. Most brands need both,
				 * because a navy wordmark disappears on a navy topbar.
				 *
				 * Supplying only one of anything is fine. logo() falls back in a
				 * declared order and tells the caller what it actually got, so a
				 * one-logo business still works and nothing renders a square image
				 * into a wide slot without the layout knowing.
				 */
				'logo' => [
					'wide'   => [ 'light' => '', 'dark' => '' ],
					'square' => [ 'light' => '', 'dark' => '' ],
					'favicon' => '',
				],
				'pwa'  => [
					'name'             => '',                 // falls back to trading name
					'short_name'       => '',                 // falls back to short name
					'theme_color'      => '',                 // falls back to ramp 600
					'background_color' => '',                 // falls back to ramp 600
				],
			],
			'locale' => [
				'timezone'      => '',                        // falls back to WP timezone
				'locale'        => '',                        // falls back to WP locale
				'currency'      => 'USD',
				'currency_sign' => '$',
				'date_format'   => 'n/j/Y',
			],
			'people' => [
				// e.g. "{first}@example.com" — used to suggest, never to assume.
				'staff_email_pattern' => '',
			],
			'_meta' => [
				'source'     => 'defaults',
				'pack'       => '',
				'pack_label' => '',
				'applied_at' => '',
			],
		];
	}

	/**
	 * The resolved profile: stored values merged over defaults, then WordPress
	 * fallbacks applied for anything still blank.
	 */
	public static function all() {
		if ( null !== self::$resolved ) {
			return self::$resolved;
		}
		$stored  = get_option( self::OPTION, [] );
		$profile = self::merge( self::defaults(), is_array( $stored ) ? $stored : [] );

		// WordPress-derived fallbacks. Deliberately resolved here rather than
		// stored, so a site rename flows through without re-saving the profile.
		$site = get_bloginfo( 'name' );
		if ( '' === $profile['identity']['trading_name'] ) $profile['identity']['trading_name'] = $site;
		if ( '' === $profile['identity']['legal_name'] )   $profile['identity']['legal_name']   = $profile['identity']['trading_name'];
		if ( '' === $profile['identity']['short_name'] )   $profile['identity']['short_name']   = $profile['identity']['trading_name'];
		if ( '' === $profile['identity']['tagline'] )      $profile['identity']['tagline']      = get_bloginfo( 'description' );
		if ( '' === $profile['contact']['email'] )         $profile['contact']['email']         = (string) get_option( 'admin_email' );
		if ( '' === $profile['web']['app_domain'] )        $profile['web']['app_domain']        = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( '' === $profile['locale']['timezone'] )       $profile['locale']['timezone']       = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC';
		if ( '' === $profile['brand']['pwa']['name'] )     $profile['brand']['pwa']['name']       = $profile['identity']['trading_name'];
		if ( '' === $profile['brand']['pwa']['short_name'] ) $profile['brand']['pwa']['short_name'] = $profile['identity']['short_name'];
		if ( '' === $profile['brand']['pwa']['theme_color'] ) $profile['brand']['pwa']['theme_color'] = $profile['brand']['ramp']['600'];
		if ( '' === $profile['brand']['pwa']['background_color'] ) $profile['brand']['pwa']['background_color'] = $profile['brand']['ramp']['600'];

		/**
		 * Filter the resolved Business Profile.
		 *
		 * Lets a site override any value in code without editing the stored
		 * profile — useful for environment-specific senders or domains.
		 */
		self::$resolved = apply_filters( 'zdz_business_profile', $profile );
		return self::$resolved;
	}

	/** Deep merge, where a stored scalar or list replaces the default outright. */
	private static function merge( array $base, array $over ) {
		foreach ( $over as $k => $v ) {
			if ( is_array( $v ) && isset( $base[ $k ] ) && is_array( $base[ $k ] ) && ! array_is_list( $v ) ) {
				$base[ $k ] = self::merge( $base[ $k ], $v );
			} else {
				$base[ $k ] = $v;
			}
		}
		return $base;
	}

	/**
	 * Read one value by dot path.
	 *
	 * @param string $path    e.g. 'contact.phone' or 'brand.ramp.500'
	 * @param mixed  $default Returned when the path is missing or blank.
	 */
	public static function get( $path, $default = '' ) {
		$node = self::all();
		foreach ( explode( '.', $path ) as $seg ) {
			if ( ! is_array( $node ) || ! array_key_exists( $seg, $node ) ) {
				return $default;
			}
			$node = $node[ $seg ];
		}
		if ( '' === $node || null === $node || [] === $node ) {
			return $default;
		}
		return $node;
	}

	/** Merge values into the stored profile and save. */
	public static function set( array $values, $source = 'manual' ) {
		$stored = get_option( self::OPTION, [] );
		$stored = self::merge( is_array( $stored ) ? $stored : [], $values );
		$stored['_meta'] = array_merge(
			$stored['_meta'] ?? [],
			[ 'source' => $source, 'applied_at' => gmdate( 'c' ) ]
		);
		update_option( self::OPTION, $stored, false );
		self::$resolved = null;
		return true;
	}

	/**
	 * Drop the request-level cache.
	 *
	 * set() does this itself. Anything that writes the option directly — a
	 * revert, an import, a test — must call this, or every read for the rest of
	 * the request keeps returning the profile that was just replaced.
	 */
	public static function flush() {
		self::$resolved = null;
	}

	/**
	 * A short monogram for the business — its initials.
	 *
	 * Used where there is room for two or three characters and no logo has been
	 * supplied: the nav button, an avatar placeholder. Exists because the theme
	 * shipped one company's initials as the hardcoded fallback, so every install
	 * on earth would have shown "TS" until someone uploaded artwork.
	 */
	public static function initials( $max = 3 ) {
		$name  = (string) self::get( 'identity.short_name', self::name() );
		$words = preg_split( '/[\s\-—–]+/u', trim( $name ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! $words ) {
			return '';
		}
		// One word: take its first two letters, so "Zorderz" reads "ZO" rather
		// than a lonely "Z".
		if ( 1 === count( $words ) ) {
			return strtoupper( mb_substr( $words[0], 0, min( 2, $max ) ) );
		}
		$out = '';
		foreach ( $words as $w ) {
			if ( mb_strlen( $out ) >= $max ) {
				break;
			}
			$out .= mb_substr( $w, 0, 1 );
		}
		return strtoupper( $out );
	}

	/** Best display name for the business. */
	public static function name() {
		return (string) self::get( 'identity.trading_name', get_bloginfo( 'name' ) );
	}

	/**
	 * A sending identity for a purpose, inheriting from 'default' then WordPress.
	 *
	 * @return array{name:string,email:string}
	 */
	public static function sender( $purpose = 'default' ) {
		$senders = self::get( 'senders', [] );
		$pick    = $senders[ $purpose ] ?? [];
		$base    = $senders['default'] ?? [];
		$name    = $pick['name'] ?? '';
		$email   = $pick['email'] ?? '';
		if ( '' === $name )  $name  = $base['name'] ?? '';
		if ( '' === $email ) $email = $base['email'] ?? '';
		if ( '' === $name )  $name  = self::name();
		if ( '' === $email ) $email = (string) self::get( 'contact.email', get_option( 'admin_email' ) );
		return [ 'name' => $name, 'email' => $email ];
	}

	/** The two logo shapes and their nominal aspect ratios. */
	const LOGO_SHAPES = [ 'wide' => 2.0, 'square' => 1.0 ];

	/**
	 * Resolve a logo for a slot, and say what was actually found.
	 *
	 * You say what shape the layout needs and what colour the BACKGROUND is. The
	 * ink is chosen for you — pass 'dark' for a dark topbar and you get the
	 * light-ink artwork, which is the thing everyone gets backwards.
	 *
	 * You get back the best available artwork plus enough information to lay it
	 * out honestly, because "the business only uploaded a square logo" is a
	 * normal situation and a square stretched across a 2:1 header is not.
	 *
	 * Fallback order, in full:
	 *   1. right shape, right ink
	 *   2. right shape, wrong ink        — wrong colouring beats no logo
	 *   3. wrong shape, right ink        — right colouring, different layout
	 *   4. whatever exists
	 *   5. nothing; the caller renders the business name as text
	 *
	 * @param string $shape      'wide' | 'square'
	 * @param string $background 'light' | 'dark' — the surface being drawn on.
	 * @return array{url:string,shape:string,ink:string,exact:bool,ratio:float}
	 *         `shape` is what the URL actually IS, not what was asked for.
	 */
	public static function logo( $shape = 'wide', $background = 'light' ) {
		$shape = isset( self::LOGO_SHAPES[ $shape ] ) ? $shape : 'wide';
		// A light background needs dark ink, and vice versa.
		$ink         = 'dark' === $background ? 'light' : 'dark';
		$other_shape = 'wide' === $shape ? 'square' : 'wide';
		$other_ink   = 'light' === $ink ? 'dark' : 'light';

		$logos = (array) self::get( 'brand.logo', [] );
		$pick  = function ( $s, $i ) use ( $logos ) {
			$v = $logos[ $s ][ $i ] ?? '';
			return is_string( $v ) ? trim( $v ) : '';
		};

		foreach ( [
			[ $shape, $ink, true ],
			[ $shape, $other_ink, false ],
			[ $other_shape, $ink, false ],
			[ $other_shape, $other_ink, false ],
		] as $try ) {
			$url = $pick( $try[0], $try[1] );
			if ( '' !== $url ) {
				return [
					'url'   => $url,
					'shape' => $try[0],
					'ink'   => $try[1],
					'exact' => $try[2],
					'ratio' => self::LOGO_SHAPES[ $try[0] ],
				];
			}
		}
		return [ 'url' => '', 'shape' => $shape, 'ink' => $ink, 'exact' => false, 'ratio' => self::LOGO_SHAPES[ $shape ] ];
	}

	/**
	 * Render a logo as an <img>, or the business name as text when there is none.
	 *
	 * The width/height attributes come from the shape that was actually found, so
	 * a square logo standing in for a wide one is laid out as a square and
	 * centred rather than stretched. `object-fit: contain` covers the rest: a
	 * business whose artwork is 3:1 or 4:5 gets letterboxed inside its slot
	 * instead of distorted.
	 *
	 * @param string $shape      Shape the layout wants.
	 * @param string $background 'light' | 'dark' — the surface being drawn on.
	 * @param int    $height     Rendered height in px; width follows the real ratio.
	 */
	public static function logo_html( $shape = 'wide', $background = 'light', $height = 32, array $attrs = [] ) {
		$logo = self::logo( $shape, $background );
		$name = self::name();

		if ( '' === $logo['url'] ) {
			return sprintf(
				'<span class="zdz-logo zdz-logo--text" data-shape="%s">%s</span>',
				esc_attr( $shape ),
				esc_html( $name )
			);
		}

		$height = max( 8, (int) $height );
		$width  = (int) round( $height * $logo['ratio'] );
		$class  = trim( 'zdz-logo zdz-logo--' . $logo['shape'] . ' ' . ( $attrs['class'] ?? '' ) );

		return sprintf(
			'<img src="%s" alt="%s" class="%s" width="%d" height="%d" decoding="async"'
				. ' data-requested-shape="%s"%s style="object-fit:contain;max-width:100%%">',
			esc_url( $logo['url'] ),
			esc_attr( $name ),
			esc_attr( $class ),
			$width,
			$height,
			esc_attr( $shape ),
			$logo['exact'] ? '' : ' data-fallback="1"',
		);
	}

	/**
	 * Measure an image's aspect ratio when it is local to this site.
	 *
	 * Used by the settings screen to warn that a 3:1 PNG in the 2:1 slot will be
	 * letterboxed. It warns; it never rejects — the business owns its artwork,
	 * and a slightly-off ratio is a design choice, not an error. Returns null for
	 * anything off-site, since fetching a remote image to measure it is not
	 * something a settings page should do.
	 */
	public static function measure_logo( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return null;
		}
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['baseurl'] ) && str_starts_with( $url, $uploads['baseurl'] ) ) {
			$path = $uploads['basedir'] . substr( $url, strlen( $uploads['baseurl'] ) );
		} elseif ( str_starts_with( $url, get_template_directory_uri() ) ) {
			$path = get_template_directory() . substr( $url, strlen( get_template_directory_uri() ) );
		} else {
			return null;
		}
		if ( ! is_readable( $path ) || ! function_exists( 'getimagesize' ) ) {
			return null;
		}
		$size = @getimagesize( $path );
		if ( ! $size || empty( $size[1] ) ) {
			return null;
		}
		return [ 'w' => (int) $size[0], 'h' => (int) $size[1], 'ratio' => round( $size[0] / $size[1], 3 ) ];
	}

	/** Formatted single-line address, or '' when unset. */
	public static function address_line() {
		$a = self::get( 'contact.address', [] );
		$parts = array_filter( [
			$a['street'] ?? '',
			trim( ( $a['locality'] ?? '' ) . ' ' . ( $a['region'] ?? '' ) . ' ' . ( $a['postal'] ?? '' ) ),
			$a['country'] ?? '',
		] );
		return implode( ', ', $parts );
	}

	// ─────────────────────────────────────────────────── wiring

	public static function init() {
		/*
		 * CASCADE ORDER MATTERS HERE, and it is easy to get wrong.
		 *
		 * The overrides below target :root, exactly as style.css does. Equal
		 * specificity means the LAST declaration wins, so the override must be
		 * emitted AFTER the stylesheet or it does nothing at all — silently, with
		 * no error, which is the worst possible failure mode for a theming feature.
		 *
		 * On the front end that means attaching the CSS to the stylesheet's own
		 * handle with wp_add_inline_style(), which WordPress prints immediately
		 * after it. In admin and on the login screen the *_head hooks already fire
		 * after styles are printed, so a late-priority print is correct there.
		 */
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'attach_inline_css' ], 20 );
		add_action( 'admin_head', [ __CLASS__, 'print_css_variables' ], 20 );
		add_action( 'login_head', [ __CLASS__, 'print_css_variables' ], 20 );
		add_filter( 'wp_mail_from', [ __CLASS__, 'filter_mail_from' ], 5 );
		add_filter( 'wp_mail_from_name', [ __CLASS__, 'filter_mail_from_name' ], 5 );
		add_action( 'init', [ __CLASS__, 'absorb_legacy_options' ], 5 );
	}

	/**
	 * Build the brand override CSS, or '' when the business has changed nothing.
	 *
	 * This is the whole aesthetic-parity mechanism. The stylesheet defines the
	 * --ref-brand-* ramp and derives every --sys-* colour from it, so replacing
	 * those eleven values re-skins the entire interface in all four theme modes
	 * without touching CSS. The --cat-* tokens are the same trick for the
	 * dashboard tiles.
	 *
	 * Only values that actually differ from the shipped default are emitted, so
	 * an install that has not set a palette pays nothing and the stylesheet stays
	 * authoritative.
	 */
	public static function css_variables() {
		$defaults = self::defaults()['brand'];
		$out      = [];

		foreach ( [ 'ramp' => '--ref-brand-', 'categories' => '--cat-' ] as $group => $prefix ) {
			foreach ( (array) self::get( "brand.$group", [] ) as $key => $hex ) {
				// Unknown keys are ignored rather than emitted. A token this theme
				// does not define is either a typo or a future version's, and
				// minting custom properties from stored data invites injection.
				if ( ! isset( $defaults[ $group ][ $key ] ) ) {
					continue;
				}
				$hex = trim( (string) $hex );
				if ( '' === $hex || 0 === strcasecmp( $hex, $defaults[ $group ][ $key ] ) ) {
					continue;
				}
				if ( ! preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', $hex ) ) {
					continue;
				}
				$out[] = $prefix . $key . ':' . $hex;
			}
		}

		return $out ? ':root{' . implode( ';', $out ) . '}' : '';
	}

	/** Front end: attach to the stylesheet handle so it prints after style.css. */
	public static function attach_inline_css() {
		if ( '' === self::css_variables() ) {
			return;
		}
		if ( wp_style_is( 'zdz-style', 'registered' ) || wp_style_is( 'zdz-style', 'enqueued' ) ) {
			wp_add_inline_style( 'zdz-style', self::css_variables() );
			return;
		}
		// A child theme or unusual setup dropped the handle. Print late in wp_head
		// instead, which still lands after wp_print_styles at priority 8.
		add_action( 'wp_head', [ __CLASS__, 'print_css_variables' ], 20 );
	}

	/** Admin, login, and the front-end fallback path. */
	public static function print_css_variables() {
		$css = self::css_variables();
		if ( '' === $css ) {
			return;
		}
		printf( "<style id=\"zdz-business-profile-brand\">%s</style>\n", esc_html( $css ) );
	}

	public static function filter_mail_from( $email ) {
		$s = self::sender( 'default' );
		return $s['email'] ?: $email;
	}

	public static function filter_mail_from_name( $name ) {
		$s = self::sender( 'default' );
		return $s['name'] ?: $name;
	}

	/**
	 * Fold the two pre-profile standalone options into the profile.
	 *
	 * `zdz_company_phone` and `zdz_receptionist_hours` predate this class. They
	 * are copied in once, and the originals are left alone so anything still
	 * reading them keeps working during the transition.
	 */
	public static function absorb_legacy_options() {
		if ( get_option( 'zdz_business_profile_absorbed' ) ) {
			return;
		}
		$phone = (string) get_option( 'zdz_company_phone', '' );
		$hours = (string) get_option( 'zdz_receptionist_hours', '' );
		$patch = [];
		if ( '' !== $phone && '' === self::get( 'contact.phone', '' ) ) $patch['contact']['phone'] = $phone;
		if ( '' !== $hours && '' === self::get( 'contact.hours', '' ) ) $patch['contact']['hours'] = $hours;
		if ( $patch ) {
			self::set( $patch, 'legacy-options' );
		}
		update_option( 'zdz_business_profile_absorbed', 1, false );
	}

	/**
	 * The PWA manifest, built from the profile.
	 *
	 * Replaces the static manifest.json, which hardcoded one company's name and
	 * colours and — for months — pointed its icons at a theme folder that no
	 * longer existed.
	 */
	public static function manifest() {
		$pwa  = (array) self::get( 'brand.pwa', [] );
		$base = get_template_directory_uri();

		/*
		 * A home-screen icon is a square slot on a background nobody controls, so
		 * it wants the full-colour mark — the dark-ink one, same as on a light
		 * surface. Accept it ONLY if a square actually came back: the wide lockup
		 * falling through as a substitute would be squashed into the launcher tile
		 * on every phone on the crew. Better the platform's own icon than a
		 * mangled wordmark.
		 */
		$found = self::logo( 'square', 'light' );
		$icon  = 'square' === $found['shape'] ? $found['url'] : '';

		$icons = [];
		foreach ( [ 192, 512 ] as $size ) {
			$icons[] = [
				'src'     => $icon ?: $base . "/assets/images/icon-{$size}.png",
				'sizes'   => "{$size}x{$size}",
				'type'    => 'image/png',
				'purpose' => 'any',
			];
			$icons[] = [
				'src'     => $base . "/assets/images/icon-maskable-{$size}.png",
				'sizes'   => "{$size}x{$size}",
				'type'    => 'image/png',
				'purpose' => 'maskable',
			];
		}
		return [
			'name'             => $pwa['name'] ?? self::name(),
			'short_name'       => $pwa['short_name'] ?? self::name(),
			'description'      => (string) self::get( 'identity.tagline', '' ),
			'start_url'        => home_url( '/' ),
			'scope'           => home_url( '/' ),
			'display'          => 'standalone',
			'orientation'      => 'portrait',
			'background_color' => $pwa['background_color'] ?? '#1E3A5F',
			'theme_color'      => $pwa['theme_color'] ?? '#1E3A5F',
			'icons'            => $icons,
		];
	}
}

ZDZ_Business_Profile::init();
