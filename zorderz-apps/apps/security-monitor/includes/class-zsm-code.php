<?php
/**
 * ZSM_Code — import a code-review report about your own code. No public feed covers it, and
 * the daily code check is narrow on purpose, so a deeper review (Plugin Check or Semgrep, run
 * elsewhere or from WP-CLI, never from a web request) can be pasted on the console or imported
 * with `wp zsm import-code <file.json>`. Its results become findings of kind 'code'.
 *
 * @package Zorderz\Security_Monitor
 */

defined( 'ABSPATH' ) || exit;

class ZSM_Code {

	/** @return int imported count, or -1 when the report cannot be read */
	public static function import_json( string $json ): int {
		$rows = self::parse( $json );
		if ( null === $rows ) {
			return -1;
		}
		ZSM_Findings::reconcile( $rows, array( 'code' ) );
		return count( $rows );
	}

	public static function import_file( string $path ): int {
		if ( '' === $path || ! is_readable( $path ) ) {
			return -1;
		}
		return self::import_json( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Finding rows from a report, or null when it is not JSON. Pure.
	 *
	 * @return array<int,array>|null
	 */
	public static function parse( string $json ): ?array {
		$data = json_decode( trim( $json ), true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$rows = self::from_semgrep( $data );
		return $rows ? $rows : self::from_generic( $data );
	}

	/** Semgrep: { results: [ { check_id, path, start:{line}, extra:{message,severity} } ] } */
	private static function from_semgrep( array $data ): array {
		if ( empty( $data['results'] ) || ! is_array( $data['results'] ) ) {
			return array();
		}
		$rows = array();
		foreach ( $data['results'] as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$rule   = (string) ( $r['check_id'] ?? 'semgrep' );
			$path   = (string) ( $r['path'] ?? '' );
			$line   = (int) ( $r['start']['line'] ?? 0 );
			$msg    = (string) ( $r['extra']['message'] ?? $rule );
			$sev    = self::map_severity( (string) ( $r['extra']['severity'] ?? '' ) );
			$rows[] = self::row( $rule, $path, $line, $msg, $sev );
		}
		return $rows;
	}

	/** Any other report: every object that carries a message and a file (or path), at any depth. */
	private static function from_generic( array $data ): array {
		$rows  = array();
		$stack = array( $data );
		while ( $stack ) {
			$node = array_pop( $stack );
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( isset( $node['message'] ) && ( isset( $node['file'] ) || isset( $node['path'] ) ) ) {
				$path   = (string) ( $node['file'] ?? $node['path'] ?? '' );
				$line   = (int) ( $node['line'] ?? $node['start_line'] ?? 0 );
				$rule   = (string) ( $node['code'] ?? $node['rule'] ?? $node['type'] ?? 'code' );
				$sev    = self::map_severity( (string) ( $node['type'] ?? $node['severity'] ?? '' ) );
				$rows[] = self::row( $rule, $path, $line, (string) $node['message'], $sev );
			}
			foreach ( $node as $v ) {
				if ( is_array( $v ) ) {
					$stack[] = $v;
				}
			}
		}
		return $rows;
	}

	private static function row( string $rule, string $path, int $line, string $msg, string $sev ): array {
		$slug = self::slug_from_path( $path );
		return array(
			'finding_key'       => sha1( 'code|' . $rule . '|' . $path . '|' . $line ),
			'kind'              => 'code',
			'plane'             => 'first_party',
			'comp_type'         => ( false !== strpos( $path, '/themes/' ) || 0 === strpos( $path, 'themes/' ) ) ? 'theme' : 'plugin',
			'slug'              => $slug,
			'name'              => $slug,
			'installed_version' => '',
			'fixed_version'     => '',
			'cve'               => '',
			'title'             => trim( $msg ) . ' — ' . $path . ( $line ? ':' . $line : '' ) . ' [' . $rule . ']',
			'severity'          => $sev,
			'cvss_score'        => null,
			'context_note'      => __( 'Code finding from an imported review report, not from any public feed.', 'zorderz' ),
			'ref_url'           => '',
		);
	}

	/** The component a reported path belongs to: the folder under plugins/, themes/ or mu-plugins/. */
	public static function slug_from_path( string $path ): string {
		$path = str_replace( '\\', '/', $path );
		if ( preg_match( '#(?:^|/)(?:plugins|themes|mu-plugins)/([^/]+)/#', $path, $m ) ) {
			return $m[1];
		}
		return 'first-party';
	}

	private static function map_severity( string $s ): string {
		$s   = strtolower( trim( $s ) );
		$map = array(
			'error'    => 'high',
			'critical' => 'critical',
			'high'     => 'high',
			'warning'  => 'medium',
			'medium'   => 'medium',
			'info'     => 'low',
			'low'      => 'low',
			'note'     => 'low',
		);
		return $map[ $s ] ?? 'low';
	}
}
