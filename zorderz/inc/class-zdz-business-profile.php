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
				 * for a person's initials.
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
			 * 'default'; 'default' itself falls back to WordPress, so no app names
			 * a sender of its own.
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
					'50'  => '#F5F6FE', '100' => '#EAEDFE', '200' => '#D3DAFF',
					'300' => '#B1BCFF', '400' => '#7D87FB', '500' => '#5150D6',
					'600' => '#433FBD', '700' => '#36339C', '800' => '#282876',
					'900' => '#1D1D56', '950' => '#11123A',
				],
				/*
				 * One colour per app category, printed as --cat-* for custom styles and
				 * add-ons. Each defaults to the colour of the dashboard group that
				 * category is filed under (ZDZ_App_Groups::CAT_FALLBACK), so anything
				 * styled with them agrees with the dashboard. The groups themselves are
				 * fixed to a colour-blind-safe set (Okabe-Ito) and are not changed here.
				 */
				'categories' => [
					'sales'   => '#E69F00', 'finance' => '#0072B2', 'service' => '#0072B2',
					'field'   => '#009E73', 'admin'   => '#0072B2', 'ops'     => '#009E73',
					'team'    => '#56B4E9',
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
				'currency'      => 'USD',                     // a business outside the US that sets none gets its country's
				'currency_sign' => '$',
				'date_format'   => 'n/j/Y',
				/*
				 * The rest follow the business's country (Contact → Address → Country), else its language's
				 * region, else the United States; a value stored here always wins.
				 */
				'units'          => '',                       // "us" (inches, °F) or "metric" (cm, °C, mm of rain)
				'postcode_label' => '',                       // "ZIP", "Postcode", "Postal code", …
				'region_label'   => '',                       // "State", "County", "Province", "Region"
				'time_format'    => '',                       // a PHP time format: "g:i a" or "H:i"
				'number_format'  => '',                       // "1,234.56", "1.234,56", "1 234,56" or "1'234.56"
			],
			'people' => [
				// e.g. "{first}@example.com" — used to suggest, never to assume.
				'staff_email_pattern' => '',
			],
			'legal' => [
				/*
				 * The legal pack customer agreements follow (the agreements app's packs/<id>/). A pack is public
				 * law, not identity: the United States' national standard is the neutral default, and the job
				 * address's state adds its own layer. '' or "none" means no agreements are made.
				 */
				'agreement_pack' => 'us-federal',
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
		$given = is_array( $stored ) && isset( $stored['locale'] ) && is_array( $stored['locale'] ) ? $stored['locale'] : [];
		$lang  = '' !== (string) $profile['locale']['locale'] ? (string) $profile['locale']['locale'] : ( function_exists( 'get_locale' ) ? (string) get_locale() : '' );
		// The country the rest follows: the business address's, else the language's region, else the United States.
		$cc = self::country_code_from( (string) ( $profile['contact']['address']['country'] ?? '' ), $lang );
		$profile['locale']['country'] = $cc;
		$unset = static function ( $k ) use ( $given ) {
			return '' === trim( (string) ( $given[ $k ] ?? '' ) );
		};
		// A business outside the United States that names no currency bills in its country's ("GB" → GBP).
		if ( $unset( 'currency' ) && ! ( is_array( $stored ) && '' !== trim( (string) ( $stored['currency'] ?? '' ) ) ) ) {
			$profile['locale']['currency'] = self::currency_for( $cc );
		}
		// The sign follows the currency when only the currency was given ("GBP" reads "£"); a stored sign always wins.
		$flat = is_array( $stored ) && is_string( $stored['currency_sign'] ?? null ) ? trim( $stored['currency_sign'] ) : ''; // a profile stored flat
		if ( $unset( 'currency_sign' ) && '' === $flat ) {
			$profile['locale']['currency_sign'] = self::sign_for( (string) $profile['locale']['currency'] );
		}
		// Dates follow the language, and the country where the language says no more than "en_US" or "en"
		// ("en_GB", or a business in the United Kingdom, writes 19/11/2026); a stored format always wins.
		if ( $unset( 'date_format' ) ) {
			$profile['locale']['date_format'] = self::date_format_for( self::language_for_country( $lang, $cc ) );
		}
		if ( $unset( 'units' ) || ! in_array( $profile['locale']['units'], [ 'us', 'metric' ], true ) ) {
			$profile['locale']['units'] = self::units_for( $cc );
		}
		if ( $unset( 'postcode_label' ) ) {
			$profile['locale']['postcode_label'] = self::postcode_label_for( $cc );
		}
		if ( $unset( 'region_label' ) ) {
			$profile['locale']['region_label'] = self::region_label_for( $cc );
		}
		if ( $unset( 'time_format' ) || ! preg_match( '/^[gGhH]:i(?: ?[aA])?$/', (string) $profile['locale']['time_format'] ) ) {
			$profile['locale']['time_format'] = self::time_format_for( $cc );
		}
		if ( $unset( 'number_format' ) || ! isset( self::NUMBER_FORMATS[ $profile['locale']['number_format'] ] ) ) {
			$profile['locale']['number_format'] = self::number_format_for( self::language_for_country( $lang, $cc ) );
		}
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

	/** Signs of the currencies a business is most likely to bill in; any other reads as its code ("CHF 40"). */
	const CURRENCY_SIGNS = [
		'USD' => '$', 'CAD' => '$', 'AUD' => '$', 'NZD' => '$', 'SGD' => '$', 'HKD' => '$', 'MXN' => '$',
		'GBP' => '£', 'EUR' => '€', 'JPY' => '¥', 'CNY' => '¥', 'INR' => '₹', 'KRW' => '₩', 'ILS' => '₪',
		'PHP' => '₱', 'NGN' => '₦', 'TRY' => '₺', 'THB' => '฿', 'VND' => '₫', 'ZAR' => 'R', 'BRL' => 'R$',
		'PLN' => 'zł', 'SEK' => 'kr', 'NOK' => 'kr', 'DKK' => 'kr',
	];

	/** The business's money sign (Business Profile → Locale), "$" when none resolves. */
	public static function sign() {
		$s = (string) self::get( 'locale.currency_sign', '$' );
		return '' !== $s ? $s : '$';
	}

	/**
	 * A sum in the business's money: money(1080) "$1,080.00", money(-175, 0) "-$175". $minus is the sign written
	 * before a negative sum ("-", or U+2212 as some paperwork prints it).
	 */
	public static function money( $amount, $places = 2, $minus = '-' ) {
		$v = (float) $amount;
		return ( $v < 0 ? $minus : '' ) . self::sign() . self::number( abs( $v ), (int) $places );
	}

	/**
	 * The sign for a currency code: CURRENCY_SIGNS, else the code itself followed by a space; "$" with no code.
	 * Filter: zdz_currency_sign ( $sign, $code ). Pure apart from the filter.
	 */
	public static function sign_for( $code ) {
		$code = strtoupper( trim( (string) $code ) );
		if ( '' === $code ) {
			$sign = '$';
		} elseif ( isset( self::CURRENCY_SIGNS[ $code ] ) ) {
			$sign = self::CURRENCY_SIGNS[ $code ];
		} else {
			$sign = preg_match( '/^[A-Z]{3}$/', $code ) ? $code . ' ' : '$';
		}
		return function_exists( 'apply_filters' ) ? (string) apply_filters( 'zdz_currency_sign', $sign, $code ) : $sign;
	}

	/**
	 * The short date format a language writes ("en_US" n/j/Y, "en_GB" j/n/Y, "de_DE" j.n.Y, "sv_SE" Y-m-d): month first
	 * only where that is the custom. Filter: zdz_date_format_for ( $format, $locale ). Pure apart from the filter.
	 */
	public static function date_format_for( $locale ) {
		$l    = strtolower( str_replace( '-', '_', trim( (string) $locale ) ) );
		$lang = (string) strtok( $l, '_' );
		$reg  = strtoupper( (string) ( explode( '_', $l )[1] ?? '' ) );
		if ( '' === $l || in_array( $reg, [ 'US', 'PH', 'FM', 'MH', 'PW' ], true ) || ( 'en' === $lang && '' === $reg ) ) {
			$f = 'n/j/Y';
		} elseif ( in_array( $lang, [ 'zh', 'ja', 'ko', 'hu', 'lt', 'sv', 'mn' ], true ) || 'CA' === $reg && 'en' === $lang ) {
			$f = 'Y-m-d';
		} elseif ( in_array( $lang, [ 'de', 'ru', 'pl', 'cs', 'sk', 'fi', 'nb', 'nn', 'no', 'da', 'tr', 'uk', 'ro', 'et', 'lv', 'sl', 'hr', 'sr', 'bg', 'is', 'be', 'kk', 'az' ], true ) ) {
			$f = 'j.n.Y';
		} elseif ( 'nl' === $lang ) {
			$f = 'j-n-Y';
		} else {
			$f = 'j/n/Y';
		}
		return function_exists( 'apply_filters' ) ? (string) apply_filters( 'zdz_date_format_for', $f, $locale ) : $f;
	}

	/**
	 * The business's short date format (Business Profile → Locale → Date format). $padded writes the day and month in
	 * two digits ("01/05/2026"), as printed paperwork does.
	 */
	public static function date_format( $padded = false ) {
		$f = (string) self::get( 'locale.date_format', 'n/j/Y' );
		return $padded ? strtr( $f, [ 'n' => 'm', 'j' => 'd' ] ) : $f;
	}

	/**
	 * The business's language as a browser reads it ("en-GB"): Locale → Language, else the site's, else "en-US"; an
	 * English language that names the United States or no country takes the business's own country ("en-GB" for a
	 * business in the United Kingdom on a site left in "en_US"). Only the language and its country, so every browser
	 * takes it.
	 */
	public static function js_locale() {
		$l = trim( (string) self::get( 'locale.locale', '' ) );
		if ( '' === $l && function_exists( 'get_locale' ) ) {
			$l = (string) get_locale();
		}
		$l = self::language_for_country( $l, (string) self::get( 'locale.country', 'US' ) );
		return preg_match( '/^([a-z]{2,3})(?:[_-]([A-Za-z]{2}|\d{3}))?(?:[_-]|$)/', $l, $m )
			? $m[1] . ( isset( $m[2] ) && '' !== $m[2] ? '-' . strtoupper( $m[2] ) : '' )
			: 'en-US';
	}

	// ─────────────────────────────────────────────── country, units, labels, numbers and textual dates

	/** Country names a business is likely to write in its address, by their two-letter codes. */
	const COUNTRY_NAMES = [
		'UNITED STATES' => 'US', 'UNITED STATES OF AMERICA' => 'US', 'USA' => 'US', 'U.S.' => 'US', 'U.S.A.' => 'US',
		'AMERICA' => 'US', 'UNITED KINGDOM' => 'GB', 'UK' => 'GB', 'U.K.' => 'GB', 'GREAT BRITAIN' => 'GB',
		'BRITAIN' => 'GB', 'ENGLAND' => 'GB', 'SCOTLAND' => 'GB', 'WALES' => 'GB', 'NORTHERN IRELAND' => 'GB',
		'IRELAND' => 'IE', 'CANADA' => 'CA', 'AUSTRALIA' => 'AU', 'NEW ZEALAND' => 'NZ', 'INDIA' => 'IN',
		'SOUTH AFRICA' => 'ZA', 'SINGAPORE' => 'SG', 'PHILIPPINES' => 'PH', 'MEXICO' => 'MX', 'BRAZIL' => 'BR',
		'GERMANY' => 'DE', 'AUSTRIA' => 'AT', 'SWITZERLAND' => 'CH', 'FRANCE' => 'FR', 'BELGIUM' => 'BE',
		'NETHERLANDS' => 'NL', 'THE NETHERLANDS' => 'NL', 'SPAIN' => 'ES', 'PORTUGAL' => 'PT', 'ITALY' => 'IT',
		'SWEDEN' => 'SE', 'NORWAY' => 'NO', 'DENMARK' => 'DK', 'FINLAND' => 'FI', 'POLAND' => 'PL', 'JAPAN' => 'JP',
		'CHINA' => 'CN', 'SOUTH KOREA' => 'KR', 'KOREA' => 'KR', 'ISRAEL' => 'IL', 'UNITED ARAB EMIRATES' => 'AE',
		'NIGERIA' => 'NG', 'KENYA' => 'KE', 'MALAYSIA' => 'MY', 'PUERTO RICO' => 'PR', 'LIBERIA' => 'LR', 'MYANMAR' => 'MM',
	];

	/**
	 * The two-letter country the locale follows: the address's country (a name from COUNTRY_NAMES or a two-letter
	 * code), else the language's region ("en_GB" → GB), else US. Pure.
	 */
	public static function country_code_from( $country, $language = '' ) {
		$c = strtoupper( trim( preg_replace( '/\s+/', ' ', (string) $country ) ) );
		if ( isset( self::COUNTRY_NAMES[ $c ] ) ) {
			return self::COUNTRY_NAMES[ $c ];
		}
		if ( preg_match( '/^[A-Z]{2}$/', $c ) ) {
			return 'UK' === $c ? 'GB' : $c;
		}
		if ( preg_match( '/^[a-z]{2,3}[_-]([A-Za-z]{2})(?:[_-]|$)/', trim( (string) $language ), $m ) ) {
			return strtoupper( $m[1] );
		}
		return 'US';
	}

	/** The business's country, as a two-letter code (see country_code_from()). */
	public static function country() {
		return (string) self::get( 'locale.country', 'US' );
	}

	/**
	 * A language with its country filled in where the language alone says nothing more than the platform's
	 * default: "en_US" or "en" for a business in GB reads "en_GB"; any other language is kept as it is. Pure.
	 */
	public static function language_for_country( $language, $country ) {
		$l  = str_replace( '-', '_', trim( (string) $language ) );
		$cc = strtoupper( (string) $country );
		if ( preg_match( '/^[A-Z]{2}$/', $cc ) && 'US' !== $cc && ( '' === $l || 'en' === strtolower( $l ) || 'en_US' === $l ) ) {
			return 'en_' . $cc;
		}
		return $l;
	}

	/** The currency a country bills in, for a business that names none. Pure. */
	public static function currency_for( $country ) {
		$euro = [ 'AT', 'BE', 'CY', 'DE', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PT', 'SI', 'SK' ];
		$own  = [ 'GB' => 'GBP', 'CA' => 'CAD', 'AU' => 'AUD', 'NZ' => 'NZD', 'IN' => 'INR', 'ZA' => 'ZAR', 'SG' => 'SGD',
			'PH' => 'PHP', 'MX' => 'MXN', 'BR' => 'BRL', 'CH' => 'CHF', 'SE' => 'SEK', 'NO' => 'NOK', 'DK' => 'DKK',
			'PL' => 'PLN', 'JP' => 'JPY', 'CN' => 'CNY', 'KR' => 'KRW', 'IL' => 'ILS', 'AE' => 'AED', 'NG' => 'NGN',
			'KE' => 'KES', 'MY' => 'MYR', 'HK' => 'HKD' ];
		$cc = strtoupper( (string) $country );
		return in_array( $cc, $euro, true ) ? 'EUR' : ( $own[ $cc ] ?? 'USD' );
	}

	/** "us" where inches, °F and inches of rain are the custom (the United States and a few others), else "metric". Pure. */
	public static function units_for( $country ) {
		return in_array( strtoupper( (string) $country ), [ 'US', 'PR', 'LR', 'MM', 'FM', 'MH', 'PW' ], true ) ? 'us' : 'metric';
	}

	/** What a country calls its postal code. Pure apart from translation. */
	public static function postcode_label_for( $country ) {
		$t  = function_exists( '__' ) ? '__' : static function ( $s ) { return $s; };
		$cc = strtoupper( (string) $country );
		if ( in_array( $cc, [ 'US', 'PR', 'PH' ], true ) ) {
			return $t( 'ZIP', 'zorderz' );
		}
		if ( 'CA' === $cc ) {
			return $t( 'Postal code', 'zorderz' );
		}
		if ( 'IN' === $cc ) {
			return $t( 'PIN code', 'zorderz' );
		}
		if ( 'IE' === $cc ) {
			return $t( 'Eircode', 'zorderz' );
		}
		return $t( 'Postcode', 'zorderz' );
	}

	/** What a country calls the part of it an address names after the town. Pure apart from translation. */
	public static function region_label_for( $country ) {
		$t  = function_exists( '__' ) ? '__' : static function ( $s ) { return $s; };
		$cc = strtoupper( (string) $country );
		if ( in_array( $cc, [ 'US', 'AU', 'IN', 'MX', 'BR', 'MY', 'NG', 'DE', 'AT' ], true ) ) {
			return $t( 'State', 'zorderz' );
		}
		if ( in_array( $cc, [ 'CA', 'ZA', 'AR', 'ES', 'IT', 'NL', 'BE', 'PH' ], true ) ) {
			return $t( 'Province', 'zorderz' );
		}
		if ( in_array( $cc, [ 'GB', 'IE', 'KE' ], true ) ) {
			return $t( 'County', 'zorderz' );
		}
		return $t( 'Region', 'zorderz' );
	}

	/** The clock a country reads: "g:i a" (12-hour) where that is the custom, else "H:i". Pure. */
	public static function time_format_for( $country ) {
		return in_array( strtoupper( (string) $country ), [ 'US', 'PR', 'CA', 'AU', 'NZ', 'PH', 'IN', 'PK', 'BD', 'EG', 'SA', 'MY', 'CO', 'SV', 'HN', 'NI' ], true ) ? 'g:i a' : 'H:i';
	}

	/** The four ways numbers are grouped: pattern => [ decimal point, thousands separator ]. */
	const NUMBER_FORMATS = [
		'1,234.56' => [ '.', ',' ],
		'1.234,56' => [ ',', '.' ],
		'1 234,56' => [ ',', "\u{00A0}" ],
		"1'234.56" => [ '.', "\u{2019}" ],
	];

	/** How a language groups numbers ("de_DE" 1.234,56; "fr_FR" 1 234,56; "de_CH" 1'234.56). Pure. */
	public static function number_format_for( $language ) {
		$l    = str_replace( '-', '_', strtolower( trim( (string) $language ) ) );
		$lang = (string) strtok( $l, '_' );
		$reg  = strtoupper( (string) ( explode( '_', $l )[1] ?? '' ) );
		if ( 'CH' === $reg || 'LI' === $reg ) {
			return "1'234.56";
		}
		if ( in_array( $lang, [ 'fr', 'sv', 'nb', 'nn', 'no', 'fi', 'cs', 'sk', 'pl', 'ru', 'uk', 'bg', 'hu', 'lt', 'lv', 'et', 'be', 'kk' ], true ) || 'pt_pt' === $l ) {
			return '1 234,56';
		}
		if ( in_array( $lang, [ 'de', 'nl', 'es', 'it', 'pt', 'id', 'tr', 'da', 'el', 'ro', 'hr', 'sl', 'sr', 'vi', 'is' ], true ) && ! in_array( $l, [ 'es_mx', 'es_us', 'es_pr' ], true ) ) {
			return '1.234,56';
		}
		if ( 'en' === $lang && 'ZA' === $reg ) {
			return '1 234,56';
		}
		return '1,234.56';
	}

	/** A number grouped the business's way: number(12500.5, 2) "12,500.50" / "12.500,50". */
	public static function number( $value, $places = 0 ) {
		$sep = self::NUMBER_FORMATS[ (string) self::get( 'locale.number_format', '1,234.56' ) ] ?? self::NUMBER_FORMATS['1,234.56'];
		return number_format( (float) $value, max( 0, (int) $places ), $sep[0], $sep[1] );
	}

	/** "us" or "metric" (Business Profile → Locale → Units). */
	public static function units() {
		return 'metric' === self::get( 'locale.units', 'us' ) ? 'metric' : 'us';
	}

	/** True when the business measures in centimetres, °C and millimetres of rain. */
	public static function metric() {
		return 'metric' === self::units();
	}

	/** What the business calls a postal code ("ZIP", "Postcode"). */
	public static function postcode_label() {
		$v = trim( (string) self::get( 'locale.postcode_label', '' ) );
		return '' !== $v ? $v : self::postcode_label_for( self::country() );
	}

	/** What the business calls the region an address names ("State", "County"). */
	public static function region_label() {
		$v = trim( (string) self::get( 'locale.region_label', '' ) );
		return '' !== $v ? $v : self::region_label_for( self::country() );
	}

	/** The business's time format ("g:i a" or "H:i"). */
	public static function time_format() {
		$v = trim( (string) self::get( 'locale.time_format', '' ) );
		return '' !== $v ? $v : 'g:i a';
	}

	/** A temperature given in °F, written the business's way: "72°F", or "22°C" for a metric business. */
	public static function temperature( $fahrenheit ) {
		$f = (float) $fahrenheit;
		return self::metric() ? round( ( $f - 32 ) * 5 / 9 ) . '°C' : round( $f ) . '°F';
	}

	/** Rain given in inches, written the business's way: "0.25 in", or "6 mm" for a metric business. */
	public static function rain( $inches ) {
		$i = (float) $inches;
		return self::metric() ? self::number( round( $i * 25.4 ), 0 ) . ' mm' : self::number( $i, 2 ) . ' in';
	}

	/**
	 * A textual date format written month first, the way the United States writes it ("M j, Y", "D, M j", "l, F j, Y",
	 * "M j, Y g:i a"), turned into the business's: unchanged for a month-first business; otherwise day first ("j M Y",
	 * "D j M", "l j F Y"); and the clock in the business's time format. Escaped letters ("\a\t") are kept.
	 */
	public static function date_text( $format ) {
		$tokens = self::date_tokens( (string) $format );
		if ( 'n' !== self::date_order() ) {
			$tokens = self::day_first_tokens( $tokens );
		}
		$t = self::time_format();
		if ( 'g:i a' !== $t ) {
			$tokens = self::clock_tokens( $tokens, $t );
		}
		return implode( '', array_column( $tokens, 1 ) );
	}

	/** date_text() for a business that writes the day first, whatever its own setting. Pure. */
	public static function date_text_day_first( $format ) {
		return implode( '', array_column( self::day_first_tokens( self::date_tokens( (string) $format ) ), 1 ) );
	}

	/** A date format as tokens: [ 'f', letter ] for a format letter, [ 'l', text ] for anything written as it is. Pure. */
	private static function date_tokens( $format ) {
		$out = [];
		$len = strlen( $format );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $format[ $i ];
			if ( '\\' === $c && $i + 1 < $len ) {
				$out[] = [ 'l', $c . $format[ ++$i ] ];
			} elseif ( ctype_alpha( $c ) ) {
				$out[] = [ 'f', $c ];
			} elseif ( $out && 'l' === $out[ count( $out ) - 1 ][0] && '\\' !== $out[ count( $out ) - 1 ][1][0] ) {
				$out[ count( $out ) - 1 ][1] .= $c;
			} else {
				$out[] = [ 'l', $c ];
			}
		}
		return $out;
	}

	/** "M j[S][, Y]" → "j[S] M[ Y]", and "D, j"/"l, j" → "D j"/"l j". Pure. */
	private static function day_first_tokens( array $t ) {
		$is = static function ( $tok, $kind, $vals ) {
			return is_array( $tok ) && $kind === $tok[0] && in_array( $tok[1], (array) $vals, true );
		};
		$out = [];
		$n   = count( $t );
		for ( $i = 0; $i < $n; $i++ ) {
			if ( $is( $t[ $i ], 'f', [ 'M', 'F' ] ) && $is( $t[ $i + 1 ] ?? null, 'l', ' ' ) && $is( $t[ $i + 2 ] ?? null, 'f', [ 'j', 'd' ] ) ) {
				$month = $t[ $i ];
				$day   = [ $t[ $i + 2 ] ];
				$i    += 2;
				if ( $is( $t[ $i + 1 ] ?? null, 'f', 'S' ) ) {
					$day[] = $t[ ++$i ];
				}
				$year = null;
				if ( $is( $t[ $i + 1 ] ?? null, 'l', ', ' ) && $is( $t[ $i + 2 ] ?? null, 'f', [ 'Y', 'y' ] ) ) {
					$year = $t[ $i + 2 ];
					$i   += 2;
				}
				// "D, " or "l, " just before the day loses its comma: "D j M"
				$last = count( $out ) - 1;
				if ( $last >= 1 && $is( $out[ $last ], 'l', ', ' ) && $is( $out[ $last - 1 ], 'f', [ 'D', 'l' ] ) ) {
					$out[ $last ] = [ 'l', ' ' ];
				}
				foreach ( $day as $d ) {
					$out[] = $d;
				}
				$out[] = [ 'l', ' ' ];
				$out[] = $month;
				if ( $year ) {
					$out[] = [ 'l', ' ' ];
					$out[] = $year;
				}
				continue;
			}
			$out[] = $t[ $i ];
		}
		return $out;
	}

	/** "g:i a", "g:ia", "g:i A" → the business's time format. Pure. */
	private static function clock_tokens( array $t, $time_format ) {
		$out = [];
		$n   = count( $t );
		for ( $i = 0; $i < $n; $i++ ) {
			if ( 'f' === $t[ $i ][0] && 'g' === $t[ $i ][1] && [ 'l', ':' ] === ( $t[ $i + 1 ] ?? null ) && [ 'f', 'i' ] === ( $t[ $i + 2 ] ?? null ) ) {
				$j = $i + 3;
				if ( [ 'l', ' ' ] === ( $t[ $j ] ?? null ) && in_array( $t[ $j + 1 ] ?? null, [ [ 'f', 'a' ], [ 'f', 'A' ] ], true ) ) {
					$j += 2;
				} elseif ( in_array( $t[ $j ] ?? null, [ [ 'f', 'a' ], [ 'f', 'A' ] ], true ) ) {
					$j += 1;
				} else {
					$out[] = $t[ $i ];
					continue;
				}
				foreach ( self::date_tokens( (string) $time_format ) as $tok ) {
					$out[] = $tok;
				}
				$i = $j - 1;
				continue;
			}
			$out[] = $t[ $i ];
		}
		return $out;
	}

	/** The first thing the business's short date writes: "n" (month), "j" (day) or "Y" (year). */
	public static function date_order() {
		foreach ( self::date_tokens( self::date_format() ) as $tok ) {
			if ( 'f' === $tok[0] && in_array( $tok[1], [ 'n', 'm', 'j', 'd', 'Y', 'y' ], true ) ) {
				return strtr( $tok[1], [ 'm' => 'n', 'd' => 'j', 'y' => 'Y' ] );
			}
		}
		return 'n';
	}

	/** What a page's script needs to write the business's way (zdzData.l10n). */
	public static function l10n_for_js() {
		$sep = self::NUMBER_FORMATS[ (string) self::get( 'locale.number_format', '1,234.56' ) ] ?? self::NUMBER_FORMATS['1,234.56'];
		return [
			'country'       => self::country(),
			'units'         => self::units(),
			'postcodeLabel' => self::postcode_label(),
			'regionLabel'   => self::region_label(),
			'dateOrder'     => self::date_order(),
			'hour12'        => false !== strpbrk( self::time_format(), 'gh' ),
			'decimal'       => $sep[0],
			'thousands'     => $sep[1],
		];
	}

	/** Deep merge, where a stored scalar or list replaces the default outright. */
	private static function merge( array $base, array $over ) {
		foreach ( $over as $k => $v ) {
			if ( [] === $v && isset( $base[ $k ] ) && is_array( $base[ $k ] ) && $base[ $k ] && ! array_is_list( $base[ $k ] ) ) {
				continue; // an empty group stored (a JSON "[]" for "{}") keeps every default inside it
			}
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
	 * supplied: the nav button, an avatar placeholder. Derived from the profile so
	 * no install ever shows a fixed two-letter code before its own artwork is
	 * uploaded.
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
		add_filter( 'zdz_business_profile_choices', [ __CLASS__, 'locale_choices' ] );
	}

	/** The Locale screen's choices; the blank one follows the country. */
	public static function locale_choices( $choices ) {
		$choices = is_array( $choices ) ? $choices : [];
		$cc      = self::country();
		$follow  = static function ( $now ) {
			/* translators: %s: the value the country gives. */
			return sprintf( __( 'Follow the country (%s)', 'zorderz' ), $now );
		};
		$units = [ 'us' => __( 'US: inches, °F, inches of rain', 'zorderz' ), 'metric' => __( 'Metric: centimetres, °C, millimetres of rain', 'zorderz' ) ];
		$choices['locale.units'] = [ '' => $follow( $units[ self::units_for( $cc ) ] ) ] + $units;
		$nums = [];
		foreach ( array_keys( self::NUMBER_FORMATS ) as $pattern ) {
			$nums[ $pattern ] = $pattern;
		}
		$lang = self::language_for_country( (string) ( self::get( 'locale.locale', '' ) ?: ( function_exists( 'get_locale' ) ? get_locale() : '' ) ), $cc );
		$choices['locale.number_format'] = [ '' => $follow( self::number_format_for( $lang ) ) ] + $nums;
		$times = [ 'g:i a' => __( '2:30 pm', 'zorderz' ), 'g:i A' => __( '2:30 PM', 'zorderz' ), 'H:i' => __( '14:30', 'zorderz' ) ];
		$choices['locale.time_format'] = [ '' => $follow( $times[ self::time_format_for( $cc ) ] ) ] + $times;
		return $choices;
	}

	/**
	 * Build the brand override CSS, or '' when the business has changed nothing.
	 *
	 * This is the whole aesthetic-parity mechanism. The stylesheet defines the
	 * --ref-brand-* ramp and derives every --sys-* colour from it, so replacing
	 * those eleven values re-skins the entire interface in all four theme modes
	 * without touching CSS. The --cat-* tokens are the same trick for the
	 * per-category colours that custom styles and add-ons use.
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
	 * Replaces a static manifest.json, whose fixed name and colours could not
	 * follow the profile and whose icons pointed at a theme folder that no
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
			'background_color' => $pwa['background_color'] ?? '#433FBD',
			'theme_color'      => $pwa['theme_color'] ?? '#433FBD',
			'icons'            => $icons,
		];
	}
}

ZDZ_Business_Profile::init();
