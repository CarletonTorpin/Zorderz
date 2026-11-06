<?php
/**
 * ZSM_Matcher — match installed third-party plugins, themes and WordPress core against the
 * cached vulnerability feed.
 *
 *   - Version RANGES, never equality: a component is affected when its version lies inside an
 *     advisory's range, with the range's inclusivity flags. The feed writes "*" for "no bound",
 *     so a bug with no fix yet ("*" to "*") affects every version.
 *   - Slug aliases: when a plugin's folder name differs from the feed's slug, an alias row
 *     (set on the console) maps one to the other.
 *   - Severity from the advisory's CVSS rating, else its score. A bug that needs a logged-in
 *     account is downgraded one level while public registration is closed. The feed's
 *     "informational" notices (for example, a plugin closed on the directory) report as info.
 *   - Your own code is not matched: no public feed covers it. The currency checks and the
 *     daily code check cover it instead.
 *
 * @package Zorderz\Security_Monitor
 */

defined( 'ABSPATH' ) || exit;

class ZSM_Matcher {

	/** local_slug => feed_slug overrides. */
	public static function alias_map(): array {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT local_slug, feed_slug FROM ' . ZSM_DB::aliases_table(), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed identifier, no input
		$map  = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$map[ strtolower( (string) $r['local_slug'] ) ] = strtolower( (string) $r['feed_slug'] );
			}
		}
		return $map;
	}

	/**
	 * Vulnerability findings for the whole inventory, read from the cached feed.
	 *
	 * @return array<int,array> finding rows
	 */
	public static function match( array $inventory ): array {
		global $wpdb;
		$aliases  = self::alias_map();
		$reg_open = ZSM::registration_open();
		$out      = array();

		foreach ( $inventory as $c ) {
			if ( ! empty( $c['first_party'] ) || '' === (string) ( $c['version'] ?? '' ) ) {
				continue;
			}
			$feed = self::feed_slug( $c, $aliases );
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM ' . ZSM_DB::vulns_table() . ' WHERE slug = %s AND comp_type = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed identifier
					$feed,
					(string) $c['comp_type']
				),
				ARRAY_A
			);
			if ( ! empty( $rows ) ) {
				$out = array_merge( $out, self::match_component( $c, $rows, $reg_open ) );
			}
		}
		return $out;
	}

	/** The slug the feed knows a component by. Core is "wordpress"; an alias wins over the folder name. */
	public static function feed_slug( array $c, array $aliases ): string {
		if ( 'core' === ( $c['comp_type'] ?? '' ) ) {
			return 'wordpress';
		}
		$local = strtolower( (string) ( $c['slug'] ?? '' ) );
		return $aliases[ $local ] ?? $local;
	}

	/**
	 * Findings for one component from its cached feed rows. Pure. One finding per advisory per
	 * component, even when the advisory lists several ranges that contain the version.
	 *
	 * @param array $c        an inventory row (slug, name, version, comp_type)
	 * @param array $rows     cached feed rows for this component
	 * @param bool  $reg_open is public registration open?
	 * @return array<int,array>
	 */
	public static function match_component( array $c, array $rows, bool $reg_open ): array {
		$installed = (string) ( $c['version'] ?? '' );
		if ( '' === $installed || ! empty( $c['first_party'] ) ) {
			return array();
		}
		$local    = strtolower( (string) ( $c['slug'] ?? '' ) );
		$type     = (string) ( $c['comp_type'] ?? '' );
		$seen_adv = array();
		$out      = array();
		foreach ( $rows as $r ) {
			if ( ! self::in_range( $installed, (string) ( $r['from_version'] ?? '' ), (int) ( $r['from_inclusive'] ?? 1 ), (string) ( $r['to_version'] ?? '' ), (int) ( $r['to_inclusive'] ?? 0 ) ) ) {
				continue;
			}
			$adv = (string) ( $r['advisory_id'] ?? '' );
			if ( isset( $seen_adv[ $adv ] ) ) {
				continue;
			}
			$seen_adv[ $adv ] = true;

			$score = ( ! isset( $r['cvss_score'] ) || null === $r['cvss_score'] || '' === $r['cvss_score'] ) ? null : (float) $r['cvss_score'];
			$sev   = self::severity( (string) ( $r['cvss_rating'] ?? '' ), $score );
			$note  = '';
			if ( ! empty( $r['informational'] ) ) {
				$sev  = 'info';
				$note = __( 'Informational notice from the feed (for example, the plugin was closed), not an exploitable bug.', 'zorderz' );
			} elseif ( 1 === (int) ( $r['auth_required'] ?? 0 ) && ! $reg_open ) {
				$sev  = self::downgrade( $sev );
				$note = __( 'The bug needs a logged-in account and public registration is closed, so its real-world risk here is downgraded one level.', 'zorderz' );
			}

			$cve   = (string) ( $r['cve'] ?? '' );
			$out[] = array(
				'finding_key'       => sha1( 'vuln|' . $local . '|' . $installed . '|' . $cve . '|' . $adv ),
				'kind'              => 'vuln',
				'plane'             => ( 'core' === $type ) ? 'core' : 'third_party',
				'comp_type'         => $type,
				'slug'              => $local,
				'name'              => (string) ( $c['name'] ?? $local ),
				'installed_version' => $installed,
				'fixed_version'     => self::best_fix( $installed, (string) ( $r['patched_version'] ?? '' ) ),
				'cve'               => $cve,
				'title'             => (string) ( $r['title'] ?? '' ),
				'severity'          => $sev,
				'cvss_score'        => $score,
				'context_note'      => $note,
				'ref_url'           => (string) ( $r['ref_url'] ?? '' ),
			);
		}
		return $out;
	}

	/** Is $v inside (from, to), with the inclusivity flags? An empty or "*" bound is unbounded. */
	public static function in_range( string $v, string $from, int $from_inc, string $to, int $to_inc ): bool {
		if ( '' === $v ) {
			return false; // no version, no claim (fail closed)
		}
		$from = trim( $from );
		$to   = trim( $to );
		if ( '*' === $from ) {
			$from = '';
		}
		if ( '*' === $to ) {
			$to = '';
		}
		if ( '' !== $from ) {
			$c = version_compare( $v, $from );
			if ( $from_inc ? ( $c < 0 ) : ( $c <= 0 ) ) {
				return false;
			}
		}
		if ( '' !== $to ) {
			$c = version_compare( $v, $to );
			if ( $to_inc ? ( $c > 0 ) : ( $c >= 0 ) ) {
				return false;
			}
		}
		return true; // both bounds open = every version affected
	}

	/**
	 * Of an advisory's patched versions (it can span branches: "3.7.40,6.0.3,6.1.1"), the lowest
	 * one above the installed version — the fix for THIS install. Never an older version.
	 */
	public static function best_fix( string $installed, string $patched_csv ): string {
		$best = '';
		foreach ( explode( ',', $patched_csv ) as $p ) {
			$p = trim( $p );
			if ( '' === $p || version_compare( $p, $installed, '<=' ) ) {
				continue;
			}
			if ( '' === $best || version_compare( $p, $best, '<' ) ) {
				$best = $p;
			}
		}
		return $best;
	}

	/** Severity from the advisory's rating, else its CVSS score, else info. */
	public static function severity( string $rating, ?float $score ): string {
		$rating = strtolower( trim( $rating ) );
		if ( in_array( $rating, array( 'critical', 'high', 'medium', 'low' ), true ) ) {
			return $rating;
		}
		if ( null !== $score ) {
			if ( $score >= 9.0 ) {
				return 'critical';
			}
			if ( $score >= 7.0 ) {
				return 'high';
			}
			if ( $score >= 4.0 ) {
				return 'medium';
			}
			if ( $score > 0.0 ) {
				return 'low';
			}
		}
		return 'info';
	}

	/** One level down; info stays info. */
	public static function downgrade( string $sev ): string {
		$order = ZSM_Policy::SEVERITIES;
		$i     = array_search( $sev, $order, true );
		if ( false === $i || $i >= count( $order ) - 1 ) {
			return 'info';
		}
		return $order[ $i + 1 ];
	}

	/** Numeric rank for sorting (higher = worse). */
	public static function rank( string $sev ): int {
		$map = array( 'critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1, 'info' => 0 );
		return $map[ $sev ] ?? 0;
	}
}
