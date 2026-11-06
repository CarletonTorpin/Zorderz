<?php
/**
 * ZSM_Currency — the checks that need no feed at all.
 *
 *   outdated  a third-party component or WordPress core has an update available (read from the
 *             update transients WordPress already keeps; no outside call)
 *   drift     one of your own components is OLDER than the version this site last ran (a rollback,
 *             or a half-finished automatic update); a newer one simply moves the baseline forward
 *   supply    your own code can be reached through the public update channel: WordPress.org offers
 *             an update for, or knows, a public plugin or theme with the same folder name
 *
 * @package Zorderz\Security_Monitor
 */

defined( 'ABSPATH' ) || exit;

class ZSM_Currency {

	const DEPLOY_LOG = 'zsm_deploy_log';       // newest first, at most 40 entries
	const ABSENT     = 'zsm_baseline_absent';  // components in the baseline but gone from the site (logged once)
	const BASELINE   = 'zsm_expected_heads';   // slug => expected version

	/** slug => expected version. */
	public static function baseline(): array {
		$b = get_option( self::BASELINE, array() );
		return is_array( $b ) ? $b : array();
	}

	/** Start afresh: today's versions of your own components become the expected ones. */
	public static function set_baseline( array $inventory ): int {
		$map = array();
		foreach ( $inventory as $c ) {
			if ( ! empty( $c['first_party'] ) && '' !== (string) $c['version'] ) {
				$map[ $c['slug'] ] = (string) $c['version'];
			}
		}
		update_option( self::BASELINE, $map, false );
		delete_option( self::ABSENT );
		$log = get_option( self::DEPLOY_LOG, array() );
		$log = array_slice( array_merge( array( array( 'at' => time(), 'slug' => '', 'name' => '', 'from' => '', 'to' => (string) count( $map ), 'what' => 'baseline' ) ), is_array( $log ) ? $log : array() ), 0, 40 );
		update_option( self::DEPLOY_LOG, $log, false );
		return count( $map );
	}

	/**
	 * Compare two versions as people write them: "1.0.0-hotfix1" is AFTER "1.0.0", and
	 * "2.0.0-beta" BEFORE "2.0.0" (PHP's version_compare puts any suffix first). A bare letter
	 * suffix reads as a pre-release.
	 */
	public static function compare( string $a, string $b ): int {
		$split = function ( string $v ): array {
			return preg_match( '/^v?(\d+(?:\.\d+)*)(.*)$/i', trim( $v ), $m ) ? array( $m[1], ltrim( $m[2], '.-_+' ) ) : array( '0', $v );
		};
		list( $na, $sa ) = $split( $a );
		list( $nb, $sb ) = $split( $b );
		$c = version_compare( $na, $nb );
		if ( 0 !== $c || $sa === $sb ) {
			return $c;
		}
		$pre  = '/^(dev|alpha|a|beta|b|rc|pre|preview)(\b|\d|$)/i';
		$rank = function ( string $s ) use ( $pre ): int {
			return '' === $s ? 1 : ( preg_match( $pre, $s ) ? 0 : 2 ); // pre-release < release < hotfix/patch
		};
		$ra = $rank( $sa );
		$rb = $rank( $sb );
		if ( $ra !== $rb ) {
			return $ra < $rb ? -1 : 1;
		}
		return ( version_compare( $sa, $sb ) <=> 0 ) ?: ( strnatcasecmp( $sa, $sb ) <=> 0 );
	}

