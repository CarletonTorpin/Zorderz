<?php
/**
 * Zorderz — Identity Packs
 *
 * An Identity Pack is a business, as data. It carries everything true about one
 * company — names, brand, contact, senders, locale, roles, house rules, the
 * non-secret half of its connections — in plain files that can be read,
 * reviewed, versioned and re-imported. Applying a pack to a stock Zorderz
 * install is what turns a blank platform into that company's app.
 *
 * This is the other half of the Business Profile. The profile is where a
 * business's values live; a pack is how they travel.
 *
 * WHAT THIS VERSION CONSUMES
 *   profile.yml   → ZDZ_Business_Profile (identity, contact, web, senders, brand, locale)
 *   org.yml       → role labels, tiers, per-role app grants, launcher groups
 *   and everything registered in the consumer registry (consumers()): the
 *   platform's own settings (settings_consumers(): quiet hours, notice grants,
 *   the Ai router's models, prices, region and budgets, the privacy coder's
 *   word lists …) and whatever a Core service or an app registers on the
 *   `zdz_identity_pack_consumers` filter (the price book, Privacy View words,
 *   Territories, Team Roles marks …).
 *
 * Every value goes through the same check the setting's own screen or reader
 * uses. A check that has a floor keeps it: a pack may choose a narrower value,
 * never a wider one. A value that fails is REFUSED and listed with the reason;
 * nothing is guessed, rounded into range or silently dropped.
 *
 * A pack may contain more than this build understands — flows, compensation and
 * the rest of the fifteen files, or keys inside a read file that nothing reads
 * yet. Those are reported, never silently ignored, so it is always obvious what
 * a pack carries that the platform cannot yet use.
 *
 * PRINCIPLES, learned the hard way
 *   - Nothing applies without an explicit, logged human decision. Business data
 *     is never written into an install on activation.
 *   - Preview before apply. The diff is shown, not summarised.
 *   - Reversible. Every setting a pack writes is recorded first and put back
 *     exactly as it was, including "was not set at all"; each consumer keeps
 *     and restores its own part.
 *   - Provenance stamped. Every applied value records which pack it came from.
 *   - Secrets are never in a pack. Credentials are referenced (`secret://<row>`),
 *     never carried: a pack that carries a credential value is refused whole.
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

	/**
	 * Values a pack sets that the platform reads through a filter rather than an
	 * option of its own: setting key => value. Answered by boot()'s filters.
	 */
	const SETTINGS_OPTION = 'zdz_identity_pack_settings';

	/** Which filter each stored setting answers. Priority 5, so site code on the same filter still has the last word. */
	const FILTERED = [
		'contacts.reaching_results' => 'zdz_contact_reaching_results',
		'usage_records.raw_days'    => 'zdz_audit_raw_days',
		'media.same_property_m'     => 'zdz_geocoder_same_property_m',
		'ai.external_callers'       => 'zdz_llm_external_callers',
	];

	/** Files this build reads itself; consumers() adds the files the registry's consumers read. */
	const SUPPORTED = [ 'profile', 'org' ];

	/** Files a full pack may contain, so unsupported ones can be named precisely. */
	const KNOWN = [
		'profile', 'parties', 'org', 'catalog', 'pricing', 'costs', 'compensation',
		'territories', 'connections', 'mappings', 'document-conventions', 'flows',
		'rules', 'knowledge', 'voice',
	];

	/**
	 * Settings a pack may name but never writes, and why. A pack that carries one
	 * is told where the setting is made instead of seeing it listed as unknown.
	 * File => path => reason.
	 */
	const NOT_FROM_A_PACK = [
		'rules'       => [
			'security_monitor.first_party'  => 'Set on Zorderz → Security Monitor ("Your own components"). It takes components out of the vulnerability check, which a file that travels between sites may not do.',
			'security_monitor.recipients'   => 'Chosen on Zorderz → Security Monitor: the digest goes to administrators of this site, picked there.',
			'security_monitor.request_wall' => 'Set on Zorderz → Security Monitor: the request time limit describes this server, not the business.',
			'login.next_prefixes'           => 'Apps add the in-app pages a sign-in may return to (zdz_login_next_prefixes). A wider list from a file would be an open redirect.',
		],
		'connections' => [
			'vulnerability_feed.allowed_hosts' => 'Code only (zsm_feed_allowed_hosts): which hosts the security monitor may contact is a safety limit.',
			'ai.openai_entries'                => 'Not read from a pack: an entry names where business data is sent, and its retention terms must be checked by a person on this site.',
			'geocoder'                         => 'Not read from a pack: a networked geocoder needs its own credentials row and a fixed provider host before a file may switch it on.',
			'staging'                          => 'Set in wp-config.php (ZDZ_STAGING_HOSTS): staging hosts describe one server, not the business.',
			'places.zip_lookup_url'            => 'Set on this site (option zana_zip_place_url): a postal-code lookup service is where customers\' postcodes are sent, which a person on this site chooses, as for the geocoder.',
		],
	];

	/** A stand-in for "this option does not exist", so an empty option and a missing one stay different. */
	const ABSENT = "\0zdz-identity-pack-absent\0";

	/** Answer the filters a pack's stored settings drive. Called once when this file loads. */
	public static function boot() {
		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}
		foreach ( self::FILTERED as $key => $hook ) {
			add_filter(
				$hook,
				static function ( $value ) use ( $key ) {
					return self::filtered( $key, $value );
				},
				5
			);
		}
	}

	/**
	 * What a filter answers for one stored setting: the pack's value, or what it
	 * was handed when the pack set nothing. A list of outside callers is added to
	 * what other code already listed rather than replacing it.
	 */
	public static function filtered( $key, $value ) {
		$all = function_exists( 'get_option' ) ? get_option( self::SETTINGS_OPTION, [] ) : [];
		if ( ! is_array( $all ) || ! array_key_exists( $key, $all ) ) {
			return $value;
		}
		if ( 'ai.external_callers' === $key ) {
			return array_values( array_unique( array_merge( is_array( $value ) ? $value : [], (array) $all[ $key ] ) ) );
		}
		return $all[ $key ];
	}

	// ─────────────────────────────────────────────── the consumer registry

	/**
	 * Every consumer of a pack's files, id => [
	 *   'label'    => string, optional: how messages name it (the id otherwise),
	 *   'files'    => string[] the pack files it reads (stems from KNOWN),
	 *   'paths'    => [ stem => string[] ], optional: the dot paths it reads in each
	 *                 file; without it the whole file is its own. Paths no consumer
	 *                 reads are reported as unused,
	 *   'preview'  => callable( array $files ): array  rows [ file, path, from, to ]; a
	 *                 row may say 'kind' => 'refused' (with 'why'), 'note' (with 'note')
	 *                 or 'unconsumed', which the preview lists apart from the changes,
	 *   'apply'    => callable( array $files, string $slug ): string[]  messages,
	 *   'snapshot' => callable( array $files, string $slug ): mixed  what to keep before
	 *                 anything is written,
	 *   'restore'  => callable( mixed $snapshot ): string[]|null  puts it back; messages
	 *                 say what could not be put back (something changed there since),
	 * ]. $files is stem => the parsed file (null when absent or unreadable). A
	 * consumer runs only when the pack carries at least one of its files; every
	 * snapshot is taken before anything is written, so revert restores it.
	 *
	 * The platform's own settings (settings_consumers()) are always in the
	 * registry; a Core service or an app adds its own on the
	 * `zdz_identity_pack_consumers` filter and cannot take a built-in id. An app
	 * whose data lives in its own tables or versioned lists keeps exactly what it
	 * needs in its snapshot and decides what putting it back means (a version
	 * check, a rule that never goes live by itself). person_id() resolves a pack's
	 * reference to a person the same way for every consumer.
	 *
	 * @return array<string,array>
	 */
	public static function consumers() {
		$out = self::settings_consumers();
		foreach ( (array) apply_filters( 'zdz_identity_pack_consumers', [] ) as $id => $c ) {
			if ( ! is_string( $id ) || isset( $out[ $id ] ) || ! is_array( $c ) || empty( $c['files'] ) ) {
				continue;
			}
			$files = array_values( array_intersect( (array) $c['files'], self::KNOWN ) );
			$ok    = $files && is_callable( $c['preview'] ?? null ) && is_callable( $c['apply'] ?? null )
				&& is_callable( $c['snapshot'] ?? null ) && is_callable( $c['restore'] ?? null );
			if ( $ok ) {
				$c['files'] = $files;
				$paths      = [];
				foreach ( is_array( $c['paths'] ?? null ) ? $c['paths'] : [] as $stem => $list ) {
					if ( in_array( $stem, $files, true ) ) {
						$paths[ $stem ] = array_values( array_filter( (array) $list, fn( $p ) => is_string( $p ) && '' !== $p ) );
					}
				}
				$c['paths'] = $paths;
				$c['label'] = '' !== (string) ( $c['label'] ?? '' ) ? (string) $c['label'] : $id;
				$out[ $id ] = $c;
			}
		}
		return $out;
	}

	/** The registry read once for the preview or apply in progress, so each lookup does not rebuild it. */
	private static $in_use = null;

	private static function registry() {
		return null !== self::$in_use ? self::$in_use : self::consumers();
	}

	/** Every pack file this build can apply: the two it reads itself and its consumers' files. */
	public static function supported_files() {
		$files = self::SUPPORTED;
		foreach ( self::registry() as $c ) {
			$files = array_merge( $files, $c['files'] );
		}
		return array_values( array_unique( $files ) );
	}

	/** The consumers a pack directory has files for, each with its files read: id => [ consumer, files ]. */
	private static function consumers_for( $dir, array $stems ) {
		$out = [];
		foreach ( self::registry() as $id => $c ) {
			if ( ! array_intersect( $c['files'], $stems ) ) {
				continue;
			}
			$files = [];
			foreach ( $c['files'] as $stem ) {
				$files[ $stem ] = in_array( $stem, $stems, true ) ? self::read_file( $dir, $stem ) : null;
			}
			$out[ $id ] = [ $c, $files ];
		}
		return $out;
	}

	/**
	 * The platform's own settings a pack may set, as registry entries. Each reads
	 * a few dot paths of one file, checks them with the setting's own check (its
	 * plan_*() method), keeps the options it is about to write and puts them back
	 * exactly on revert.
	 *
	 *   profile.yml      email.work_domains                 → Scheduler work-calendar domains
	 *   org.yml          alerts.role_category_grants        → extra notice channels per role and category
	 *   rules.yml        notifications.quiet_hours           → quiet hours
	 *                    contacts.reaching_results           → the answers that count as reaching a customer
	 *                    usage_records.raw_days              → how long detailed usage records are kept
	 *                    media.same_property_m               → the same-property radius for photos
	 *                    ai_spend.monthly_usd                → the Ai router's monthly lines
	 *                    security_monitor.public_registration → the monitor's registration posture
	 *   connections.yml  ai.models / prices / anthropic / roles / external_callers → the Ai router
	 *                    vulnerability_feed.key              → checked reference to the feed key
	 *   catalog.yml      vocabulary.plain_words / model_words → the privacy coder
	 *   territories.yml  place_names.*, jurisdiction.*       → the privacy coder
	 *   catalog.yml      vocabulary.things                   → the ask bar's trade words
	 *   org.yml          work_hours.default                  → the business's usual hours (For You)
	 *   knowledge.yml    names.soundalikes                   → the name matcher's soundalike groups (added, never removed)
	 *                    addresses.street_first_words        → street words written before the name, for the address readers
	 *
	 * @return array<string,array>
	 */
	public static function settings_consumers() {
		$c = static function ( $label, $file, array $paths, $plan ) {
			return self::option_consumer( $label, $file, $paths, [ __CLASS__, $plan ], true );
		};
		return [
			'work-domains'          => $c( 'Work calendars', 'profile', [ 'email.work_domains' ], 'plan_work_domains' ),
			'alert-grants'          => $c( 'Notices', 'org', [ 'alerts.role_category_grants' ], 'plan_alert_grants' ),
			'quiet-hours'           => $c( 'Quiet hours', 'rules', [ 'notifications.quiet_hours' ], 'plan_quiet_hours' ),
			'contact-reach'         => $c( 'Contacts', 'rules', [ 'contacts.reaching_results' ], 'plan_reaching_results' ),
			'usage-retention'       => $c( 'Usage records', 'rules', [ 'usage_records.raw_days' ], 'plan_raw_days' ),
			'same-property'         => $c( 'Photo locations', 'rules', [ 'media.same_property_m' ], 'plan_same_property' ),
			'ai-spend'              => $c( 'Ai spending', 'rules', [ 'ai_spend.monthly_usd' ], 'plan_ai_spend' ),
			'security-registration' => $c( 'Security monitor', 'rules', [ 'security_monitor.public_registration' ], 'plan_registration' ),
			'ai-connection'         => $c( 'Ai router', 'connections', [ 'ai.models', 'ai.prices', 'ai.anthropic', 'ai.roles', 'ai.external_callers' ], 'plan_ai_connection' ),
			'vulnerability-feed'    => $c( 'Security monitor', 'connections', [ 'vulnerability_feed.key' ], 'plan_feed_key' ),
			'privacy-vocabulary'    => $c( 'Privacy coder', 'catalog', [ 'vocabulary.plain_words', 'vocabulary.model_words' ], 'plan_vocabulary' ),
			'privacy-places'        => $c( 'Privacy coder', 'territories', [ 'place_names.multi_word', 'place_names.ask_visible' ], 'plan_places' ),
			'privacy-region'        => $c( 'Privacy coder', 'territories', [ 'jurisdiction.code_words', 'jurisdiction.postcode' ], 'plan_jurisdiction' ),
			'trade-words'           => $c( 'Ask bar', 'catalog', [ 'vocabulary.things' ], 'plan_trade_words' ),
			'work-hours'            => $c( 'Usual hours', 'org', [ 'work_hours.default' ], 'plan_work_hours' ),
			'soundalikes'           => $c( 'Name matching', 'knowledge', [ 'names.soundalikes' ], 'plan_soundalikes' ),
			'street-words'          => $c( 'Address reading', 'knowledge', [ 'addresses.street_first_words' ], 'plan_street_words' ),
		];
	}

	/**
	 * A registry entry for a setting kept in options: it reads a few dot paths of one file and hands them,
	 * path => value, to $plan, which checks them with the setting's own check and returns a plan (plan(),
	 * plan_option(), plan_key(): changes, refused, notes and the writes). The entry previews the plan,
	 * writes it, keeps the options it is about to write and puts them back exactly on revert. A Core
	 * service or an app that keeps its setting in an option registers with this on
	 * `zdz_identity_pack_consumers` instead of writing the four callables itself.
	 *
	 * @param string   $label How messages name it.
	 * @param string   $file  The pack file (a stem from KNOWN).
	 * @param string[] $paths The dot paths it reads in that file.
	 * @param callable $plan  callable( array $data ): array, a plan.
	 * @return array
	 */
	public static function option_consumer( $label, $file, array $paths, $plan, $builtin = false ) {
		$run = static function ( $files ) use ( $file, $paths, $plan ) {
			$data = is_array( $files[ $file ] ?? null ) ? self::slice( $files[ $file ], $paths ) : [];
			return $data ? self::run_plan( $plan, $data ) : self::plan();
		};
		$entry = [
			'label'    => $label,
			'files'    => [ $file ],
			'paths'    => [ $file => $paths ],
			'preview'  => static function ( array $files ) use ( $run, $file ) {
				return self::plan_rows( $file, $run( $files ) );
			},
			'apply'    => static function ( array $files, $slug = '' ) use ( $run, $label ) {
				$plan = $run( $files );
				$msgs = [];
				if ( $plan['writes'] ) {
					self::write_plan( $plan['writes'] );
					self::flush_readers();
					$n      = count( $plan['changes'] );
					$msgs[] = sprintf( '%s: %d setting%s applied.', $label, $n, 1 === $n ? '' : 's' );
				}
				foreach ( $plan['refused'] as $r ) {
					$msgs[] = sprintf( '%s: %s refused — %s', $label, $r['path'], $r['why'] );
				}
				return $msgs;
			},
			'snapshot' => static function ( array $files = [], $slug = '' ) use ( $run ) {
				return self::record_options( array_values( array_unique( array_column( $run( $files )['writes'], 'option' ) ) ) );
			},
			'restore'  => static function ( $kept ) {
				self::restore_options( is_array( $kept ) ? $kept : [] );
				self::flush_readers();
				return [];
			},
		];
		if ( $builtin ) {
			$entry['builtin'] = true;
		}
		return $entry;
	}

	/** A plan as preview rows. */
	private static function plan_rows( $file, array $plan ) {
		$rows = [];
		foreach ( $plan['changes'] as $r ) {
			$rows[] = [ 'file' => $file, 'path' => $r['path'], 'from' => $r['from'], 'to' => $r['to'] ];
		}
		foreach ( $plan['refused'] as $r ) {
			$rows[] = [ 'file' => $file, 'path' => $r['path'], 'kind' => 'refused', 'why' => $r['why'] ];
		}
		foreach ( $plan['notes'] as $n ) {
			$rows[] = [ 'file' => $file, 'path' => '', 'kind' => 'note', 'note' => $n ];
		}
		return $rows;
	}

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
	 * @return array<string,array> slug => [ label, dir, files, supported, unsupported, stems ]
	 */
	public static function discover() {
		$found = [];
		$can   = self::supported_files();
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
				$stems = array_values( array_unique( array_map( fn( $f ) => preg_replace( '/\.(ya?ml|json)$/', '', $f ), $files ) ) );
				// `pack` is the pack's own metadata, not content — it is read for
				// the label, so reporting it as "cannot apply" is just confusing.
				$found[ $slug ] = [
					'label'       => self::read_label( $dir, $slug ),
					'dir'         => $dir,
					'files'       => $files,
					'supported'   => array_values( array_intersect( $stems, $can ) ),
					'unsupported' => array_values( array_diff( $stems, $can, [ 'pack' ] ) ),
					'stems'       => $stems,
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

	/** Every known file the pack carries, parsed: stem => array (unparseable files are left out). */
	private static function read_all( $dir ) {
		$out = [];
		foreach ( self::KNOWN as $stem ) {
			$d = self::read_file( $dir, $stem );
			if ( is_array( $d ) ) {
				$out[ $stem ] = $d;
			}
		}
		return $out;
	}

	/**
	 * Build a preview of what applying a pack would change. Writes nothing.
	 *
	 * @return array{pack:string,label:string,changes:array,unsupported:array,unconsumed:array,refused:array,notes:array,errors:array,blocked:bool}
	 */
	public static function preview( $slug ) {
		self::$in_use = self::consumers();
		try {
			return self::build_preview( $slug );
		} finally {
			self::$in_use = null;
		}
	}

	private static function build_preview( $slug ) {
		$packs = self::discover();
		if ( ! isset( $packs[ $slug ] ) ) {
			return [ 'pack' => $slug, 'label' => $slug, 'changes' => [], 'unsupported' => [], 'unconsumed' => [], 'refused' => [], 'notes' => [], 'errors' => [ 'Pack not found.' ], 'blocked' => true ];
		}
		$pack       = $packs[ $slug ];
		$errors     = [];
		$changes    = [];
		$refused    = [];
		$notes      = [];
		$unconsumed = [];
		$all        = self::read_all( $pack['dir'] );

		$secrets = self::find_secrets( $all );
		foreach ( $secrets as $where ) {
			$errors[] = sprintf( 'A credential value is written in %s. A pack carries only secret:// references; remove the value (and treat it as exposed) before this pack can be applied.', $where );
		}

		$profile = $all['profile'] ?? null;
		if ( null === $profile ) {
			if ( in_array( 'profile', $pack['supported'], true ) ) {
				$errors[] = 'profile file present but could not be parsed.';
			}
		} else {
			$norm   = self::normalize_profile( $profile );
			$values = self::strip_claimed( $norm['values'], 'profile' );
			foreach ( self::unclaimed( $norm['unconsumed'], 'profile' ) as $p ) {
				$unconsumed[] = 'profile: ' . $p;
			}
			foreach ( self::flatten( $values ) as $path => $new ) {
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
			if ( isset( $def['tier'] ) ) {
				$tiers_now = class_exists( 'ZDZ_Capabilities' ) ? ZDZ_Capabilities::role_tiers() : [];
				$changes[] = [ 'file' => 'org', 'path' => "roles.$role_slug.tier", 'from' => (string) ( $tiers_now[ $role_slug ] ?? '(none)' ), 'to' => $def['tier'] ];
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

		$groups = self::read_org_app_groups( $pack['dir'] );
		if ( $groups ) {
			$was       = get_option( 'zdz_app_groups', [] );
			$changes[] = [ 'file' => 'org', 'path' => 'app_groups', 'from' => $was ? wp_json_encode( $was ) : '(platform default)', 'to' => wp_json_encode( $groups ) ];
		}
		if ( isset( $all['org'] ) ) {
			foreach ( self::org_unconsumed( $all['org'] ) as $p ) {
				$unconsumed[] = 'org: ' . $p;
			}
		}

		foreach ( self::consumers_for( $pack['dir'], (array) ( $pack['stems'] ?? [] ) ) as $id => $cf ) {
			try {
				$rows = (array) call_user_func( $cf[0]['preview'], $cf[1] );
			} catch ( \Throwable $e ) {
				$errors[] = $id . ': ' . $e->getMessage();
				continue;
			}
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$file = (string) ( $row['file'] ?? '' );
				switch ( (string) ( $row['kind'] ?? 'change' ) ) {
					case 'refused':
						$refused[] = [ 'file' => $file, 'path' => (string) ( $row['path'] ?? '' ), 'why' => (string) ( $row['why'] ?? '' ) ];
						break;
					case 'note':
						$notes[] = $cf[0]['label'] . ': ' . (string) ( $row['note'] ?? '' );
						break;
					case 'unconsumed':
						$unconsumed[] = $file . ': ' . (string) ( $row['path'] ?? '' );
						break;
					default:
						if ( isset( $row['path'] ) ) {
							$changes[] = [ 'file' => $file, 'path' => (string) $row['path'], 'from' => (string) ( $row['from'] ?? '' ), 'to' => (string) ( $row['to'] ?? '' ) ];
						}
				}
			}
		}

		// What the read files carry that no consumer reads (profile and org report their own above).
		foreach ( $all as $stem => $data ) {
			if ( in_array( $stem, self::SUPPORTED, true ) || ! in_array( $stem, $pack['supported'], true ) ) {
				continue;
			}
			foreach ( self::file_leftovers( $stem, $data ) as $row ) {
				if ( isset( $row['why'] ) ) {
					$refused[] = [ 'file' => $stem, 'path' => $row['path'], 'why' => $row['why'] ];
				} else {
					$unconsumed[] = $stem . ': ' . $row['path'];
				}
			}
		}

		return [
			'pack'        => $slug,
			'label'       => $pack['label'],
			'changes'     => $changes,
			'unsupported' => $pack['unsupported'],
			'unconsumed'  => $unconsumed,
			'refused'     => $refused,
			'notes'       => $notes,
			'errors'      => $errors,
			'blocked'     => (bool) $secrets,
		];
	}

	/**
	 * Read role definitions out of a pack's org file, in either shape.
	 *
	 * The canonical schema writes roles as a list of records with a `slug` field;
	 * a hand-written pack is likelier to write a map keyed by slug. Both are
	 * accepted. `default_apps` collapses to either a flat list of app ids or the
	 * boolean true meaning "every app", so the caller never has to interpret the
	 * `{all: true}` sentinel or the allowed/pinned split — there is one grant list.
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
			// The verb ladder rung (ZDZ_Capabilities). Validated again where it is read:
			// only an admin-level role may sit on the admin rung.
			if ( ! empty( $def['tier'] ) && in_array( (string) $def['tier'], [ 'viewer', 'staff', 'admin' ], true ) ) {
				$row['tier'] = (string) $def['tier'];
			}
			if ( $row ) {
				$out[ $slug ] = $row;
			}
		}
		return $out;
	}

	/** The org file's keys nothing reads: anything but roles (slug, label, default_apps, tier), app_groups and what consumers claim. */
	private static function org_unconsumed( array $org ) {
		$out = [];
		foreach ( (array) ( $org['roles'] ?? [] ) as $key => $def ) {
			if ( ! is_array( $def ) ) {
				continue;
			}
			$slug = is_string( $key ) ? $key : (string) ( $def['slug'] ?? $key );
			foreach ( $def as $k => $v ) {
				if ( ! in_array( (string) $k, [ 'slug', 'label', 'default_apps', 'tier' ], true ) && '' !== $v && null !== $v && [] !== $v ) {
					$out[] = "roles.$slug.$k";
				}
			}
		}
		$rest = $org;
		unset( $rest['roles'], $rest['app_groups'], $rest['pack'], $rest['_meta'] );
		foreach ( self::flatten( $rest ) as $path => $v ) {
			if ( '' === $v || null === $v || [] === $v || self::claimed( 'org', $path ) ) {
				continue;
			}
			$out[] = $path;
		}
		return $out;
	}

	/**
	 * The business's app arrangement from org.yml `app_groups` (apps => group, labels => text),
	 * validated by ZDZ_App_Groups. Empty when the pack says nothing.
	 */
	private static function read_org_app_groups( $dir ) {
		$org = self::read_file( $dir, 'org' );
		if ( ! is_array( $org ) || empty( $org['app_groups'] ) || ! is_array( $org['app_groups'] ) || ! class_exists( 'ZDZ_App_Groups' ) ) {
			return [];
		}
		$g = ZDZ_App_Groups::sanitize( $org['app_groups'] );
		return ( $g['apps'] || $g['labels'] ) ? $g : [];
	}

	/**
	 * Apply a pack. Requires an explicit confirmation token, so nothing can
	 * apply a pack by accident or as a side effect of loading.
	 *
	 * A pack that carries a credential value is refused whole. Before the first
	 * write, every option the profile and org parts write is recorded (its value,
	 * or that it was not set) and every consumer keeps its own snapshot, so
	 * revert() can put everything back.
	 *
	 * @param string $slug    Pack slug.
	 * @param bool   $confirm Must be true. Present so callers cannot apply implicitly.
	 * @return array{ok:bool,applied:int,messages:string[]}
	 */
	public static function apply( $slug, $confirm = false ) {
		self::$in_use = self::consumers();
		try {
			return self::run_apply( $slug, $confirm );
		} finally {
			self::$in_use = null;
		}
	}

	private static function run_apply( $slug, $confirm ) {
		$msgs = [];
		if ( ! $confirm ) {
			return [ 'ok' => false, 'applied' => 0, 'messages' => [ 'Refused: applying a pack requires explicit confirmation.' ] ];
		}
		$packs = self::discover();
		if ( ! isset( $packs[ $slug ] ) ) {
			return [ 'ok' => false, 'applied' => 0, 'messages' => [ 'Pack not found.' ] ];
		}
		$pack = $packs[ $slug ];
		$by   = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		$secrets = self::find_secrets( self::read_all( $pack['dir'] ) );
		if ( $secrets ) {
			return [ 'ok' => false, 'applied' => 0, 'messages' => [ 'Refused: this pack carries a credential value (' . implode( ', ', $secrets ) . '). A pack carries only secret:// references. Nothing was applied.' ] ];
		}

		$profile = self::read_file( $pack['dir'], 'profile' );
		$roles   = self::read_org_roles( $pack['dir'] );
		$groups  = self::read_org_app_groups( $pack['dir'] );

		// Snapshot first, so this is reversible: the options the profile and org
		// parts write, and the part each consumer this pack feeds is about to write.
		$touch = [];
		if ( is_array( $profile ) ) {
			$touch[] = ZDZ_Business_Profile::OPTION;
		}
		if ( $roles ) {
			array_push( $touch, 'zdz_role_tiers', 'zdz_role_labels', 'zdz_role_default_apps' );
		}
		if ( $groups ) {
			$touch[] = 'zdz_app_groups';
		}
		$consumers = self::consumers_for( $pack['dir'], (array) ( $pack['stems'] ?? [] ) );
		$kept      = [];
		foreach ( $consumers as $id => $cf ) {
			$kept[ $id ] = call_user_func( $cf[0]['snapshot'], $cf[1], $slug );
		}
		update_option( self::SNAPSHOT_OPTION, [
			'schema'     => 2,
			'taken_at'   => gmdate( 'c' ),
			'pack'       => $slug,
			'options'    => self::record_options( $touch ),
			'role_names' => $roles && function_exists( 'wp_roles' ) ? wp_roles()->role_names : [],
			'consumers'  => $kept,
		], false );

		$applied = 0;

		if ( is_array( $profile ) ) {
			$norm   = self::normalize_profile( $profile );
			$values = self::strip_claimed( $norm['values'], 'profile' );
			ZDZ_Business_Profile::set( $values, 'pack:' . $slug );
			ZDZ_Business_Profile::set( [ '_meta' => [ 'pack' => $slug, 'pack_label' => $pack['label'] ] ], 'pack:' . $slug );
			$applied++;
			$n      = count( self::flatten( $values ) );
			$msgs[] = sprintf( 'profile applied (%s schema, %d value%s).', $norm['schema'], $n, 1 === $n ? '' : 's' );
			$left   = self::unclaimed( $norm['unconsumed'], 'profile' );
			if ( $left ) {
				$msgs[] = sprintf(
					'%d profile key(s) carried but not used by this version: %s',
					count( $left ),
					implode( ', ', array_slice( $left, 0, 12 ) ) . ( count( $left ) > 12 ? ' …' : '' )
				);
			}
		}

		if ( $roles ) {
			$labels = [];
			$grants = [];
			$tiers  = [];
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
				if ( isset( $def['tier'] ) ) {
					$tiers[ $role_slug ] = $def['tier'];
				}
			}
			if ( $tiers ) {
				update_option( 'zdz_role_tiers', $tiers, false );
				$applied++;
				$msgs[] = count( $tiers ) . ' role tier(s) applied.';
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

		if ( $groups ) {
			update_option( 'zdz_app_groups', $groups, false );
			$applied++;
			$msgs[] = 'App groups applied.';
		}

		foreach ( $consumers as $id => $cf ) {
			try {
				$out = (array) call_user_func( $cf[0]['apply'], $cf[1], $slug );
				foreach ( $out as $m ) {
					$msgs[] = (string) $m;
				}
				if ( $out ) {
					$applied++;
				}
			} catch ( \Throwable $e ) {
				$msgs[] = $id . ' not applied: ' . $e->getMessage();
			}
		}

		foreach ( $pack['unsupported'] as $stem ) {
			$msgs[] = "'$stem' is in the pack but this version cannot apply it yet — left untouched.";
		}

		update_option( self::APPLIED_OPTION, [
			'pack'       => $slug,
			'label'      => $pack['label'],
			'applied_at' => gmdate( 'c' ),
			'by'         => $by,
			'files'      => $pack['files'],
		], false );

		$log = (array) get_option( self::LOG_OPTION, [] );
		$log[] = [ 'at' => gmdate( 'c' ), 'pack' => $slug, 'by' => $by, 'messages' => $msgs ];
		update_option( self::LOG_OPTION, array_slice( $log, -25 ), false );

		return [ 'ok' => true, 'applied' => $applied, 'messages' => $msgs ];
	}

	/**
	 * Restore the snapshot taken before the most recent apply.
	 *
	 * Every option the profile and org parts wrote goes back to exactly what it
	 * was, including "not set". Each consumer puts its own part back by its own
	 * rules and says what it could not (something changed there since the pack).
	 * The snapshot is used once.
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
		$by   = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$msgs = [ 'Reverted to the snapshot taken at ' . ( $snap['taken_at'] ?? 'unknown' ) . '.' ];

		if ( isset( $snap['options'] ) && is_array( $snap['options'] ) ) {
			self::restore_options( $snap['options'] );
		} else {
			// A snapshot taken before options were recorded one by one.
			update_option( ZDZ_Business_Profile::OPTION, $snap['profile'] ?? [], false );
			delete_option( 'zdz_role_labels' );
			delete_option( 'zdz_role_default_apps' );
			delete_option( 'zdz_role_tiers' );
			delete_option( 'zdz_app_groups' );
		}
		// Writing the options directly bypasses the readers' own setters, so their
		// request caches still hold what was just replaced. Without this, every read
		// for the rest of the request reports the revert as having done nothing.
		self::flush_readers();

		$consumers = self::consumers();
		foreach ( (array) ( $snap['consumers'] ?? [] ) as $id => $kept ) {
			if ( ! isset( $consumers[ $id ] ) ) {
				$msgs[] = sprintf( '%s: not active on this site, so its part was left as it is.', $id );
				continue;
			}
			try {
				foreach ( (array) call_user_func( $consumers[ $id ]['restore'], $kept ) as $m ) {
					$msgs[] = $consumers[ $id ]['label'] . ': ' . (string) $m;
				}
			} catch ( \Throwable $e ) {
				$msgs[] = $consumers[ $id ]['label'] . ' not put back: ' . $e->getMessage();
			}
		}

		delete_option( self::APPLIED_OPTION );
		delete_option( self::SNAPSHOT_OPTION );

		$log   = (array) get_option( self::LOG_OPTION, [] );
		$log[] = [ 'at' => gmdate( 'c' ), 'pack' => '(revert)', 'by' => $by, 'messages' => $msgs ];
		update_option( self::LOG_OPTION, array_slice( $log, -25 ), false );

		return [ 'ok' => true, 'messages' => $msgs ];
	}

	/** Which pack is currently applied, if any. */
	public static function applied() {
		$a = get_option( self::APPLIED_OPTION, null );
		return is_array( $a ) ? $a : null;
	}

	// ─────────────────────────────────────────────── claims and leftovers

	/** The claimed paths a file carries: dot path => value. Empty values are placeholders and are left out. */
	private static function slice( array $file, array $paths ) {
		$out = [];
		foreach ( $paths as $p ) {
			$v = self::dig( $file, $p );
			if ( null !== $v && '' !== $v && [] !== $v ) {
				$out[ $p ] = $v;
			}
		}
		return $out;
	}

	/** Does a consumer read this path of this file (or a path above it, or the whole file)? */
	private static function claimed( $file, $path ) {
		foreach ( self::registry() as $c ) {
			if ( ! in_array( $file, $c['files'], true ) ) {
				continue;
			}
			if ( ! isset( $c['paths'][ $file ] ) ) {
				return true; // the whole file is this consumer's
			}
			foreach ( $c['paths'][ $file ] as $p ) {
				if ( $path === $p || str_starts_with( $path, $p . '.' ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Profile keys the profile reader did not use, less those another consumer reads. */
	private static function unclaimed( array $paths, $file ) {
		return array_values( array_filter( $paths, fn( $p ) => ! self::claimed( $file, $p ) ) );
	}

	/** A flat-schema profile passes through whole; keys another consumer reads are not stored in the profile too. */
	private static function strip_claimed( array $values, $file ) {
		foreach ( self::registry() as $c ) {
			foreach ( $c['paths'][ $file ] ?? [] as $p ) {
				$values = self::unset_path( $values, explode( '.', $p ) );
			}
		}
		return $values;
	}

	/** $a without the value at this path; a map left empty by that goes too. */
	private static function unset_path( array $a, array $segs ) {
		$first = array_shift( $segs );
		if ( ! array_key_exists( $first, $a ) ) {
			return $a;
		}
		if ( ! $segs ) {
			unset( $a[ $first ] );
			return $a;
		}
		if ( is_array( $a[ $first ] ) ) {
			$a[ $first ] = self::unset_path( $a[ $first ], $segs );
			if ( [] === $a[ $first ] ) {
				unset( $a[ $first ] );
			}
		}
		return $a;
	}

	/**
	 * What a read file carries that no consumer reads: [ path ] rows, or [ path, why ]
	 * for settings a pack may not set (NOT_FROM_A_PACK).
	 */
	private static function file_leftovers( $stem, array $data ) {
		unset( $data['pack'], $data['_meta'] );
		$out = [];
		$not = self::NOT_FROM_A_PACK[ $stem ] ?? [];
		foreach ( self::flatten( $data ) as $path => $v ) {
			if ( '' === $v || null === $v || [] === $v || self::claimed( $stem, $path ) ) {
				continue;
			}
			foreach ( $not as $prefix => $why ) {
				if ( $path === $prefix || str_starts_with( $path, $prefix . '.' ) ) {
					$out[ $prefix ] = [ 'path' => $prefix, 'why' => $why ];
					continue 2;
				}
			}
			$out[ $path ] = [ 'path' => $path ];
		}
		return array_values( $out );
	}

	/** A plan, made safe: a check that fails says so instead of breaking the preview or the apply. */
	private static function run_plan( $plan, array $data ) {
		$empty = self::plan();
		try {
			$r = call_user_func( $plan, $data );
		} catch ( \Throwable $e ) {
			$empty['refused'][] = [ 'path' => implode( ', ', array_keys( $data ) ), 'why' => 'This setting could not be read here: ' . $e->getMessage() ];
			return $empty;
		}
		return is_array( $r ) ? array_merge( $empty, array_intersect_key( $r, $empty ) ) : $empty;
	}

	/** Each option as it is now: [ name => [ exists, value ] ]. */
	public static function record_options( array $names ) {
		$out = [];
		foreach ( array_unique( $names ) as $n ) {
			$v         = get_option( $n, self::ABSENT );
			$out[ $n ] = self::ABSENT === $v ? [ 'exists' => false, 'value' => null ] : [ 'exists' => true, 'value' => $v ];
		}
		return $out;
	}

	/** Put recorded options back: the value they had, or gone when they were not set. */
	public static function restore_options( array $recorded ) {
		foreach ( $recorded as $name => $was ) {
			if ( ! empty( $was['exists'] ) ) {
				update_option( (string) $name, $was['value'] ?? null, false );
			} else {
				delete_option( (string) $name );
			}
		}
	}

	/**
	 * Write a plan's values. A write names an option and either replaces it whole
	 * (key null) or sets one key inside it, keeping the rest.
	 */
	private static function write_plan( array $writes ) {
		$final = [];
		foreach ( $writes as $w ) {
			$name = $w['option'];
			if ( ! array_key_exists( $name, $final ) ) {
				$cur            = get_option( $name, self::ABSENT );
				$final[ $name ] = self::ABSENT === $cur ? null : $cur;
			}
			if ( null === ( $w['key'] ?? null ) ) {
				$final[ $name ] = $w['value'];
			} else {
				$arr                       = is_array( $final[ $name ] ) ? $final[ $name ] : [];
				$arr[ (string) $w['key'] ] = $w['value'];
				$final[ $name ]            = $arr;
			}
		}
		foreach ( $final as $name => $value ) {
			update_option( $name, $value, false );
		}
	}

	/** Drop the request caches of the readers a pack writes for. */
	private static function flush_readers() {
		if ( class_exists( 'ZDZ_Business_Profile' ) ) {
			ZDZ_Business_Profile::flush();
		}
		if ( class_exists( 'ZDZ_Private' ) && method_exists( 'ZDZ_Private', 'flush_lists' ) ) {
			ZDZ_Private::flush_lists();
		}
		if ( class_exists( 'ZDZ_Sealed' ) && method_exists( 'ZDZ_Sealed', 'flush_lists' ) ) {
			ZDZ_Sealed::flush_lists();
		}
	}

	// ─────────────────────────────────────────────── secrets

	/**
	 * Where a pack carries a credential VALUE instead of a reference. In
	 * connections.yml any key ending in key, secret, token or password; in every
	 * other file one ending in api_key, secret, token or password. Its value must
	 * be empty or a `secret://<row>` reference. The pack's own metadata block is
	 * not read.
	 *
	 * @return string[] "file: path" for each one found
	 */
	public static function find_secrets( array $files ) {
		$out = [];
		foreach ( $files as $stem => $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}
			unset( $data['pack'], $data['_meta'] );
			$re = 'connections' === $stem
				? '/(?:^|_)(?:api_?key|key|secret|token|password|passwd|pwd)$/i'
				: '/(?:^|_)(?:api_?key|apikey|secret|token|password|passwd)$/i';
			self::walk_secrets( $data, '', $re, (string) $stem, $out );
		}
		return array_values( array_unique( $out ) );
	}

	private static function walk_secrets( array $node, $prefix, $re, $stem, array &$out ) {
		foreach ( $node as $k => $v ) {
			$path = '' === $prefix ? (string) $k : $prefix . '.' . $k;
			if ( is_array( $v ) ) {
				self::walk_secrets( $v, $path, $re, $stem, $out );
				continue;
			}
			if ( ! is_string( $k ) || ! preg_match( $re, $k ) ) {
				continue;
			}
			if ( null === $v || '' === $v || false === $v ) {
				continue;
			}
			if ( is_string( $v ) && null !== self::secret_row( $v ) ) {
				continue;
			}
			$out[] = $stem . ': ' . $path;
		}
	}

	/**
	 * The credentials row a `secret://` reference names, as its full option name, or
	 * null when the value is not a reference. `secret://anthropic_api_key` and
	 * `secret://zdz_core_anthropic_api_key` name the same row.
	 */
	public static function secret_row( $ref ) {
		if ( ! is_string( $ref ) || ! preg_match( '#^secret://([a-z0-9_]{1,64})$#', trim( $ref ), $m ) ) {
			return null;
		}
		return str_starts_with( $m[1], 'zdz_core_' ) ? $m[1] : 'zdz_core_' . $m[1];
	}

	/** Every credentials row the Credentials screen manages, or null when that screen is not here. */
	private static function credential_rows() {
		if ( ! class_exists( 'ZDZ_Core_Credentials' ) ) {
			return null;
		}
		try {
			$rows = [];
			$defs = array_merge( (array) ZDZ_Core_Credentials::services(), method_exists( 'ZDZ_Core_Credentials', 'app_services' ) ? (array) ZDZ_Core_Credentials::app_services() : [] );
			foreach ( $defs as $def ) {
				foreach ( (array) ( $def['canonical'] ?? [] ) as $row ) {
					$rows[ (string) $row ] = true;
				}
			}
			return $rows;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Check one reference: [ refused row or null, note or null ]. Never reads the
	 * value; says only whether the row is filled.
	 */
	private static function check_secret_ref( $path, $ref, $expect_row ) {
		$row = self::secret_row( is_string( $ref ) ? $ref : '' );
		if ( null === $row ) {
			return [ [ 'path' => $path, 'why' => 'Write a secret://<credentials row> reference here, never a value.' ], null ];
		}
		if ( $row !== $expect_row ) {
			return [ [ 'path' => $path, 'why' => sprintf( 'This names the credentials row %s; this setting is read from %s.', $row, $expect_row ) ], null ];
		}
		$known = self::credential_rows();
		if ( is_array( $known ) && ! isset( $known[ $row ] ) ) {
			return [ [ 'path' => $path, 'why' => sprintf( 'No credentials row %s on this site (the app that reads it may not be active).', $row ) ], null ];
		}
		$filled = '' !== (string) get_option( $row, '' );
		return [ null, $filled
			? sprintf( '%s: the credentials row %s is filled.', $path, $row )
			: sprintf( '%s: the credentials row %s is empty — enter it on Zorderz → Credentials.', $path, $row ) ];
	}

	// ─────────────────────────────────────────────── people

	/**
	 * A pack's reference to a person, as initials: "AB", or { initials: "AB" }.
	 * '' when it is not two or three letters (the platform's routing initials).
	 */
	public static function person_ref( $ref ) {
		if ( is_array( $ref ) ) {
			$ref = $ref['initials'] ?? '';
		}
		$ini = strtoupper( trim( is_scalar( $ref ) ? (string) $ref : '' ) );
		return preg_match( '/^[A-Z]{2,3}$/', $ini ) ? $ini : '';
	}

	/**
	 * The one person among $ids whose initials (the platform's short code) are the
	 * reference's. Never a guess: no match, or more than one, is no person.
	 *
	 * @param mixed $ref A person_ref() reference.
	 * @param int[] $ids The people the asking app may name (its team).
	 * @return array{id:int,why:string}
	 */
	public static function person_id( $ref, array $ids ) {
		$ini = self::person_ref( $ref );
		if ( '' === $ini ) {
			return [ 'id' => 0, 'why' => 'A person is named by their initials (two or three letters).' ];
		}
		$meta  = ( class_exists( 'ZDZ_Person_Color' ) && defined( 'ZDZ_Person_Color::META_INITIALS' ) ) ? ZDZ_Person_Color::META_INITIALS : 'zdz_user_initials';
		$found = [];
		foreach ( $ids as $uid ) {
			$uid = (int) $uid;
			if ( $uid > 0 && strtoupper( trim( (string) get_user_meta( $uid, $meta, true ) ) ) === $ini ) {
				$found[ $uid ] = true;
			}
		}
		if ( 1 === count( $found ) ) {
			return [ 'id' => (int) array_key_first( $found ), 'why' => '' ];
		}
		return [ 'id' => 0, 'why' => $found
			? sprintf( 'More than one person here has the initials %s.', $ini )
			: sprintf( 'No one here who can be named has the initials %s.', $ini ) ];
	}

	/** A value as the preview shows it. */
	public static function show( $v ) {
		if ( self::ABSENT === $v || null === $v ) {
			return '(not set)';
		}
		if ( is_bool( $v ) ) {
			return $v ? 'yes' : 'no';
		}
		if ( is_array( $v ) ) {
			return function_exists( 'wp_json_encode' ) ? (string) wp_json_encode( $v ) : (string) json_encode( $v );
		}
		return (string) $v;
	}

	// ─────────────────────────────────────────────── the settings' plans

	/** An empty plan. */
	public static function plan() {
		return [ 'changes' => [], 'refused' => [], 'notes' => [], 'writes' => [] ];
	}

	/** Add a whole-option write and its change row, when the value differs from what is stored. */
	public static function plan_option( array &$plan, $path, $option, $value ) {
		$now = get_option( $option, self::ABSENT );
		if ( self::ABSENT !== $now && $now === $value ) {
			return;
		}
		$plan['writes'][]  = [ 'option' => $option, 'key' => null, 'value' => $value ];
		$plan['changes'][] = [ 'path' => $path, 'from' => self::show( $now ), 'to' => self::show( $value ) ];
	}

	/** Add a write of one key inside an array option, and its change row. */
	public static function plan_key( array &$plan, $path, $option, $key, $value ) {
		$now = get_option( $option, [] );
		$was = is_array( $now ) && array_key_exists( $key, $now ) ? $now[ $key ] : self::ABSENT;
		if ( self::ABSENT !== $was && $was === $value ) {
			return;
		}
		$plan['writes'][]  = [ 'option' => $option, 'key' => $key, 'value' => $value ];
		$plan['changes'][] = [ 'path' => $path, 'from' => self::show( $was ), 'to' => self::show( $value ) ];
	}

	/** Refuse the sub-keys of a setting that are not its own. */
	public static function refuse_extra( array &$plan, $path, $value, array $known ) {
		foreach ( is_array( $value ) ? array_keys( $value ) : [] as $k ) {
			if ( ! in_array( (string) $k, $known, true ) ) {
				$plan['refused'][] = [ 'path' => "$path.$k", 'why' => sprintf( 'Not a setting here (known: %s).', implode( ', ', $known ) ) ];
			}
		}
	}

	/** A list from a pack value: a YAML list, or one comma-separated string. */
	public static function as_list( $v ) {
		if ( is_array( $v ) ) {
			return array_values( $v );
		}
		return is_scalar( $v ) && '' !== trim( (string) $v ) ? preg_split( '/\s*,\s*/', trim( (string) $v ) ) : [];
	}

	/**
	 * profile.yml email.work_domains → the Scheduler's work-calendar domains (option zsch_work_domains,
	 * read by ZSCH_Settings::work_domains()). Each entry must be a whole mail domain: lower case, with a
	 * dot, no leading or trailing dot. A colleague sees the titles of events on calendars of exactly these
	 * domains; with none, no titles are shown.
	 */
	public static function plan_work_domains( array $data ) {
		$plan = self::plan();
		$path = 'email.work_domains';
		$ok   = [];
		foreach ( self::as_list( $data[ $path ] ) as $raw ) {
			$d = is_scalar( $raw ) ? trim( strtolower( (string) $raw ), " \t\n\r\0\x0B." ) : '';
			if ( preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/', $d ) ) {
				$ok[ $d ] = true;
			} else {
				$plan['refused'][] = [ 'path' => $path, 'why' => sprintf( '"%s" is not a mail domain (example.com).', is_scalar( $raw ) ? (string) $raw : gettype( $raw ) ) ];
			}
		}
		if ( $ok ) {
			$value = sanitize_text_field( implode( ', ', array_keys( $ok ) ) );
			self::plan_option( $plan, $path, 'zsch_work_domains', $value );
			if ( ! class_exists( 'ZSCH_Settings' ) ) {
				$plan['notes'][] = 'the Scheduler app is not active here; the domains are kept for when it is.';
			}
		}
		return $plan;
	}

	/** org.yml alerts.role_category_grants → ZDZ_Alert_Router's per-role category grants, cleaned by the router's own rule. */
	public static function plan_alert_grants( array $data ) {
		$plan = self::plan();
		$path = 'alerts.role_category_grants';
		$raw  = $data[ $path ];
		if ( ! class_exists( 'ZDZ_Alert_Router' ) || ! method_exists( 'ZDZ_Alert_Router', 'clean_role_category_grants' ) ) {
			$plan['refused'][] = [ 'path' => $path, 'why' => 'The notice router is not loaded here.' ];
			return $plan;
		}
		$clean = ZDZ_Alert_Router::clean_role_category_grants( $raw );
		foreach ( is_array( $raw ) ? $raw : [] as $role => $cats ) {
			if ( ! isset( $clean[ (string) $role ] ) && ! isset( ZDZ_Alert_Router::ROLE_CHANNEL_DEFAULTS[ (string) $role ] ) ) {
				$plan['refused'][] = [ 'path' => "$path.$role", 'why' => 'Not a role with notice channels. The shared device never gets a grant; role slugs are the platform\'s own.' ];
				continue;
			}
			foreach ( is_array( $cats ) ? $cats : [] as $cat => $chs ) {
				$kept = $clean[ (string) $role ][ sanitize_key( (string) $cat ) ] ?? [];
				$bad  = array_diff( array_map( 'strval', (array) $chs ), $kept );
				if ( $bad ) {
					$plan['refused'][] = [ 'path' => "$path.$role.$cat", 'why' => sprintf( 'Not a channel: %s (channels are %s).', implode( ', ', $bad ), implode( ', ', ZDZ_Alert_Router::CHANNELS ) ) ];
				}
			}
			if ( ! is_array( $cats ) ) {
				$plan['refused'][] = [ 'path' => "$path.$role", 'why' => 'Write each category with its list of channels.' ];
			}
		}
		if ( $clean ) {
			self::plan_option( $plan, $path, ZDZ_Alert_Router::OPT_ROLE_CATEGORY_GRANTS, $clean );
			// A category no registered notice uses opens nothing: said here, so a typo does not grant nothing silently.
			// Kept, not refused: an app installed later may bring notices of that category.
			$in_use = method_exists( 'ZDZ_Alert_Router', 'categories_in_use' ) ? ZDZ_Alert_Router::categories_in_use() : [];
			if ( ! $in_use ) {
				$plan['notes'][] = 'the notice types are not loaded here, so the categories could not be checked against them.';
			} else {
				$unused = [];
				foreach ( $clean as $role => $cats ) {
					foreach ( array_keys( $cats ) as $cat ) {
						if ( ! in_array( (string) $cat, $in_use, true ) ) {
							$unused[ (string) $cat ][] = (string) $role;
						}
					}
				}
				foreach ( $unused as $cat => $roles ) {
					$plan['notes'][] = sprintf( 'no notice on this site uses the category "%s" (granted to %s), so the grant opens nothing until one does; categories in use: %s. Check the spelling.', $cat, implode( ', ', $roles ), implode( ', ', $in_use ) );
				}
			}
		}
		return $plan;
	}

	/** rules.yml notifications.quiet_hours → zdz_quiet_hours, through ZDZ_Quiet_Hours::sanitize_setting(). */
	public static function plan_quiet_hours( array $data ) {
		$plan = self::plan();
		$path = 'notifications.quiet_hours';
		$raw  = $data[ $path ];
		if ( ! class_exists( 'ZDZ_Quiet_Hours' ) ) {
			$plan['refused'][] = [ 'path' => $path, 'why' => 'Quiet hours are not loaded here.' ];
			return $plan;
		}
		self::refuse_extra( $plan, $path, $raw, [ 'start', 'end' ] );
		$w = ZDZ_Quiet_Hours::sanitize_setting( $raw );
		if ( null === $w ) {
			$plan['refused'][] = [ 'path' => $path, 'why' => 'Two 24-hour times are needed, start and end ("21:00", "07:00"). The same time in both turns quiet hours off.' ];
			return $plan;
		}
		self::plan_option( $plan, $path, ZDZ_Quiet_Hours::OPT, $w );
		return $plan;
	}

	/** rules.yml contacts.reaching_results → the `zdz_contact_reaching_results` filter. Only the contact log's own answers. */
	public static function plan_reaching_results( array $data ) {
		$plan = self::plan();
		$path = 'contacts.reaching_results';
		if ( ! class_exists( 'ZDZ_Contacts' ) ) {
			$plan['refused'][] = [ 'path' => $path, 'why' => 'The contact log is not loaded here.' ];
			return $plan;
		}
		$ok = [];
		foreach ( self::as_list( $data[ $path ] ) as $r ) {
			$r = is_scalar( $r ) ? strtolower( trim( (string) $r ) ) : '';
			if ( in_array( $r, ZDZ_Contacts::RESULTS, true ) ) {
				$ok[ $r ] = true;
			} else {
				$plan['refused'][] = [ 'path' => $path, 'why' => sprintf( '"%s" is not an answer the contact log asks (%s).', $r, implode( ', ', ZDZ_Contacts::RESULTS ) ) ];
			}
		}
		if ( ! $ok ) {
			$plan['refused'][] = [ 'path' => $path, 'why' => 'At least one answer must count as reaching a customer.' ];
			return $plan;
		}
		$value = array_values( array_intersect( ZDZ_Contacts::RESULTS, array_keys( $ok ) ) );
		self::plan_key( $plan, $path, self::SETTINGS_OPTION, 'contacts.reaching_results', $value );
		return $plan;
	}

	/** rules.yml usage_records.raw_days → the `zdz_audit_raw_days` filter. Whole days, never under the usage records' floor. */
	public static function plan_raw_days( array $data ) {
		$plan = self::plan();
		$path = 'usage_records.raw_days';
		$v    = $data[ $path ];
		if ( ! class_exists( 'ZDZ_Admin_Dashboard' ) || ! defined( 'ZDZ_Admin_Dashboard::RAW_DAYS_MIN' ) ) {
			$plan['refused'][] = [ 'path' => $path, 'why' => 'Usage records are not loaded here.' ];
			return $plan;
		}
		$min = (int) ZDZ_Admin_Dashboard::RAW_DAYS_MIN;
		if ( ! ( is_int( $v ) || ( is_string( $v ) && preg_match( '/^\d+$/', trim( $v ) ) ) ) || (int) $v < $min ) {
			$plan['refused'][] = [ 'path' => $path, 'why' => sprintf( 'A whole number of days, at least %d (some views look back 60 days).', $min ) ];
			return $plan;
		}
		self::plan_key( $plan, $path, self::SETTINGS_OPTION, 'usage_records.raw_days', (int) $v );
		$plan['notes'][] = 'the usage-records notice people are asked to agree to states this number of days, so a new number asks everyone again.';
		return $plan;
	}

	/** rules.yml media.same_property_m → the `zdz_geocoder_same_property_m` filter, inside the geocoder's own range. */
	public static function plan_same_property( array $data ) {
		$plan = self::plan();
		$path = 'media.same_property_m';
		$v    = $data[ $path ];
		if ( ! class_exists( 'ZDZ_Geocoder' ) || ! defined( 'ZDZ_Geocoder::SAME_PROPERTY_MIN_M' ) ) {
			$plan['refused'][] = [ 'path' => $path, 'why' => 'The geocoder is not loaded here.' ];
			return $plan;
		}
		if ( ! is_numeric( $v ) || (float) $v < ZDZ_Geocoder::SAME_PROPERTY_MIN_M || (float) $v > ZDZ_Geocoder::SAME_PROPERTY_MAX_M ) {
			$plan['refused'][] = [ 'path' => $path, 'why' => sprintf( 'Metres, from %d to %d.', (int) ZDZ_Geocoder::SAME_PROPERTY_MIN_M, (int) ZDZ_Geocoder::SAME_PROPERTY_MAX_M ) ];
			return $plan;
		}
		self::plan_key( $plan, $path, self::SETTINGS_OPTION, 'media.same_property_m', (float) $v );
		return $plan;
	}

	/** True when the Ai router and its setup screen are both here (their checks are the ones used). */
	private static function ai_loaded() {
		return class_exists( 'ZDZ_Core_LLM' ) && class_exists( 'ZDZ_LLM_Admin' ) && method_exists( 'ZDZ_LLM_Admin', 'clean_amount' ) && method_exists( 'ZDZ_LLM_Admin', 'clean_model' );
	}

	/**
	 * rules.yml ai_spend.monthly_usd { app, all, ceiling } → zdz_llm_budgets, checked as the Ai usage
	 * setup checks it. The site's own line (app) is required: without it nothing is sent, so a pack that
	 * gives only the others sets nothing.
	 */
	public static function plan_ai_spend( array $data ) {
		$plan = self::plan();
		$path = 'ai_spend.monthly_usd';
		$raw  = $data[ $path ];
		if ( ! self::ai_loaded() ) {
			$plan['refused'][] = [ 'path' => $path, 'why' => 'The Ai router is not loaded here.' ];
			return $plan;
		}
		self::refuse_extra( $plan, $path, $raw, [ 'app', 'all', 'ceiling' ] );
		$b = [];
		foreach ( [ 'app', 'all', 'ceiling' ] as $k ) {
			if ( ! is_array( $raw ) || ! array_key_exists( $k, $raw ) ) {
				continue;
			}
			$v = ZDZ_LLM_Admin::clean_amount( $raw[ $k ], ZDZ_Core_LLM::MAX_BUDGET_USD );
			if ( null === $v ) {
				$plan['refused'][] = [ 'path' => "$path.$k", 'why' => sprintf( 'US dollars a month, from 0 to %s.', number_format( ZDZ_Core_LLM::MAX_BUDGET_USD, 0, '.', ',' ) ) ];
				continue;
			}
			$b[ $k ] = round( $v, 2 );
		}
		if ( ! isset( $b['app'] ) ) {
			$plan['refused'][] = [ 'path' => "$path.app", 'why' => 'The site\'s own monthly line is required; without it nothing is sent and no budget is set.' ];
			return $plan;
		}
		self::plan_option( $plan, $path, ZDZ_Core_LLM::BUDGET_OPTION, $b );
		return $plan;
	}

	/**
	 * rules.yml security_monitor.public_registration → the monitor's registration posture
	 * (zsm_registration_open). "open" counts registration as open whatever WordPress says, "follow"
	 * reads WordPress's own setting. "closed" is not taken from a pack: it lowers how serious a flaw
	 * that needs a login is reported, and only a person who has checked this site may say so.
	 */
	public static function plan_registration( array $data ) {
		$plan = self::plan();
		$path = 'security_monitor.public_registration';
		$v    = $data[ $path ];
		$v    = true === $v ? 'open' : ( false === $v ? 'closed' : strtolower( trim( is_scalar( $v ) ? (string) $v : '' ) ) );
		$map  = [ 'open' => 'yes', 'follow' => '' ];
		if ( 'closed' === $v ) {
			$plan['refused'][] = [ 'path' => $path, 'why' => '"closed" is set on Zorderz → Security Monitor, not by a pack: it lowers how serious flaws that need a login are reported.' ];
			return $plan;
		}
		if ( ! isset( $map[ $v ] ) ) {
			$plan['refused'][] = [ 'path' => $path, 'why' => 'Write "open" or "follow".' ];
			return $plan;
		}
		self::plan_option( $plan, $path, 'zsm_registration_open', $map[ $v ] );
		if ( ! class_exists( 'ZSM' ) ) {
			$plan['notes'][] = 'the Security Monitor app is not active here; the posture is kept for when it is.';
		}
		return $plan;
	}

	/**
	 * connections.yml ai.* → the Ai router.
	 *   models            { primary, quick, fallback } → the Model Registry's llm_* slots
	 *   prices.<model>    five USD-per-million amounts → zdz_llm_model_prices (one row per model; others kept)
	 *   anthropic         inference_geo, geo_price_factor → zdz_llm_connection; workspace_id and api_key are
	 *                     only checked against the Credentials screen
	 *   roles.<role>      model, effort, cap_usd → zdz_llm_role_overrides (the role's other choices kept)
	 *   external_callers  code words → the `zdz_llm_external_callers` filter
	 * Every value is checked as the Ai usage setup and the router check it.
	 */
	public static function plan_ai_connection( array $data ) {
		$plan = self::plan();
		if ( ! self::ai_loaded() ) {
			$plan['refused'][] = [ 'path' => 'ai', 'why' => 'The Ai router is not loaded here.' ];
			return $plan;
		}
		$prefix = class_exists( 'ZDZ_Model_Registry' ) ? ZDZ_Model_Registry::OPT_PREFIX : 'zdz_model_slot_';

		// The models the slots will hold once this pack is applied.
		$models = [];
		foreach ( ZDZ_Core_LLM::SLOTS as $k => $slot ) {
			$models[ $k ] = (string) get_option( $prefix . $slot, '' );
		}
		if ( isset( $data['ai.models'] ) ) {
			$raw = $data['ai.models'];
			self::refuse_extra( $plan, 'ai.models', $raw, array_keys( ZDZ_Core_LLM::SLOTS ) );
			foreach ( ZDZ_Core_LLM::SLOTS as $k => $slot ) {
				if ( ! is_array( $raw ) || ! array_key_exists( $k, $raw ) || '' === $raw[ $k ] ) {
					continue;
				}
				$m = ZDZ_LLM_Admin::clean_model( $raw[ $k ] );
				if ( '' === $m ) {
					$plan['refused'][] = [ 'path' => "ai.models.$k", 'why' => 'A model id as the provider spells it: letters, digits and . _ : / -, at most 100 characters.' ];
					continue;
				}
				$models[ $k ] = $m;
				self::plan_option( $plan, "ai.models.$k", $prefix . $slot, $m );
			}
		}
		$approved = array_values( array_unique( array_filter( $models, 'strlen' ) ) );

		if ( isset( $data['ai.prices'] ) ) {
			foreach ( is_array( $data['ai.prices'] ) ? $data['ai.prices'] : [] as $model => $p ) {
				$m = ZDZ_LLM_Admin::clean_model( (string) $model );
				if ( '' === $m || ! in_array( $m, $approved, true ) ) {
					$plan['refused'][] = [ 'path' => "ai.prices.$model", 'why' => 'Prices are kept only for a model one of the three slots names.' ];
					continue;
				}
				$vals = [];
				foreach ( is_array( $p ) && 5 === count( $p ) ? array_values( $p ) : [] as $x ) {
					$vals[] = ZDZ_LLM_Admin::clean_amount( $x, ZDZ_LLM_Admin::MAX_PRICE );
				}
				if ( 5 !== count( $vals ) || in_array( null, $vals, true ) ) {
					$plan['refused'][] = [ 'path' => "ai.prices.$model", 'why' => 'Five amounts in USD per million tokens: in, cache write 5 min, cache write 1 h, cache read, out.' ];
					continue;
				}
				self::plan_key( $plan, "ai.prices.$model", ZDZ_Core_LLM::PRICES_OPTION, $m, $vals );
			}
			if ( ! is_array( $data['ai.prices'] ) ) {
				$plan['refused'][] = [ 'path' => 'ai.prices', 'why' => 'Write one row of five amounts per model.' ];
			}
		}

		if ( isset( $data['ai.anthropic'] ) ) {
			$raw = is_array( $data['ai.anthropic'] ) ? $data['ai.anthropic'] : [];
			self::refuse_extra( $plan, 'ai.anthropic', $data['ai.anthropic'], [ 'inference_geo', 'geo_price_factor', 'workspace_id', 'api_key' ] );
			$cur  = get_option( ZDZ_Core_LLM::CONN_OPTION, [] );
			$cur  = is_array( $cur ) ? $cur : [];
			$conn = [
				'inference_geo'    => (string) ( $cur['inference_geo'] ?? '' ),
				'geo_price_factor' => isset( $cur['geo_price_factor'] ) && is_numeric( $cur['geo_price_factor'] ) ? (float) $cur['geo_price_factor'] : 1.0,
			];
			$set  = false;
			if ( array_key_exists( 'inference_geo', $raw ) ) {
				$geo = strtolower( trim( is_scalar( $raw['inference_geo'] ) ? (string) $raw['inference_geo'] : '' ) );
				if ( '' === $geo || preg_match( '/^[a-z]{2,12}$/', $geo ) ) {
					$conn['inference_geo'] = $geo;
					$set                   = true;
				} else {
					$plan['refused'][] = [ 'path' => 'ai.anthropic.inference_geo', 'why' => 'A short region word (2 to 12 letters), or empty for the provider\'s default.' ];
				}
			}
			if ( array_key_exists( 'geo_price_factor', $raw ) ) {
				$f = ZDZ_LLM_Admin::clean_amount( $raw['geo_price_factor'], 3.0 );
				if ( null === $f || $f < 1.0 ) {
					$plan['refused'][] = [ 'path' => 'ai.anthropic.geo_price_factor', 'why' => 'From 1 to 3: a region never makes a call cheaper here.' ];
				} else {
					$conn['geo_price_factor'] = $f;
					$set                      = true;
				}
			}
			if ( $set ) {
				self::plan_option( $plan, 'ai.anthropic.region', ZDZ_Core_LLM::CONN_OPTION, $conn );
				if ( '' === $conn['inference_geo'] && 1.0 !== (float) $conn['geo_price_factor'] ) {
					$plan['notes'][] = 'the price factor counts only once a region is set.';
				}
			}
			if ( array_key_exists( 'workspace_id', $raw ) && '' !== (string) $raw['workspace_id'] ) {
				$want = trim( (string) $raw['workspace_id'] );
				$have = method_exists( 'ZDZ_Core_Settings', 'get_credential' ) ? trim( (string) ZDZ_Core_Settings::get_credential( 'anthropic_workspace_id' ) ) : null;
				$plan['notes'][] = null === $have
					? 'ai.anthropic.workspace_id: could not be compared here; it is set on Zorderz → Credentials.'
					: ( $have === $want
						? 'ai.anthropic.workspace_id: matches the workspace set on Zorderz → Credentials.'
						: 'ai.anthropic.workspace_id: differs from the workspace set on Zorderz → Credentials (or none is set there). It is set there, next to the key it belongs to; a pack does not change it.' );
			}
			if ( array_key_exists( 'api_key', $raw ) && '' !== (string) $raw['api_key'] ) {
				list( $bad, $note ) = self::check_secret_ref( 'ai.anthropic.api_key', $raw['api_key'], 'zdz_core_anthropic_api_key' );
				if ( $bad ) {
					$plan['refused'][] = $bad;
				}
				if ( $note ) {
					$plan['notes'][] = $note;
				}
			}
		}

		if ( isset( $data['ai.roles'] ) ) {
			$ov = get_option( ZDZ_Core_LLM::ROLES_OPTION, [] );
			$ov = is_array( $ov ) ? $ov : [];
			foreach ( is_array( $data['ai.roles'] ) ? $data['ai.roles'] : [] as $role => $want ) {
				$p = "ai.roles.$role";
				if ( ! isset( ZDZ_Core_LLM::ROLES[ $role ] ) ) {
					$plan['refused'][] = [ 'path' => $p, 'why' => sprintf( 'Not a kind of Ai call (%s).', implode( ', ', array_keys( ZDZ_Core_LLM::ROLES ) ) ) ];
					continue;
				}
				if ( ! is_array( $want ) ) {
					$plan['refused'][] = [ 'path' => $p, 'why' => 'Write model, effort or cap_usd under the role.' ];
					continue;
				}
				foreach ( [ 'provider', 'poe_model' ] as $k ) {
					if ( array_key_exists( $k, $want ) ) {
						$plan['refused'][] = [ 'path' => "$p.$k", 'why' => 'Which provider receives a kind of call is chosen by a person on this site, not by a pack.' ];
					}
				}
				self::refuse_extra( $plan, $p, array_diff_key( $want, [ 'provider' => 1, 'poe_model' => 1 ] ), [ 'model', 'effort', 'cap_usd' ] );
				$row = isset( $ov[ $role ] ) && is_array( $ov[ $role ] ) ? $ov[ $role ] : [];
				$new = $row;
				if ( array_key_exists( 'model', $want ) ) {
					if ( is_string( $want['model'] ) && in_array( $want['model'], $approved, true ) ) {
						$new['model'] = $want['model'];
					} else {
						$plan['refused'][] = [ 'path' => "$p.model", 'why' => 'One of the models the three slots name.' ];
					}
				}
				if ( array_key_exists( 'effort', $want ) ) {
					if ( in_array( $want['effort'], [ 'medium', 'high' ], true ) ) {
						$new['effort'] = $want['effort'];
					} else {
						$plan['refused'][] = [ 'path' => "$p.effort", 'why' => 'medium or high.' ];
					}
				}
				if ( array_key_exists( 'cap_usd', $want ) ) {
					$cap = $want['cap_usd'];
					if ( is_numeric( $cap ) && (float) $cap > 0 && (float) $cap <= ZDZ_Core_LLM::MAX_CAP_USD ) {
						$new['cap_usd'] = (float) $cap;
					} else {
						$plan['refused'][] = [ 'path' => "$p.cap_usd", 'why' => sprintf( 'More than 0 and at most %s US dollars a call.', (string) ZDZ_Core_LLM::MAX_CAP_USD ) ];
					}
				}
				if ( $new !== $row ) {
					self::plan_key( $plan, $p, ZDZ_Core_LLM::ROLES_OPTION, (string) $role, $new );
				}
			}
		}

		if ( isset( $data['ai.external_callers'] ) ) {
			$ok   = [];
			$code = class_exists( 'ZDZ_LLM_Ledger' ) && defined( 'ZDZ_LLM_Ledger::CODE' ) ? ZDZ_LLM_Ledger::CODE : null;
			foreach ( self::as_list( $data['ai.external_callers'] ) as $c ) {
				$c = strtolower( trim( is_scalar( $c ) ? (string) $c : '' ) );
				if ( null === $code || ! preg_match( $code, $c ) ) {
					$plan['refused'][] = [ 'path' => 'ai.external_callers', 'why' => sprintf( '"%s" is not a caller code word.', $c ) ];
				} elseif ( isset( ZDZ_Core_LLM::CALLERS[ $c ] ) ) {
					$plan['refused'][] = [ 'path' => 'ai.external_callers', 'why' => sprintf( '"%s" is an app of this site: its spending always counts toward the site\'s own line.', $c ) ];
				} else {
					$ok[ $c ] = true;
				}
			}
			if ( $ok ) {
				self::plan_key( $plan, 'ai.external_callers', self::SETTINGS_OPTION, 'ai.external_callers', array_keys( $ok ) );
			}
		}
		return $plan;
	}

	/** connections.yml vulnerability_feed.key: a reference to the feed key's credentials row, checked and never read. */
	public static function plan_feed_key( array $data ) {
		$plan = self::plan();
		list( $bad, $note ) = self::check_secret_ref( 'vulnerability_feed.key', $data['vulnerability_feed.key'], 'zdz_core_wordfence_feed_key' );
		if ( $bad ) {
			$plan['refused'][] = $bad;
		}
		if ( $note ) {
			$plan['notes'][] = $note;
		}
		return $plan;
	}

	/**
	 * territories.yml place_names → the privacy coder.
	 *   multi_word   places of two or more words never read as a name (zdz_private_places). A one-word town
	 *                is refused: a town's name can be a customer's surname.
	 *   ask_visible  places the "Ask chat about this" pass leaves visible (zdz_ask_place_names).
	 */
	public static function plan_places( array $data ) {
		$plan = self::plan();
		if ( isset( $data['place_names.multi_word'] ) ) {
			$p = 'place_names.multi_word';
			if ( ! class_exists( 'ZDZ_Private' ) || ! method_exists( 'ZDZ_Private', 'clean_list' ) ) {
				$plan['refused'][] = [ 'path' => $p, 'why' => 'The privacy coder is not loaded here.' ];
			} else {
				$ok = [];
				foreach ( self::as_list( $data[ $p ] ) as $w ) {
					$n = ZDZ_Private::clean_list( [ $w ] );
					if ( ! $n ) {
						$plan['refused'][] = [ 'path' => $p, 'why' => sprintf( '"%s" is not a place name.', is_scalar( $w ) ? (string) $w : gettype( $w ) ) ];
					} elseif ( count( explode( ' ', (string) $n[0] ) ) < 2 ) {
						$plan['refused'][] = [ 'path' => $p, 'why' => sprintf( '"%s" is one word. One-word towns are not listed: a town\'s name can be a customer\'s surname.', (string) $w ) ];
					} else {
						$ok[ (string) $n[0] ] = trim( (string) $w );
					}
				}
				if ( $ok ) {
					self::plan_option( $plan, $p, ZDZ_Private::PLACES_OPT, array_values( $ok ) );
				}
			}
		}
		if ( isset( $data['place_names.ask_visible'] ) ) {
			$p = 'place_names.ask_visible';
			if ( ! class_exists( 'ZDZ_Ask_Context' ) ) {
				$plan['refused'][] = [ 'path' => $p, 'why' => '"Ask chat about this" is not loaded here.' ];
			} else {
				$ok = [];
				foreach ( self::as_list( $data[ $p ] ) as $w ) {
					$n = is_scalar( $w ) ? ZDZ_Ask_Context::norm( (string) $w ) : '';
					if ( '' === $n ) {
						$plan['refused'][] = [ 'path' => $p, 'why' => sprintf( '"%s" is not a place name.', is_scalar( $w ) ? (string) $w : gettype( $w ) ) ];
					} else {
						$ok[ $n ] = trim( (string) $w );
					}
				}
				if ( $ok ) {
					self::plan_option( $plan, $p, ZDZ_Ask_Context::PLACES_OPT, array_values( $ok ) );
				}
			}
		}
		return $plan;
	}

	/**
	 * territories.yml jurisdiction → the access-code reader.
	 *   code_words  the region's own code-book words ("state building code 2022" is not an access code)
	 *               → zdz_sealed_region_words
	 *   postcode    { digits, region }: the region's postcode shape → zdz_sealed_postcode. A pack must write
	 *               the whole shape from ^ to $, so a shorter number is never let off as a postcode.
	 */
	public static function plan_jurisdiction( array $data ) {
		$plan = self::plan();
		if ( ! class_exists( 'ZDZ_Sealed' ) || ! method_exists( 'ZDZ_Sealed', 'clean_words' ) || ! method_exists( 'ZDZ_Sealed', 'safe_pattern' ) ) {
			$plan['refused'][] = [ 'path' => 'jurisdiction', 'why' => 'The access-code reader is not loaded here.' ];
			return $plan;
		}
		if ( isset( $data['jurisdiction.code_words'] ) ) {
			$p  = 'jurisdiction.code_words';
			$ok = [];
			foreach ( self::as_list( $data[ $p ] ) as $w ) {
				$c = ZDZ_Sealed::clean_words( [ $w ] );
				if ( $c ) {
					$ok[ $c[0] ] = true;
				} else {
					$plan['refused'][] = [ 'path' => $p, 'why' => sprintf( '"%s": letters, digits, spaces, dots and hyphens, starting with a letter or digit, at most 41 characters.', is_scalar( $w ) ? (string) $w : gettype( $w ) ) ];
				}
			}
			if ( $ok ) {
				self::plan_option( $plan, $p, ZDZ_Sealed::REGION_WORDS_OPT, array_map( 'strval', array_keys( $ok ) ) );
			}
		}
		if ( isset( $data['jurisdiction.postcode'] ) ) {
			$p   = 'jurisdiction.postcode';
			$raw = $data[ $p ];
			self::refuse_extra( $plan, $p, $raw, [ 'digits', 'region' ] );
			$digits = is_array( $raw ) && is_string( $raw['digits'] ?? null ) ? trim( $raw['digits'] ) : '';
			$region = is_array( $raw ) && is_string( $raw['region'] ?? null ) ? trim( $raw['region'] ) : '';
			if ( '' === ZDZ_Sealed::safe_pattern( $digits ) || ! preg_match( '/^\^.*\$$/', $digits ) ) {
				$plan['refused'][] = [ 'path' => "$p.digits", 'why' => 'A pattern for the whole postcode, from ^ to $ (for example ^\d{5}$), at most 120 characters, without "/".' ];
			} elseif ( '' !== $region && '' === ZDZ_Sealed::safe_pattern( $region ) ) {
				$plan['refused'][] = [ 'path' => "$p.region", 'why' => 'A pattern for the region\'s words, at most 120 characters, without "/".' ];
			} else {
				self::plan_option( $plan, $p, ZDZ_Sealed::POSTCODE_OPT, [ 'digits' => $digits, 'region' => $region ] );
			}
		}
		return $plan;
	}

	/**
	 * catalog.yml vocabulary.things → the ask bar's trade words (option zdz_ask_trade_words, read by
	 * ZDZ_Ask_Phrases::trade_words()): the words of the business's trade for things, so a rule can tell a thing from
	 * a person. Each a lower-case word or short phrase of letters, digits, spaces, hyphens and apostrophes, at most 31
	 * characters, starting with a letter; anything else is refused by name, never turned into a pattern.
	 */
	public static function plan_trade_words( array $data ) {
		$plan = self::plan();
		$path = 'vocabulary.things';
		$ok   = [];
		foreach ( self::as_list( $data[ $path ] ) as $w ) {
			$c = is_scalar( $w ) ? strtolower( trim( preg_replace( '/\s+/', ' ', (string) $w ) ) ) : '';
			if ( preg_match( '/^[a-z][a-z0-9 \'\-]{0,30}$/', $c ) ) {
				$ok[ $c ] = true;
			} else {
				$plan['refused'][] = [ 'path' => $path, 'why' => sprintf( '"%s" is not a plain word (letters, digits, spaces, hyphens; at most 31 characters, a letter first).', is_scalar( $w ) ? (string) $w : gettype( $w ) ) ];
			}
		}
		if ( $ok ) {
			$words = array_keys( $ok );
			sort( $words );
			self::plan_option( $plan, $path, 'zdz_ask_trade_words', $words );
		}
		return $plan;
	}

	/**
	 * knowledge.yml addresses.street_first_words → the street words a region writes BEFORE the street's name ("Rue",
	 * "Strada"), on top of the few Core knows (option zdz_street_first_words, read by ZDZ_Sealed::street_first_words()
	 * for the access-code reader and "Ask chat about this"). Each is a word or two of letters, at most 24 characters.
	 */
	public static function plan_street_words( array $data ) {
		$plan = self::plan();
		$path = 'addresses.street_first_words';
		if ( ! class_exists( 'ZDZ_Sealed' ) || ! defined( 'ZDZ_Sealed::STREET_FIRST_OPT' ) ) {
			$plan['refused'][] = [ 'path' => $path, 'why' => 'The address readers are not loaded here.' ];
			return $plan;
		}
		$ok = [];
		foreach ( self::as_list( $data[ $path ] ) as $w ) {
			$c = is_string( $w ) ? mb_strtolower( trim( preg_replace( '/\s+/u', ' ', $w ) ), 'UTF-8' ) : '';
			if ( preg_match( '/^\p{L}[\p{L} ]{0,23}$/u', $c ) ) {
				$ok[ $c ] = true;
			} else {
				$plan['refused'][] = [ 'path' => $path, 'why' => sprintf( '"%s" is not a street word (a word or two of letters, at most 24 characters).', is_scalar( $w ) ? (string) $w : gettype( $w ) ) ];
			}
		}
		if ( $ok ) {
			$words = array_map( 'strval', array_keys( $ok ) );
			sort( $words );
			self::plan_option( $plan, $path, ZDZ_Sealed::STREET_FIRST_OPT, $words );
		}
		return $plan;
	}

	/**
	 * org.yml work_hours.default { days, start, end } → the business's usual hours (option zdz_work_hours_default,
	 * read by ZDZ_For_You::default_hours()). Days are weekday names or ISO numbers 1 (Monday) to 7; times 24-hour
	 * HH:MM; an end at or before the start runs past midnight. Checked by ZDZ_For_You::sanitize_hours().
	 */
	public static function plan_work_hours( array $data ) {
		$plan = self::plan();
		$path = 'work_hours.default';
		$raw  = $data[ $path ];
		if ( ! class_exists( 'ZDZ_For_You' ) || ! method_exists( 'ZDZ_For_You', 'sanitize_hours' ) ) {
			$plan['refused'][] = [ 'path' => $path, 'why' => 'Usual hours are not loaded here.' ];
			return $plan;
		}
		self::refuse_extra( $plan, $path, $raw, [ 'days', 'start', 'end' ] );
		$names = [ 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 7 ];
		$days  = [];
		$bad   = false;
		foreach ( self::as_list( is_array( $raw ) ? ( $raw['days'] ?? [] ) : [] ) as $d ) {
			$n = is_numeric( $d ) ? (int) $d : ( $names[ strtolower( trim( (string) $d ) ) ] ?? 0 );
			if ( $n >= 1 && $n <= 7 ) {
				$days[] = $n;
			} else {
				$bad = true;
			}
		}
		$clean = $bad || ! $days ? null : ZDZ_For_You::sanitize_hours( [ 'days' => $days, 'start' => is_array( $raw ) ? ( $raw['start'] ?? '' ) : '', 'end' => is_array( $raw ) ? ( $raw['end'] ?? '' ) : '' ] );
		if ( null === $clean ) {
			$plan['refused'][] = [ 'path' => $path, 'why' => 'Days (weekday names, or 1 for Monday to 7), a start and an end, 24-hour HH:MM, not the same time.' ];
			return $plan;
		}
		self::plan_option( $plan, $path, 'zdz_work_hours_default', $clean );
		return $plan;
	}

	/**
	 * knowledge.yml names.soundalikes → the name matcher's curated groups (option zdz_name_homophones, read by
	 * ZDZ_Name_Match). The groups are ADDED to what the site already holds (ZDZ_Name_Match::merge_maps(): nothing is
	 * removed). Buckets: surnames, given_names, distinct_but_confusable, not_soundalike_flags; each group lists its
	 * spellings (or names), two or more, each a name of letters, spaces, hyphens, apostrophes and dots. The names are
	 * private: the preview shows how many groups change, never a name.
	 */
	public static function plan_soundalikes( array $data ) {
		$plan = self::plan();
		$path = 'names.soundalikes';
		$raw  = $data[ $path ];
		if ( ! class_exists( 'ZDZ_Name_Match' ) || ! method_exists( 'ZDZ_Name_Match', 'merge_maps' ) ) {
			$plan['refused'][] = [ 'path' => $path, 'why' => 'Name matching is not loaded here.' ];
			return $plan;
		}
		$add = [];
		foreach ( is_array( $raw ) ? $raw : [] as $bucket => $groups ) {
			if ( ! in_array( (string) $bucket, ZDZ_Name_Match::BUCKETS, true ) ) {
				$plan['refused'][] = [ 'path' => "$path.$bucket", 'why' => sprintf( 'Not a kind of group (kinds: %s).', implode( ', ', ZDZ_Name_Match::BUCKETS ) ) ];
				continue;
			}
			foreach ( is_array( $groups ) ? array_values( $groups ) : [ null ] as $i => $g ) {
				$key   = is_array( $g ) && isset( $g['spellings'] ) ? 'spellings' : 'names';
				$names = is_array( $g ) && is_array( $g[ $key ] ?? null ) ? array_values( $g[ $key ] ) : [];
				$ok    = count( $names ) >= 2;
				foreach ( $names as $n ) {
					$ok = $ok && is_string( $n ) && 1 === preg_match( "/^\\p{L}[\\p{L} .'\\-]{0,59}$/u", trim( $n ) );
				}
				if ( ! $ok ) {
					$plan['refused'][] = [ 'path' => "$path.$bucket." . ( $i + 1 ), 'why' => 'A group lists two or more names (spellings:), each letters, spaces, hyphens, apostrophes and dots, at most 60 characters.' ];
					continue;
				}
				$add[ (string) $bucket ][] = [ $key => array_map( 'trim', $names ) ] + array_diff_key( $g, [ $key => 1 ] );
			}
		}
		if ( ! $add ) {
			return $plan;
		}
		$now = get_option( ZDZ_Name_Match::OPTION, self::ABSENT );
		$cur = self::ABSENT === $now ? [] : ( is_string( $now ) ? json_decode( $now, true ) : $now );
		$cur = is_array( $cur ) ? $cur : [];
		$new = ZDZ_Name_Match::merge_maps( $cur, $add );
		if ( $new === $cur ) {
			return $plan;
		}
		$plan['writes'][]  = [ 'option' => ZDZ_Name_Match::OPTION, 'key' => null, 'value' => $new ];
		$plan['changes'][] = [ 'path' => $path, 'from' => sprintf( '%d groups', ZDZ_Name_Match::group_count( $cur ) ), 'to' => sprintf( '%d groups', ZDZ_Name_Match::group_count( $new ) ) ];
		return $plan;
	}

	/**
	 * catalog.yml vocabulary → the privacy coder.
	 *   plain_words  the business's own words that are never a name on their own, one word each
	 *                (zdz_private_plain_words)
	 *   model_words  product or model words a number after which is a model, not an access code
	 *                (zdz_sealed_model_words)
	 */
	public static function plan_vocabulary( array $data ) {
		$plan = self::plan();
		if ( isset( $data['vocabulary.plain_words'] ) ) {
			$p = 'vocabulary.plain_words';
			if ( ! class_exists( 'ZDZ_Private' ) || ! method_exists( 'ZDZ_Private', 'clean_list' ) ) {
				$plan['refused'][] = [ 'path' => $p, 'why' => 'The privacy coder is not loaded here.' ];
			} else {
				$ok = [];
				foreach ( self::as_list( $data[ $p ] ) as $w ) {
					$n = ZDZ_Private::clean_list( [ $w ] );
					if ( ! $n || false !== strpos( (string) $n[0], ' ' ) ) {
						$plan['refused'][] = [ 'path' => $p, 'why' => sprintf( '"%s": one word each.', is_scalar( $w ) ? (string) $w : gettype( $w ) ) ];
					} else {
						$ok[ (string) $n[0] ] = trim( (string) $w );
					}
				}
				if ( $ok ) {
					self::plan_option( $plan, $p, ZDZ_Private::PLAIN_WORDS_OPT, array_values( $ok ) );
					$plan['notes'][] = 'words in vocabulary.plain_words are never read as a person\'s name, so they are never hidden from the Ai.';
				}
			}
		}
		if ( isset( $data['vocabulary.model_words'] ) ) {
			$p = 'vocabulary.model_words';
			if ( ! class_exists( 'ZDZ_Sealed' ) || ! method_exists( 'ZDZ_Sealed', 'clean_words' ) ) {
				$plan['refused'][] = [ 'path' => $p, 'why' => 'The access-code reader is not loaded here.' ];
			} else {
				$ok = [];
				foreach ( self::as_list( $data[ $p ] ) as $w ) {
					$c = ZDZ_Sealed::clean_words( [ $w ] );
					if ( $c ) {
						$ok[ $c[0] ] = true;
					} else {
						$plan['refused'][] = [ 'path' => $p, 'why' => sprintf( '"%s": letters, digits, spaces, dots and hyphens, starting with a letter or digit, at most 41 characters.', is_scalar( $w ) ? (string) $w : gettype( $w ) ) ];
					}
				}
				if ( $ok ) {
					self::plan_option( $plan, $p, ZDZ_Sealed::MODEL_WORDS_OPT, array_map( 'strval', array_keys( $ok ) ) );
				}
			}
		}
		return $plan;
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
		'notices.usage_records'            => 'notices.usage_records',
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
				$val = self::drop_comment( trim( substr( $text, 1 ) ) );
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
				$val = self::drop_comment( trim( $m[2] ) );
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

	/** A value that is only a comment ("key:   # note") is no value: the key opens a block below it. */
	private static function drop_comment( $val ) {
		return str_starts_with( $val, '#' ) ? '' : $val;
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

ZDZ_Identity_Pack::boot();
