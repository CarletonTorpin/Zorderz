<?php
/**
 * ZJOB_Tile_Grant — the tiles this app gives a person by default.
 *
 * Everyone who may use apps (`zdz_access_app`, or an administrator) is given the Jobs tile and the Projects hub,
 * on activation and again at each sign-in, so people added or promoted later get them too. A tile an administrator
 * took away from a person (`zdz_denied_apps`) is never given back. The Projects hub is never given to the shared
 * device. Granting only adds to the person's `zdz_allowed_apps`; it never removes anything.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZJOB_Tile_Grant {

	const JOBS_APP_ID     = 'jobs';
	const PROJECTS_APP_ID = 'projects';

	/**
	 * The app ids given by default.
	 *
	 * @param bool $shared_device the person is the shared device.
	 * @return string[]
	 */
	public static function default_ids( bool $shared_device ): array {
		return $shared_device ? array( self::JOBS_APP_ID ) : array( self::JOBS_APP_ID, self::PROJECTS_APP_ID );
	}

	/**
	 * The allowed-apps list once $ids are granted, leaving out any the person was denied; null when nothing
	 * changes. PURE.
	 *
	 * @param mixed    $allowed the stored `zdz_allowed_apps`.
	 * @param mixed    $denied  the stored `zdz_denied_apps`.
	 * @param string[] $ids
	 * @return string[]|null
	 */
	public static function merge( $allowed, $denied, array $ids ): ?array {
		$allowed = is_array( $allowed ) ? $allowed : array();
		$denied  = is_array( $denied ) ? $denied : array();
		$changed = false;
		foreach ( $ids as $id ) {
			if ( in_array( $id, $denied, true ) || in_array( $id, $allowed, true ) ) {
				continue;
			}
			$allowed[] = $id;
			$changed   = true;
		}
		return $changed ? $allowed : null;
	}

	/** Give one person the default tiles (idempotent). */
	public static function grant( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		if ( ! user_can( $user_id, 'manage_options' ) && ! user_can( $user_id, 'zdz_access_app' ) ) {
			return;
		}
		$shared = class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $user_id );
		$next   = self::merge(
			get_user_meta( $user_id, 'zdz_allowed_apps', true ),
			get_user_meta( $user_id, 'zdz_denied_apps', true ),
			self::default_ids( $shared )
		);
		if ( null !== $next ) {
			update_user_meta( $user_id, 'zdz_allowed_apps', $next );
		}
	}
}
