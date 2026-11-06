<?php
/**
 * The rename map's app-id values: a person's grant that names an older app id must land on an id an app actually
 * registers, or the app's tile silently disappears. Checked for the platform's own rows and for every row an app
 * declares on `zdz_rename_map`.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-rename-migration.php';

final class KWireRenameAppIdsTest extends TestCase {

	/** Every app id the bundled apps register, and their id constants. */
	private static function registered(): array {
		$apps  = dirname( __DIR__, 2 ) . '/zorderz-apps/apps';
		$ids   = array();
		$const = array();
		$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $apps, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( 'php' !== $f->getExtension() || false !== strpos( $f->getPathname(), '/tests/' ) ) {
				continue;
			}
			$src = (string) file_get_contents( $f->getPathname() );
			if ( preg_match_all( "/define\(\s*'([A-Z]+_APP_ID)',\s*'([a-z0-9-]+)'\s*\)/", $src, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $x ) {
					$const[ $x[1] ] = $x[2];
					$ids[ $x[2] ]   = true;
				}
			}
			foreach ( array( "/const APP_ID\s*=\s*'([a-z0-9-]+)'/", "/\\\$apps\[\s*'([a-z0-9-]+)'\s*\]/", "/'id'\s*=>\s*'(zdz-[a-z-]+)'/" ) as $re ) {
				if ( preg_match_all( $re, $src, $m ) ) {
					foreach ( $m[1] as $id ) {
						$ids[ $id ] = true;
					}
				}
			}
		}
		return array( $ids, $const );
	}

	public function test_the_platforms_rows_name_real_app_ids(): void {
		list( $ids ) = self::registered();
		$map         = ZDZ_Rename_Migration::map();
		$this->assertSame( 'jobs', $map['app_ids']['ts-jobs'], 'a grant for the older jobs id lands on the Jobs app' );
		foreach ( $map['app_ids'] as $old => $new ) {
			$this->assertArrayHasKey( $new, $ids, "$old maps to $new, an id an app registers" );
		}
		$this->assertContains( 'zdz_allowed_apps', $map['app_id_meta'] );
	}

	public function test_every_row_an_app_declares_names_a_real_app_id(): void {
		list( $ids, $const ) = self::registered();
		$seen                = 0;
		foreach ( (array) glob( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/*/app.php' ) as $file ) {
			$src = (string) file_get_contents( $file );
			$at  = 0;
			while ( false !== ( $at = strpos( $src, "\$map['app_ids']", $at ) ) ) {
				$block = substr( $src, $at, (int) strpos( $src, ');', $at ) - $at );
				$at   += 10;
				if ( ! preg_match_all( "/'([a-z0-9-]+)'\s*=>\s*([A-Z]+_APP_ID|'[a-z0-9-]+')/", $block, $m, PREG_SET_ORDER ) ) {
					continue;
				}
				foreach ( $m as $x ) {
					$new = "'" === $x[2][0] ? trim( $x[2], "'" ) : ( $const[ $x[2] ] ?? '?' );
					$this->assertArrayHasKey( $new, $ids, basename( dirname( $file ) ) . ": {$x[1]} maps to {$new}, an id an app registers" );
					$seen++;
				}
			}
		}
		$this->assertGreaterThan( 10, $seen, 'the apps\' rows were read' );
	}
}