	/**
	 * The baseline follows deploys. For each of your own components:
	 *   - not in the baseline yet (a new plugin, or the very first scan) -> added;
	 *   - a NEWER version than the baseline -> the baseline moves to it ("deploy noticed");
	 *   - an OLDER version -> left alone, so evaluate() reports it as rolled back;
	 *   - gone from the site -> KEPT and logged once as "removed"; when it comes back it is compared
	 *     with the version it had, so a rollback through a reinstall is still reported.
	 * Every change goes into the deploy log the console shows, so an update nobody planned is
	 * visible even though it raises no finding.
	 *
	 * @return array<int,array> the log entries added by this call
	 */
	public static function follow_deploys( array $inventory ): array {
		$base       = self::baseline();
		$first      = empty( $base );
		$now        = time();
		$added      = array();
		$seen       = array();
		$absent     = get_option( self::ABSENT, array() );
		$absent     = is_array( $absent ) ? $absent : array();
		$was_absent = $absent;
		foreach ( $inventory as $c ) {
			if ( empty( $c['first_party'] ) || '' === (string) $c['version'] ) {
				continue;
			}
			$slug          = (string) $c['slug'];
			$ver           = (string) $c['version'];
			$seen[ $slug ] = true;
			if ( isset( $absent[ $slug ] ) ) {
				unset( $absent[ $slug ] );
				$added[] = array( 'at' => $now, 'slug' => $slug, 'name' => (string) $c['name'], 'from' => (string) ( $base[ $slug ] ?? '' ), 'to' => $ver, 'what' => 'returned' );
			}
			if ( ! isset( $base[ $slug ] ) ) {
				$base[ $slug ] = $ver;
				if ( ! $first ) {
					$added[] = array( 'at' => $now, 'slug' => $slug, 'name' => (string) $c['name'], 'from' => '', 'to' => $ver, 'what' => 'new' );
				}
			} elseif ( self::compare( $ver, (string) $base[ $slug ] ) > 0 ) {
				$added[]       = array( 'at' => $now, 'slug' => $slug, 'name' => (string) $c['name'], 'from' => (string) $base[ $slug ], 'to' => $ver, 'what' => 'deploy' );
				$base[ $slug ] = $ver;
			}
		}
		foreach ( array_keys( $base ) as $slug ) {
			if ( empty( $seen[ $slug ] ) && ! isset( $absent[ $slug ] ) ) {
				$added[]         = array( 'at' => $now, 'slug' => (string) $slug, 'name' => (string) $slug, 'from' => (string) $base[ $slug ], 'to' => '', 'what' => 'removed' );
				$absent[ $slug ] = (string) $base[ $slug ];
			}
		}
		if ( $absent !== $was_absent ) {
			update_option( self::ABSENT, $absent, false );
		}
		if ( $first ) {
			$added[] = array( 'at' => $now, 'slug' => '', 'name' => '', 'from' => '', 'to' => (string) count( $base ), 'what' => 'baseline' );
		}
		if ( $first || $added ) {
			ksort( $base );
			update_option( self::BASELINE, $base, false );
			$log = get_option( self::DEPLOY_LOG, array() );
			$log = array_slice( array_merge( array_reverse( $added ), is_array( $log ) ? $log : array() ), 0, 40 );
			update_option( self::DEPLOY_LOG, $log, false );
		}
		return $added;
	}

	/** @return array<int,array{at:int,slug:string,name:string,from:string,to:string,what:string}> newest first */
	public static function deploy_log(): array {
		$l = get_option( self::DEPLOY_LOG, array() );
		return is_array( $l ) ? $l : array();
	}

	/**
	 * Out-of-date, drift and supply-chain findings for the inventory.
	 *
	 * @param array|null $baseline slug => expected version (defaults to the stored one)
	 * @return array<int,array> finding rows
	 */
	public static function evaluate( array $inventory, ?array $baseline = null ): array {
		$out      = array();
		$baseline = null === $baseline ? self::baseline() : $baseline;

		foreach ( $inventory as $c ) {
			$slug = (string) $c['slug'];
			$name = (string) $c['name'];
			$ver  = (string) $c['version'];
			$type = (string) $c['comp_type'];

			// Third-party and core: out of date.
			if ( empty( $c['first_party'] ) && ! empty( $c['update_available'] ) ) {
				$latest = (string) $c['latest'];
				$out[]  = self::row( array(
					'kind'              => 'outdated',
					'plane'             => ( 'core' === $type ) ? 'core' : 'third_party',
					'comp_type'         => $type,
					'slug'              => $slug,
					'name'              => $name,
					'installed_version' => $ver,
					'fixed_version'     => $latest,
					'severity'          => ( 'core' === $type ) ? 'medium' : 'low',
					/* translators: 1: component name, 2: installed version, 3: available version */
					'title'             => sprintf( __( 'Update available: %1$s %2$s → %3$s', 'zorderz' ), $name, $ver, $latest ),
					'context_note'      => __( 'Out of date. An outdated component is the most common way in, even before a vulnerability is published.', 'zorderz' ),
				) );
			}

			// Your own code: drift from the version this site last ran.
			if ( ! empty( $c['first_party'] ) && isset( $baseline[ $slug ] ) && '' !== $ver ) {
				$expected = (string) $baseline[ $slug ];
				if ( $expected !== $ver ) {
					$older = self::compare( $ver, $expected ) < 0;
					$out[] = self::row( array(
						'kind'              => 'drift',
						'plane'             => 'first_party',
						'comp_type'         => $type,
						'slug'              => $slug,
						'name'              => $name,
						'installed_version' => $ver,
						'fixed_version'     => $expected,
						'severity'          => $older ? 'medium' : 'info',
						'title'             => $older
							/* translators: 1: component name, 2: installed version, 3: expected version */
							? sprintf( __( 'Version drift: %1$s was rolled back (installed %2$s, expected %3$s)', 'zorderz' ), $name, $ver, $expected )
							/* translators: 1: component name, 2: installed version, 3: expected version */
							: sprintf( __( 'Version drift: %1$s is ahead of the baseline (installed %2$s, expected %3$s); set the baseline again, or let it follow deploys', 'zorderz' ), $name, $ver, $expected ),
						'context_note'      => __( 'Your own components are not covered by any public feed; drift from the deployed version is the signal here.', 'zorderz' ),
					) );
				}
			}
		}

		return array_merge( $out, self::supply_chain( $inventory ) );
	}

