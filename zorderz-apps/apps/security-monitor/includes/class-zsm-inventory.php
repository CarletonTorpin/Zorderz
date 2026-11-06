<?php
/**
 * ZSM_Inventory — every installed component, from WordPress's own APIs.
 *
 * No fingerprinting and no outside call: on its own server get_plugins() beats a scan. "Latest
 * version" comes from the update transients WordPress core already refreshes (about twice a
 * day), so the inventory itself never contacts anyone.
 *
 * "First party" means code this site owns and no public feed covers: the Zorderz theme and
 * apps bundle, plus whatever the business lists on the console as its own (exact folder names,
 * or prefixes ending in "-" or "*"). The monitor checks first-party code for version drift,
 * for a public plugin sharing its name, and with the daily code check — never against the feed.
 *
 * @package Zorderz\Security_Monitor
 */

defined( 'ABSPATH' ) || exit;

class ZSM_Inventory {

	/** The platform's own components: always first party. */
	const CORE_FIRST_PARTY = array( 'zorderz', 'zorderz-apps' );
	/** Prefixes of the platform's own components (a child theme, a companion plugin). */
	const CORE_FIRST_PARTY_PREFIXES = array( 'zorderz-', 'zdz-' );

	/** @return array<int,array> normalized component rows */
	public static function collect(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$rows = array();

		// Plugins and must-use plugins.
		$plugin_updates = self::plugin_update_map();
		$plugin_known   = self::plugin_known_map();
		foreach ( get_plugins() as $file => $data ) {
			$rows[] = self::plugin_row( (string) $file, (array) $data, $plugin_updates, false, isset( $plugin_known[ $file ] ) );
		}
		if ( function_exists( 'get_mu_plugins' ) ) {
			foreach ( get_mu_plugins() as $file => $data ) {
				$rows[] = self::plugin_row( (string) $file, (array) $data, $plugin_updates, true, false );
			}
		}

		// Themes.
		$theme_updates = self::theme_update_map();
		$theme_known   = self::theme_known_map();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$slug    = (string) $stylesheet;
			$version = (string) $theme->get( 'Version' );
			$latest  = $theme_updates[ $slug ] ?? '';
			$rows[]  = array(
				'kind'             => 'theme',
				'comp_type'        => 'theme',
				'slug'             => $slug,
				'name'             => (string) $theme->get( 'Name' ),
				'version'          => $version,
				'latest'           => $latest,
				'update_available' => ( '' !== $latest && version_compare( $version, $latest, '<' ) ),
				'first_party'      => self::is_first_party( $slug ),
				'mu'               => false,
				'update_uri'       => (string) $theme->get( 'UpdateURI' ),
				'wporg_known'      => isset( $theme_updates[ $slug ] ) || isset( $theme_known[ $slug ] ),
			);
		}

		// WordPress core.
		$core_latest = self::core_latest();
		$core_ver    = (string) get_bloginfo( 'version' );
		$rows[]      = array(
			'kind'             => 'core',
			'comp_type'        => 'core',
			'slug'             => 'wordpress',
			'name'             => 'WordPress core',
			'version'          => $core_ver,
			'latest'           => $core_latest,
			'update_available' => ( '' !== $core_latest && version_compare( $core_ver, $core_latest, '<' ) ),
			'first_party'      => false,
			'mu'               => false,
			'update_uri'       => '',
			'wporg_known'      => true,
		);

