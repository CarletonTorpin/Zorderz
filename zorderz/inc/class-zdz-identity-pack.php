<?php
/**
 * Zorderz — Identity Packs
 *
 * An Identity Pack is a business, as data. It carries everything true about one
 * company — names, brand, contact, senders, locale, roles — in plain files that
 * can be read, reviewed, versioned and re-imported. Applying a pack to a stock
 * Zorderz install is what turns a blank platform into that company's app.
 *
 * This is the other half of the Business Profile. The profile is where a
 * business's values live; a pack is how they travel.
 *
 * WHAT V1 CONSUMES
 *   profile.yml  → ZDZ_Business_Profile (identity, contact, web, senders, brand, locale)
 *   org.yml      → role labels and per-role app grants
 *
 * A pack may contain more files than this build understands — catalog, pricing,
 * costs, flows, rules and the rest of the fifteen. Unknown files are reported,
 * never silently ignored, so it is always obvious what a pack carries that the
 * platform cannot yet use.
 *
 * PRINCIPLES, learned the hard way
 *   - Nothing applies without an explicit, logged human decision. The previous
 *     architecture seeded one company's business data into every fresh install
 *     on activation; that must never be possible again.
 *   - Preview before apply. The diff is shown, not summarised.
 *   - Reversible. A snapshot is taken before writing.
 *   - Provenance stamped. Every applied value records which pack it came from.
 *   - Secrets are never in a pack. Credentials are referenced, never carried.
 *
 * WHERE PACKS LIVE
 *   wp-content/zorderz-identity/<pack>/      (site-owned, preferred)
 *   <theme>/identity-packs/<pack>/           (shipped examples)
 *
 * @package Zorderz
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Identity_Pack {

	const APPLIED_OPTION  = 'zdz_identity_pack_applied';
	const SNAPSHOT_OPTION = 'zdz_identity_pack_snapshot';
	const LOG_OPTION      = 'zdz_identity_pack_log';

	/** Files this build knows how to apply. */
	const SUPPORTED = [ 'profile', 'org' ];

	/** Files a full pack may contain, so unsupported ones can be named precisely. */
	const KNOWN = [
		'profile', 'parties', 'org', 'catalog', 'pricing', 'costs', 'compensation',
		'territories', 'connections', 'mappings', 'document-conventions', 'flows',
		'rules', 'knowledge', 'voice',
	];

	/** @return string[] Absolute directories that may contain packs. */
	public static function search_paths() {
		$paths = [
			WP_CONTENT_DIR . '/zorderz-identity',
			get_template_directory() . '/identity-packs',
		];
		return apply_filters( 'zdz_identity_pack_paths', $paths );
	}

	/**
	 * Discover available packs.
	 *
	 * @return array<string,array> slug => [ label, dir, files, supported, unsupported ]
	 */
	public static function discover() {
		$found = [];
		foreach ( self::search_paths() as $root ) {
			if ( ! is_dir( $root ) ) {
				continue;
			}
			foreach ( (array) glob( $root . '/*', GLOB_ONLYDIR ) as $dir ) {
				$slug = basename( $dir );
				if ( isset( $found[ $slug ] ) ) {
					continue; // earlier search path wins
				}
				$files = [];
				foreach ( (array) glob( $dir . '/*.{yml,yaml,json}', GLOB_BRACE ) as $f ) {
					$files[] = basename( $f );
				}
				if ( ! $files ) {
					continue;
				}
				$stems       = array_map( fn( $f ) => preg_replace( '/\.(ya?ml|json)$/', '', $f ), $files );
				$supported   = array_values( array_intersect( $stems, self::SUPPORTED ) );
				// `pack` is the pack's own metadata, not content — it is read for
				// the label, so reporting it as "cannot apply" is just confusing.
				$unsupported = array_values( array_diff( $stems, self::SUPPORTED, [ 'pack' ] ) );
				$found[ $slug ] = [
					'label'       => self::read_label( $dir, $slug ),
					'dir'         => $dir,
					'files'       => $files,
					'supported'   => $supported,
					'unsupported' => $unsupported,
				];
			}
		}
		return $found;
	}

	private static function read_label( $dir, $slug ) {
		$meta = self::read_file( $dir, 'pack' );
		if ( is_array( $meta ) && ! empty( $meta['label'] ) ) {
			return (string) $meta['label'];
		}
		$profile = self::read_file( $dir, 'profile' );
		if ( is_array( $profile ) && ! empty( $profile['identity']['trading_name'] ) ) {
			return (string) $profile['identity']['trading_name'];
		}
		return ucwords( str_replace( [ '-', '_' ], ' ', $slug ) );
	}

	/**
	 * Read one pack file. JSON is preferred when both exist (exact parse);
	 * YAML is accepted because it is the authoring format.
	 *
	 * @return array|null
	 */
	public static function read_file( $dir, $stem ) {
		foreach ( [ 'json', 'yml', 'yaml' ] as $ext ) {
			$path = $dir . '/' . $stem . '.' . $ext;
			if ( ! is_readable( $path ) ) {
				continue;
			}
			$raw = file_get_contents( $path );
			if ( false === $raw ) {
				continue;
			}
			if ( 'json' === $ext ) {
				$d = json_decode( $raw, true );
				return is_array( $d ) ? $d : null;
			}
			return self::parse_yaml( $raw );
		}
		return null;
	}

	/**
	 * Build a preview of what applying a pack would change.
	 *
	 * @return array{pack:string,label:string,changes:array,unsupported:array,errors:array}
	 */
	public static function preview( $slug ) {
		$packs = self::discover();
		if ( ! isset( $packs[ $slug ] ) ) {
			return [ 'pack' => $slug, 'label' => $slug, 'changes' => [], 'unsupported' => [], 'errors' => [ 'Pack not found.' ] ];
		}
		$pack    = $packs[ $slug ];
		$errors  = [];
		$changes = [];

		$unconsumed = [];

		$profile = self::read_file( $pack['dir'], 'profile' );
		if ( null === $profile ) {
			if ( in_array( 'profile', $pack['supported'], true ) ) {
				$errors[] = 'profile file present but could not be parsed.';
			}
		} else {
			$norm       = self::normalize_profile( $profile );
			$unconsumed = $norm['unconsumed'];
			foreach ( self::flatten( $norm['values'] ) as $path => $new ) {
				$old = ZDZ_Business_Profile::get( $path, '' );
				$a   = is_array( $old ) ? wp_json_encode( $old ) : (string) $old;
				$b   = is_array( $new ) ? wp_json_encode( $new ) : (string) $new;
				if ( $a !== $b ) {
					$changes[] = [ 'file' => 'profile', 'path' => $path, 'from' => $a, 'to' => $b ];
				}
			}
		}

		foreach ( self::read_org_roles( $pack['dir'] ) as $role_slug => $def ) {
			if ( ! get_role( $role_slug ) ) {
				$changes[] = [ 'file' => 'org', 'path' => "roles.$role_slug", 'from' => '(role not registered)', 'to' => 'SKIPPED — Zorderz owns role slugs' ];
				continue;
			}
			$names = wp_roles()->role_names;
			if ( isset( $def['label'] ) && $def['label'] !== ( $names[ $role_slug ] ?? '' ) ) {
				$changes[] = [ 'file' => 'org', 'path' => "roles.$role_slug.label", 'from' => (string) ( $names[ $role_slug ] ?? '' ), 'to' => (string) $def['label'] ];
			}
			if ( array_key_exists( 'default_apps', $def ) ) {
				$before = ZDZ_User_Roles::get_default_apps_for_role( $role_slug );
				$changes[] = [
					'file' => 'org',
					'path' => "roles.$role_slug.default_apps",
					'from' => null === $before ? '(all apps)' : wp_json_encode( $before ),
					'to'   => true === $def['default_apps'] ? '(all apps)' : wp_json_encode( $def['default_apps'] ),
				];
			}
		}

		return [
			'pack'        => $slug,
			'label'       => $pack['label'],
			'changes'     => $changes,
			'unsupported' => $pack['unsupported'],
			'unconsumed'  => $unconsumed,
			'errors'      => $errors,
		];
	}

	/**
	 * Read role definitions out of a pack's org file, in either shape.
	 *
	 * The canonical schema writes roles as a list of records with a `slug` field;
	 * a hand-written pack is likelier to write a map keyed by slug. Both are
	 * accepted. `default_apps` collapses to either a flat list of app ids or the
	 * boolean true meaning "every app", so the caller never has to interpret the
	 * `{all: true}` sentinel or the allowed/pinned split — v1 has one grant list.
	 *
	 * @return array<string,array{label?:string,default_apps?:array|bool}>
	 */
	private static function read_org_roles( $dir ) {
		$org = self::read_file( $dir, 'org' );
		if ( ! is_array( $org ) || empty( $org['roles'] ) ) {
			return [];
		}
		$out = [];
		foreach ( (array) $org['roles'] as $key => $def ) {
			if ( ! is_array( $def ) ) {
				continue;
			}
			$slug = is_string( $key ) ? $key : ( $def['slug'] ?? '' );
			if ( '' === $slug ) {
				continue;
			}
			$row = [];
			if ( ! empty( $def['label'] ) ) {
				$row['label'] = (string) $def['label'];
			}
			if ( array_key_exists( 'default_apps', $def ) ) {
				$apps = $def['default_apps'];
				if ( is_array( $apps ) && array_key_exists( 'allowed', $apps ) ) {
					$apps = $apps['allowed'];
				}
				if ( is_array( $apps ) && ! empty( $apps['all'] ) ) {
					$row['default_apps'] = true;
				} elseif ( is_array( $apps ) ) {
					$row['default_apps'] = array_values( array_filter( $apps, 'is_string' ) );
				}
			}
			if ( $row ) {
				$out[ $slug ] = $row;
			}
		}
		return $out;
	}

	/**
	 * Apply a pack. Requires an explicit confirmation token, so nothing can
	 * apply a pack by accident or as a side effect of loading.
	 *
	 * @param string $slug    Pack slug.
	 * @param bool   $confirm Must be true. Present so callers cannot apply implicitly.
	 * @return array{ok:bool,applied:int,messages:string[]}
	 */
	public static function apply( $slug, $confirm = false ) {
		$msgs = [];
		if ( ! $confirm ) {
			return [ 'ok' => false, 'applied' => 0, 'messages' => [ 'Refused: applying a pack requires explicit confirmation.' ] ];
		}
		$packs = self::discover();
		if ( ! isset( $packs[ $slug ] ) ) {
			return [ 'ok' => false, 'applied' => 0, 'messages' => [ 'Pack not found.' ] ];
		}
		$pack = $packs[ $slug ];

		// Snapshot first, so this is reversible.
		update_option( self::SNAPSHOT_OPTION, [
			'taken_at'   => gmdate( 'c' ),
			'profile'    => get_option( ZDZ_Business_Profile::OPTION, [] ),
			'role_names' => wp_roles()->role_names,
		], false );

		$applied = 0;

		$profile = self::read_file( $pack['dir'], 'profile' );
		if ( is_array( $profile ) ) {
			$norm = self::normalize_profile( $profile );
			ZDZ_Business_Profile::set( $norm['values'], 'pack:' . $slug );
			ZDZ_Business_Profile::set( [ '_meta' => [ 'pack' => $slug, 'pack_label' => $pack['label'] ] ], 'pack:' . $slug );
			$applied++;
			$msgs[] = sprintf(
				'profile applied (%s schema, %d value%s).',
				$norm['schema'],
				count( self::flatten( $norm['values'] ) ),
				1 === count( self::flatten( $norm['values'] ) ) ? '' : 's'
			);
			if ( $norm['unconsumed'] ) {
				$msgs[] = sprintf(
					'%d profile key(s) carried but not used by this version: %s',
					count( $norm['unconsumed'] ),
					implode( ', ', array_slice( $norm['unconsumed'], 0, 12 ) )
						. ( count( $norm['unconsumed'] ) > 12 ? ' …' : '' )
				);
			}
		}

		$roles = self::read_org_roles( $pack['dir'] );
		if ( $roles ) {
			$labels = [];
			$grants = [];
			foreach ( $roles as $role_slug => $def ) {
				// Role SLUGS belong to the platform. A pack may relabel a role and
				// set its default apps; it may not invent or rename slugs, because
				// several are matched as literal strings by security checks.
				if ( ! get_role( $role_slug ) ) {
					$msgs[] = "role '$role_slug' skipped (not a Zorderz role).";
					continue;
				}
				if ( isset( $def['label'] ) ) {
					$labels[ $role_slug ] = $def['label'];
				}
				if ( array_key_exists( 'default_apps', $def ) ) {
					$grants[ $role_slug ] = $def['default_apps'];
				}
			}
			if ( $labels ) {
				update_option( 'zdz_role_labels', $labels, false );
				$applied++;
				$msgs[] = count( $labels ) . ' role label(s) applied.';
			}
			if ( $grants ) {
				update_option( 'zdz_role_default_apps', $grants, false );
				$applied++;
				$msgs[] = count( $grants ) . ' role app-grant set(s) applied.';
			}
		}

		foreach ( $pack['unsupported'] as $stem ) {
			$msgs[] = "'$stem' is in the pack but this version cannot apply it yet — left untouched.";
		}

		update_option( self::APPLIED_OPTION, [
			'pack'       => $slug,
			'label'      => $pack['label'],
			'applied_at' => gmdate( 'c' ),
			'by'         => get_current_user_id(),
			'files'      => $pack['files'],
		], false );

		$log = (array) get_option( self::LOG_OPTION, [] );
		$log[] = [ 'at' => gmdate( 'c' ), 'pack' => $slug, 'by' => get_current_user_id(), 'messages' => $msgs ];
		update_option( self::LOG_OPTION, array_slice( $log, -25 ), false );

		return [ 'ok' => true, 'applied' => $applied, 'messages' => $msgs ];
	}

	/**
	 * Restore the snapshot taken before the most recent apply.
	 *
	 * ONE STEP DEEP, deliberately. There is a single snapshot slot, so applying a
	 * second pack replaces the first pack's snapshot — reverting then returns you
	 * to the first pack, not to a blank install. To get all the way back to
	 * neutral, clear the profile from the Business Profile screen.
	 */
	public static function revert() {
		$snap = get_option( self::SNAPSHOT_OPTION, null );
		if ( ! is_array( $snap ) ) {
			return [ 'ok' => false, 'messages' => [ 'No snapshot to revert to.' ] ];
		}
		update_option( ZDZ_Business_Profile::OPTION, $snap['profile'] ?? [], false );
		// Writing the option directly bypasses set(), so the resolved cache is
		// still holding the profile we just replaced. Without this, every read
		// for the rest of the request reports the revert as having done nothing.
		ZDZ_Business_Profile::flush();
		delete_option( 'zdz_role_labels' );
		delete_option( 'zdz_role_default_apps' );
		delete_option( self::APPLIED_OPTION );

		$log   = (array) get_option( self::LOG_OPTION, [] );
		$log[] = [
			'at'       => gmdate( 'c' ),
			'pack'     => '(revert)',
			'by'       => get_current_user_id(),
			'messages' => [ 'Reverted to the snapshot taken at ' . ( $snap['taken_at'] ?? 'unknown' ) . '.' ],
		];
		update_option( self::LOG_OPTION, array_slice( $log, -25 ), false );

		return [ 'ok' => true, 'messages' => [ 'Reverted to the snapshot taken at ' . ( $snap['taken_at'] ?? 'unknown' ) . '.' ] ];
	}

	/** Which pack is currently applied, if any. */
	public static function applied() {
		$a = get_option( self::APPLIED_OPTION, null );
		return is_array( $a ) ? $a : null;
	}

	// ─────────────────────────────────────────────── schema translation

	/**
	 * Straight one-to-one moves from the canonical pack schema to the profile's
	 * internal shape. Everything structural is handled in normalize_profile().
	 *
	 * @var array<string,string> pack dot-path => profile dot-path
	 */
	const PROFILE_MAP = [
		'identity.legal_name'              => 'identity.legal_name',
		'identity.trading_name'            => 'identity.trading_name',
		'identity.short_name'              => 'identity.short_name',
		'identity.former_names'            => 'identity.former_names',
		'identity.short_code'              => 'identity.short_code',
		'identity.founded'                 => 'identity.founded',
		'identity.industry_descriptor'     => 'identity.industry',
		'identity.publisher_display_name'  => 'identity.publisher',
		'brand.taglines.product_tagline'   => 'identity.tagline',
		'domains.app'                      => 'web.app_domain',
		'domains.marketing'                => 'web.marketing_domain',
		'brand.assets.cdn_host'            => 'web.asset_cdn_host',
		'contact.address.street'           => 'contact.address.street',
		'contact.address.locality'         => 'contact.address.locality',
		'contact.address.region'           => 'contact.address.region',
		'contact.address.postcode'         => 'contact.address.postal',
		'contact.address.country'          => 'contact.address.country',
		'brand.color_tokens.brand_ramp'    => 'brand.ramp',
		'brand.color_tokens.categories'    => 'brand.categories',
		'brand.pwa.theme_color'            => 'brand.pwa.theme_color',
		'brand.pwa.background_color'       => 'brand.pwa.background_color',
		'locale.timezone'                  => 'locale.timezone',
		'locale.currency'                  => 'locale.currency',
		'locale.locale'                    => 'locale.locale',
	];

	/**
	 * Which sending purposes the canonical schema calls something else.
	 * Pack purpose => profile sender key.
	 */
	const SENDER_MAP = [
		'survey'                 => 'surveys',
		'receipt'                => 'documents',
		'document'               => 'documents',
		'alert'                  => 'alerts',
		'messaging_notification' => 'messaging',
		'default'                => 'default',
	];

	/**
	 * Translate a pack's profile file into the Business Profile's own shape.
	 *
	 * WHY A TRANSLATOR EXISTS AT ALL
	 * The pack format is the documented long-term schema: phones are an ordered
	 * list with one canonical entry, senders are a list keyed by purpose that can
	 * inherit from each other, registrations repeat, review destinations are typed.
	 * The v1 Business Profile is deliberately flatter than that, because it only
	 * stores what this build actually reads. Writing the packs in the flat shape
	 * would mean re-authoring every pack when the richer consumers land; carrying
	 * the richer shape and translating means the pack outlives this version.
	 *
	 * A pack written directly in the profile's own shape is also accepted — that
	 * is the easy path for someone hand-writing one — and is detected, not guessed.
	 *
	 * @return array{values:array,unconsumed:array<string>,schema:string}
	 */
	public static function normalize_profile( array $p ) {
		// A pack in the flat/native shape passes through untouched.
		if ( ! self::looks_canonical( $p ) ) {
			unset( $p['pack'], $p['_meta'] );
			return [ 'values' => $p, 'unconsumed' => [], 'schema' => 'profile' ];
		}

		$out  = [];
		$seen = [];

		foreach ( self::PROFILE_MAP as $from => $to ) {
			$v = self::dig( $p, $from );
			if ( null === $v || '' === $v || [] === $v ) {
				continue;
			}
			self::plant( $out, $to, $v );
			$seen[ $from ] = true;
		}

		// Display name is the pack's headline field; the profile calls the same
		// idea trading_name and derives everything else from it.
		$display = self::dig( $p, 'identity.display_name' );
		if ( $display && empty( $out['identity']['trading_name'] ) ) {
			self::plant( $out, 'identity.trading_name', $display );
		}
		if ( $display ) {
			$seen['identity.display_name'] = true;
		}

		// Phones: an ordered list with exactly one canonical entry becomes the
		// primary, and the next one becomes the alternate. Order is not authority —
		// the is_canonical flag is, which is the point of carrying the list.
		$phones = self::dig( $p, 'contact.phones' );
		if ( is_array( $phones ) && $phones ) {
			$primary = null;
			$alt     = null;
			foreach ( $phones as $ph ) {
				$num = is_array( $ph ) ? ( $ph['number'] ?? '' ) : (string) $ph;
				if ( '' === $num ) {
					continue;
				}
				if ( is_array( $ph ) && ! empty( $ph['is_canonical'] ) && null === $primary ) {
					$primary = $num;
				} elseif ( null === $alt ) {
					$alt = $num;
				}
			}
			if ( null === $primary ) {          // no flag set: fall back to first
				$primary = $alt;
				$alt     = null;
			}
			if ( $primary ) self::plant( $out, 'contact.phone', $primary );
			if ( $alt )     self::plant( $out, 'contact.phone_alt', $alt );
			$seen['contact.phones'] = true;
		}

		// Hours: prefer the business's own display string; otherwise render the
		// structured schedule rather than losing it.
		$hours = self::dig( $p, 'hours.display_override' );
		if ( ! $hours ) {
			$hours = self::render_hours( self::dig( $p, 'hours.office' ) );
		}
		if ( $hours ) {
			self::plant( $out, 'contact.hours', $hours );
			$seen['hours.display_override'] = true;
			$seen['hours.office']           = true;
		}

		// Staff address pattern, with the pack's own domain substituted in.
		$pattern = (string) self::dig( $p, 'email.staff_address_pattern' );
		$domain  = (string) self::dig( $p, 'email.primary_domain' );
		if ( '' !== $pattern ) {
			if ( '' !== $domain ) {
				$pattern = str_replace( [ '{primary_domain}', '{email_domain}' ], $domain, $pattern );
			}
			self::plant( $out, 'people.staff_email_pattern', $pattern );
			$seen['email.staff_address_pattern'] = true;
			$seen['email.primary_domain']        = true;
		}

		// Senders, resolving `inherits` one level. A sender that inherits and
		// overrides nothing is exactly the duplicated-literal problem this replaces.
		$senders = self::dig( $p, 'messaging.senders' );
		if ( is_array( $senders ) && $senders ) {
			$by_purpose = [];
			foreach ( $senders as $s ) {
				if ( is_array( $s ) && ! empty( $s['purpose'] ) ) {
					$by_purpose[ (string) $s['purpose'] ] = $s;
				}
			}
			foreach ( $by_purpose as $purpose => $s ) {
				$key = self::SENDER_MAP[ $purpose ] ?? null;
				if ( null === $key ) {
					continue;                    // reported as unconsumed below
				}
				$src = $s;
				if ( ! empty( $s['inherits'] ) && isset( $by_purpose[ $s['inherits'] ] ) ) {
					$src = array_merge( $by_purpose[ $s['inherits'] ], array_filter( $s ) );
				}
				$name  = (string) ( $src['from_name'] ?? '' );
				$email = (string) ( $src['from_address'] ?? '' );
				if ( '' === $name && '' === $email ) {
					continue;
				}
				self::plant( $out, "senders.$key.name", $name );
				self::plant( $out, "senders.$key.email", $email );
			}
			// The company-level alert sender is the best default: it is the one
			// addressed as the business rather than as a person.
			if ( empty( $out['senders']['default']['email'] ) && ! empty( $out['senders']['alerts']['email'] ) ) {
				self::plant( $out, 'senders.default.name', $out['senders']['alerts']['name'] );
				self::plant( $out, 'senders.default.email', $out['senders']['alerts']['email'] );
			}
			if ( ! empty( $out['senders']['default']['email'] ) ) {
				self::plant( $out, 'contact.email', $out['senders']['default']['email'] );
			}
			$seen['messaging.senders'] = true;
		}

		// Review destinations, by provider rather than by position.
		$reviews = self::dig( $p, 'reviews.destinations' );
		if ( is_array( $reviews ) ) {
			foreach ( $reviews as $d ) {
				if ( ! is_array( $d ) || empty( $d['url'] ) ) {
					continue;
				}
				$provider = (string) ( $d['provider'] ?? '' );
				if ( 'google_business' === $provider || 'google' === $provider ) {
					self::plant( $out, 'web.review_google', $d['url'] );
				} elseif ( 'on_site_form' === $provider || 'website' === $provider ) {
					self::plant( $out, 'web.review_page', $d['url'] );
				}
			}
			$seen['reviews.destinations'] = true;
		}

		// Registrations: whichever is marked for the ID card becomes the licence
		// line. The rest stay in the pack until a repeatable consumer exists.
		$regs = self::dig( $p, 'compliance.registrations' );
		if ( is_array( $regs ) ) {
			foreach ( $regs as $r ) {
				if ( ! is_array( $r ) || empty( $r['show_on_card'] ) ) {
					continue;
				}
				$label = (string) ( $r['label'] ?? '' );
				if ( '' === $label && ! empty( $r['number'] ) ) {
					$label = 'License #' . $r['number'];
				}
				if ( '' !== $label ) {
					self::plant( $out, 'identity.license', $label );
					$seen['compliance.registrations'] = true;
					break;
				}
			}
		}

		/*
		 * Logo artwork, in two shapes × two modes.
		 *
		 * Two spellings are accepted. The explicit one — brand.logo.wide.light and
		 * friends — is what a pack should use. The older flat names are mapped for
		 * packs authored before the shapes existed: a "primary logo" is the wide
		 * lockup and an "icon" is the square mark, which is what those words meant
		 * in practice.
		 *
		 * A value like "@media:icon-512" is a promise, not a URL — it names an
		 * attachment a human still has to upload. Applying it verbatim would
		 * produce broken images that LOOK configured, so those are skipped and
		 * surface in the unconsumed list where somebody will see them.
		 */
		$logo_sources = [
			'brand.logo.wide.light'      => 'brand.logo.wide.light',
			'brand.logo.wide.dark'       => 'brand.logo.wide.dark',
			'brand.logo.square.light'    => 'brand.logo.square.light',
			'brand.logo.square.dark'     => 'brand.logo.square.dark',
			'brand.assets.logo_wide'     => 'brand.logo.wide.light',
			'brand.assets.logo_wide_dark'=> 'brand.logo.wide.dark',
			'brand.assets.logo_square'   => 'brand.logo.square.light',
			'brand.assets.logo_square_dark' => 'brand.logo.square.dark',
			'brand.assets.primary_logo'  => 'brand.logo.wide.light',
			'brand.assets.logo_dark'     => 'brand.logo.wide.dark',
			'brand.assets.icon_512'      => 'brand.logo.square.light',
			'brand.assets.favicon'       => 'brand.logo.favicon',
		];
		foreach ( $logo_sources as $from => $to ) {
			// An explicit shape already written wins over a legacy alias.
			if ( null !== self::dig( $out, $to ) && '' !== self::dig( $out, $to ) ) {
				continue;
			}
			$v = self::dig( $p, $from );
			if ( is_string( $v ) && '' !== $v && ! str_starts_with( $v, '@media:' ) ) {
				self::plant( $out, $to, $v );
				$seen[ $from ] = true;
			}
		}

		// Anything the pack carries that nothing above claimed. Reported, never
		// silently dropped — a pack should always be able to tell you what of it
		// this version could not yet use.
		$unconsumed = [];
		foreach ( self::flatten( $p ) as $path => $value ) {
			if ( 'pack' === $path || str_starts_with( $path, 'pack.' ) || str_starts_with( $path, '_meta' ) ) {
				continue;
			}
			// "Unconsumed" must mean "had a value this version could not use". A
			// declared-but-empty key is a placeholder the business hasn't filled
			// in — reporting it as unusable would read like the mechanism failed.
			if ( '' === $value || null === $value || [] === $value ) {
				continue;
			}
			foreach ( $seen as $claimed => $_ ) {
				if ( $path === $claimed || str_starts_with( $path, $claimed . '.' ) ) {
					continue 2;
				}
			}
			$unconsumed[] = $path;
		}

		return [ 'values' => $out, 'unconsumed' => $unconsumed, 'schema' => 'canonical' ];
	}

	/** Is this the canonical pack schema, or already the profile's own shape? */
	private static function looks_canonical( array $p ) {
		return isset( $p['domains'] ) || isset( $p['messaging'] ) || isset( $p['compliance'] )
			|| isset( $p['reviews'] ) || isset( $p['brand']['color_tokens'] )
			|| isset( $p['contact']['phones'] ) || isset( $p['hours'] );
	}

	/** Read a dot path out of a nested array. Returns null when absent. */
	private static function dig( array $a, $path ) {
		$node = $a;
		foreach ( explode( '.', $path ) as $seg ) {
			if ( ! is_array( $node ) || ! array_key_exists( $seg, $node ) ) {
				return null;
			}
			$node = $node[ $seg ];
		}
		return $node;
	}

	/** Write a value into a nested array at a dot path. */
	private static function plant( array &$a, $path, $value ) {
		$segs = explode( '.', $path );
		$node = &$a;
		foreach ( $segs as $seg ) {
			if ( ! isset( $node[ $seg ] ) || ! is_array( $node[ $seg ] ) ) {
				$node[ $seg ] = [];
			}
			$node = &$node[ $seg ];
		}
		$node = $value;
	}

	/** Render a structured weekly schedule to a display string. */
	private static function render_hours( $office ) {
		if ( ! is_array( $office ) || ! $office ) {
			return '';
		}
		$parts = [];
		foreach ( $office as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$days = (array) ( $block['days'] ?? [] );
			if ( ! $days ) {
				continue;
			}
			$names = array_map( fn( $d ) => ucfirst( substr( (string) $d, 0, 3 ) ), $days );
			$span  = count( $names ) > 1 ? $names[0] . '–' . end( $names ) : $names[0];
			$open  = (string) ( $block['open'] ?? '' );
			$close = (string) ( $block['close'] ?? '' );
			$parts[] = trim( $span . ' ' . trim( $open . ( $close ? '–' . $close : '' ) ) );
		}
		return implode( ', ', $parts );
	}

	// ─────────────────────────────────────────────────── helpers

	/** Flatten a nested array to dot paths, keeping lists whole. */
	public static function flatten( array $in, $prefix = '' ) {
		$out = [];
		foreach ( $in as $k => $v ) {
			$path = '' === $prefix ? (string) $k : $prefix . '.' . $k;
			if ( is_array( $v ) && ! array_is_list( $v ) ) {
				$out += self::flatten( $v, $path );
			} else {
				$out[ $path ] = $v;
			}
		}
		return $out;
	}

	/**
	 * Parse the subset of YAML an Identity Pack uses: nested maps by indentation,
	 * lists, scalars, quoted strings, comments, booleans and null.
	 *
	 * Deliberately not a full YAML implementation — no anchors, tags, multi-line
	 * scalars or flow mappings. A pack that needs those should ship JSON instead,
	 * which the loader prefers anyway. Keeping this small avoids adding a
	 * dependency to a WordPress theme for the sake of a config file.
	 */
	public static function parse_yaml( $raw ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $raw );
		$root  = [];
		// Stack of [ indent, &container ]
		$stack = [ [ -1, &$root ] ];

		foreach ( $lines as $line ) {
			if ( '' === trim( $line ) || preg_match( '/^\s*#/', $line ) ) {
				continue;
			}
			$indent = strlen( $line ) - strlen( ltrim( $line, ' ' ) );
			$text   = trim( $line );

			// Pop to the parent at a smaller indent.
			while ( count( $stack ) > 1 && $indent <= $stack[ count( $stack ) - 1 ][0] ) {
				array_pop( $stack );
			}
			$parent = &$stack[ count( $stack ) - 1 ][1];

			// List item
			if ( str_starts_with( $text, '- ' ) || '-' === $text ) {
				$val = trim( substr( $text, 1 ) );
				if ( ! is_array( $parent ) ) {
					$parent = [];
				}
				if ( '' === $val ) {
					$parent[] = [];
					$idx      = count( $parent ) - 1;
					$stack[]  = [ $indent, &$parent[ $idx ] ];
				} elseif ( str_starts_with( $val, '{' ) || str_starts_with( $val, '[' ) ) {
					// A whole flow map or list as one list item: `- { key: value }`.
					// This must be checked BEFORE the key:value branch below, whose
					// regex would otherwise split on the first colon and produce a
					// key of `{ key`.
					$parent[] = self::scalar( $val );
				} elseif ( preg_match( '/^([^:]+):\s*(.*)$/', $val, $m ) ) {
					// inline map inside a list item
					$item = [];
					$key  = self::clean_key( $m[1] );
					$item[ $key ] = self::scalar( $m[2] );
					$parent[]     = $item;
					$idx          = count( $parent ) - 1;
					$stack[]      = [ $indent, &$parent[ $idx ] ];
				} else {
					$parent[] = self::scalar( $val );
				}
				continue;
			}

			// key: value
			if ( preg_match( '/^("[^"]*"|\'[^\']*\'|[^:]+):\s*(.*)$/', $text, $m ) ) {
				$key = self::clean_key( $m[1] );
				$val = trim( $m[2] );
				if ( ! is_array( $parent ) ) {
					$parent = [];
				}
				if ( '' === $val ) {
					$parent[ $key ] = [];
					$stack[]        = [ $indent, &$parent[ $key ] ];
				} else {
					$parent[ $key ] = self::scalar( $val );
				}
			}
		}
		return $root;
	}

	private static function clean_key( $k ) {
		$k = trim( $k );
		if ( ( str_starts_with( $k, '"' ) && str_ends_with( $k, '"' ) ) || ( str_starts_with( $k, "'" ) && str_ends_with( $k, "'" ) ) ) {
			$k = substr( $k, 1, -1 );
		}
		return $k;
	}

	private static function scalar( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v ) {
			return '';
		}

		/*
		 * A quoted scalar ends at its closing quote. Everything after that is a
		 * trailing comment and must be discarded.
		 *
		 * Getting this wrong is quiet and nasty: the old version only stripped
		 * comments from UNQUOTED values, so `name: "Acme"   # note` came back as
		 * the whole line, quotes and comment included, and the business ended up
		 * literally named `"Acme"   # note`. Nothing errors; it just looks wrong
		 * everywhere at once.
		 */
		$q = $v[0];
		if ( '"' === $q || "'" === $q ) {
			$end = strpos( $v, $q, 1 );
			return false === $end ? substr( $v, 1 ) : substr( $v, 1, $end - 1 );
		}

		// Unquoted: ' #' begins a comment.
		if ( str_contains( $v, ' #' ) ) {
			$v = trim( substr( $v, 0, strpos( $v, ' #' ) ) );
		}

		// Inline flow list: [a, b, c]
		if ( str_starts_with( $v, '[' ) && str_ends_with( $v, ']' ) ) {
			$inner = trim( substr( $v, 1, -1 ) );
			return '' === $inner ? [] : array_map( [ __CLASS__, 'scalar' ], array_map( 'trim', explode( ',', $inner ) ) );
		}

		// Inline flow map: { key: value, key: value }
		if ( str_starts_with( $v, '{' ) && str_ends_with( $v, '}' ) ) {
			$inner = trim( substr( $v, 1, -1 ) );
			if ( '' === $inner ) {
				return [];
			}
			$map = [];
			foreach ( array_map( 'trim', explode( ',', $inner ) ) as $pair ) {
				if ( '' === $pair ) {
					continue;
				}
				if ( preg_match( '/^([^:]+):\s*(.*)$/', $pair, $m ) ) {
					$map[ self::clean_key( $m[1] ) ] = self::scalar( $m[2] );
				} else {
					$map[] = self::scalar( $pair );
				}
			}
			return $map;
		}

		$lower = strtolower( $v );
		if ( in_array( $lower, [ 'true', 'yes' ], true ) )   return true;
		if ( in_array( $lower, [ 'false', 'no' ], true ) )   return false;
		if ( in_array( $lower, [ 'null', '~', '' ], true ) ) return '';
		if ( preg_match( '/^-?\d+$/', $v ) )                 return (int) $v;
		if ( preg_match( '/^-?\d*\.\d+$/', $v ) )            return (float) $v;
		return $v;
	}
}