	/**
	 * Your own code reaching the public update channel.
	 *
	 *  - HIGH: WordPress.org offers an UPDATE for one of your own plugins or themes. A public plugin
	 *    shares its folder name, and an update (by hand, or by a host's automatic updater) would
	 *    replace your code with someone else's.
	 *  - MEDIUM: WordPress.org knows the name but offers no update today; the same risk later.
	 *  - INFO (one row): how many of your own components do not declare `Update URI: false`, the
	 *    header that takes them out of that channel entirely.
	 */
	public static function supply_chain( array $inventory ): array {
		$out     = array();
		$missing = array();
		$total   = 0;
		foreach ( $inventory as $c ) {
			if ( empty( $c['first_party'] ) || ! empty( $c['mu'] ) || 'core' === $c['comp_type'] ) {
				continue; // must-use plugins are never updated through WordPress.org
			}
			$total++;
			$uri      = strtolower( trim( (string) ( $c['update_uri'] ?? '' ) ) );
			$host     = (string) parse_url( $uri, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
			$wporg    = in_array( $host, array( 'wordpress.org', 'w.org' ), true ) || '.wordpress.org' === substr( $host, -14 ) || '.w.org' === substr( $host, -6 );
			$declared = ( 'false' === $uri ) || ( '' !== $uri && ! $wporg );
			if ( ! $declared ) {
				$missing[] = (string) $c['slug'];
			}
			if ( $declared ) {
				continue; // WordPress ignores WordPress.org for it, whatever the update check said
			}
			if ( '' !== (string) ( $c['latest'] ?? '' ) ) {
				$out[] = self::row( array(
					'kind'              => 'supply',
					'plane'             => 'first_party',
					'comp_type'         => (string) $c['comp_type'],
					'slug'              => (string) $c['slug'],
					'name'              => (string) $c['name'],
					'installed_version' => (string) $c['version'],
					'fixed_version'     => (string) $c['latest'],
					'severity'          => 'high',
					/* translators: 1: offered version, 2: component name */
					'title'             => sprintf( __( 'WordPress.org offers an update (%1$s) for your own %2$s: a public plugin or theme shares its name', 'zorderz' ), (string) $c['latest'], (string) $c['name'] ),
					'context_note'      => __( 'Do not update it: the update would replace your code with someone else\'s. Add "Update URI: false" to its header.', 'zorderz' ),
				) );
			} elseif ( ! empty( $c['wporg_known'] ) ) {
				$out[] = self::row( array(
					'kind'              => 'supply',
					'plane'             => 'first_party',
					'comp_type'         => (string) $c['comp_type'],
					'slug'              => (string) $c['slug'],
					'name'              => (string) $c['name'],
					'installed_version' => (string) $c['version'],
					'severity'          => 'medium',
					/* translators: %s: component name */
					'title'             => sprintf( __( 'WordPress.org knows a public plugin or theme named like your own %s', 'zorderz' ), (string) $c['name'] ),
					'context_note'      => __( 'No update is offered today, but one could be later. Add "Update URI: false" to its header.', 'zorderz' ),
				) );
			}
		}
		if ( $missing ) {
			sort( $missing );
			$out[] = self::row( array(
				'kind'              => 'supply',
				'plane'             => 'first_party',
				'comp_type'         => 'plugin',
				'slug'              => 'first-party',
				'name'              => __( 'Your own plugins and themes', 'zorderz' ),
				'installed_version' => count( $missing ) . ' of ' . $total,
				'key_extra'         => implode( ',', $missing ), // a new or fixed component makes it a new finding
				'severity'          => 'info',
				/* translators: 1: count missing, 2: total, 3: list of folder names */
				'title'             => sprintf( __( '%1$d of %2$d of your own components do not declare "Update URI: false": %3$s', 'zorderz' ), count( $missing ), $total, implode( ', ', $missing ) ),
				'context_note'      => __( 'Without it, WordPress asks WordPress.org about them. Harmless while no public plugin shares a name (the check above watches for that).', 'zorderz' ),
			) );
		}
		return $out;
	}

	/** Normalize a finding row and compute its de-duplication key. */
	public static function row( array $r ): array {
		$r += array(
			'cve'               => '',
			'cvss_score'        => null,
			'ref_url'           => '',
			'fixed_version'     => '',
			'installed_version' => '',
			'context_note'      => '',
			'name'              => '',
			'key_extra'         => '',
		);
		$r['finding_key'] = sha1( $r['kind'] . '|' . $r['slug'] . '|' . $r['installed_version'] . '|' . $r['fixed_version'] . '|' . $r['cve'] . ( '' !== $r['key_extra'] ? '|' . $r['key_extra'] : '' ) );
		unset( $r['key_extra'] );
		return $r;
	}
}