		return $rows;
	}

	private static function plugin_row( string $file, array $data, array $updates, bool $mu, bool $known ): array {
		$slug    = self::plugin_slug( $file );
		$version = isset( $data['Version'] ) ? (string) $data['Version'] : '';
		$latest  = $updates[ $file ] ?? '';
		return array(
			'kind'             => 'plugin',
			'comp_type'        => 'plugin',
			'slug'             => $slug,
			'name'             => isset( $data['Name'] ) ? (string) $data['Name'] : $slug,
			'version'          => $version,
			'latest'           => $latest,
			'update_available' => ( '' !== $latest && '' !== $version && version_compare( $version, $latest, '<' ) ),
			'first_party'      => self::is_first_party( $slug ),
			'mu'               => $mu,
			// Supply-chain inputs: the plugin's own `Update URI` header, and whether WordPress.org
			// answered for this plugin file at all (an update offered, or listed as current),
			// which means a public plugin shares its folder name.
			'update_uri'       => isset( $data['UpdateURI'] ) ? (string) $data['UpdateURI'] : '',
			'wporg_known'      => $known || '' !== $latest,
			'file'             => $file,
		);
	}

	/** Folder slug from a plugin file path ("example-plugin/example-plugin.php" -> "example-plugin"). */
	public static function plugin_slug( string $file ): string {
		$file = ltrim( $file, '/' );
		if ( false !== strpos( $file, '/' ) ) {
			return substr( $file, 0, strpos( $file, '/' ) );
		}
		return (string) preg_replace( '/\.php$/', '', $file );
	}

	/**
	 * The business's own components, as configured on the console: a list of exact folder names
	 * and prefixes (an entry ending in "-" or "*" is a prefix). Empty by default.
	 *
	 * @return array{exact:string[],prefixes:string[]}
	 */
	public static function configured_first_party(): array {
		$raw = function_exists( 'get_option' ) ? get_option( ZSM::OPT . 'first_party', array() ) : array();
		return self::parse_first_party( is_array( $raw ) ? $raw : explode( ',', (string) $raw ) );
	}

	/**
	 * Split entries into exact names and prefixes. Only folder-name characters are kept.
	 *
	 * @param string[] $entries
	 * @return array{exact:string[],prefixes:string[]}
	 */
	public static function parse_first_party( array $entries ): array {
		$out = array( 'exact' => array(), 'prefixes' => array() );
		foreach ( $entries as $e ) {
			$e = strtolower( trim( (string) $e ) );
			if ( '' === $e ) {
				continue;
			}
			$prefix = '*' === substr( $e, -1 );
			$e      = rtrim( $e, '*' );
			$e      = (string) preg_replace( '/[^a-z0-9._-]/', '', $e );
			if ( '' === $e ) {
				continue;
			}
			if ( $prefix || '-' === substr( $e, -1 ) || '_' === substr( $e, -1 ) ) {
				$out['prefixes'][] = $e;
			} else {
				$out['exact'][] = $e;
			}
		}
		$out['exact']    = array_values( array_unique( $out['exact'] ) );
		$out['prefixes'] = array_values( array_unique( $out['prefixes'] ) );
		return $out;
	}

	/**
	 * Is this folder slug code the site owns? The platform's own components always are; the
	 * business adds its own on the console; code can decide with the `zsm_first_party` filter.
	 *
	 * @param array|null $configured parsed configuration (defaults to the stored one)
	 */
	public static function is_first_party( string $slug, ?array $configured = null ): bool {
		$s    = strtolower( $slug );
		$conf = null === $configured ? self::configured_first_party() : $configured;
		$own  = in_array( $s, self::CORE_FIRST_PARTY, true ) || in_array( $s, (array) ( $conf['exact'] ?? array() ), true );
		if ( ! $own ) {
			foreach ( array_merge( self::CORE_FIRST_PARTY_PREFIXES, (array) ( $conf['prefixes'] ?? array() ) ) as $p ) {
				if ( '' !== $p && 0 === strpos( $s, $p ) ) {
					$own = true;
					break;
				}
			}
		}
		return (bool) apply_filters( 'zsm_first_party', $own, $slug );
	}

	/** plugin-file => new_version, from core's update transient (no outside call). */
	private static function plugin_update_map(): array {
		$out = array();
		$t   = get_site_transient( 'update_plugins' );
		if ( isset( $t->response ) && is_array( $t->response ) ) {
			foreach ( $t->response as $file => $info ) {
				if ( isset( $info->new_version ) ) {
					$out[ $file ] = (string) $info->new_version;
				}
			}
		}
		return $out;
	}

	/** Plugin files WordPress.org reported as current (the update check's no_update list). */
	private static function plugin_known_map(): array {
		$out = array();
		$t   = get_site_transient( 'update_plugins' );
		if ( isset( $t->no_update ) && is_array( $t->no_update ) ) {
			foreach ( $t->no_update as $file => $info ) {
				$out[ $file ] = true;
			}
		}
		return $out;
	}

	/** Themes WordPress.org reported as current (the update check's no_update list). */
	private static function theme_known_map(): array {
		$out = array();
		$t   = get_site_transient( 'update_themes' );
		if ( isset( $t->no_update ) && is_array( $t->no_update ) ) {
			foreach ( $t->no_update as $slug => $info ) {
				$out[ (string) $slug ] = true;
			}
		}
		return $out;
	}

	private static function theme_update_map(): array {
		$out = array();
		$t   = get_site_transient( 'update_themes' );
		if ( isset( $t->response ) && is_array( $t->response ) ) {
			foreach ( $t->response as $slug => $info ) {
				$nv = is_array( $info ) && isset( $info['new_version'] ) ? $info['new_version'] : ( $info->new_version ?? '' );
				if ( '' !== $nv ) {
					$out[ $slug ] = (string) $nv;
				}
			}
		}
		return $out;
	}

	private static function core_latest(): string {
		if ( ! function_exists( 'get_preferred_from_update_core' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		$u = function_exists( 'get_preferred_from_update_core' ) ? get_preferred_from_update_core() : false;
		if ( is_object( $u ) && isset( $u->current ) && ( ! isset( $u->response ) || 'upgrade' === $u->response ) ) {
			return (string) $u->current;
		}
		return '';
	}
}
