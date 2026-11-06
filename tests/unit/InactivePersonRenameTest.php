<?php
/**
 * A former employee's "inactive" flag is carried across by the rename migration, so they stay out of
 * every people picker after an upgrade.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'ZDZ_Rename_Migration' ) ) {
	require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-rename-migration.php';
}

final class InactivePersonRenameTest extends TestCase {

	public function test_map_carries_the_inactive_flag(): void {
		$meta = ZDZ_Rename_Migration::map()['user_meta'];
		$this->assertArrayHasKey( 'ts_inactive', $meta );
		$this->assertSame( 'zdz_inactive', $meta['ts_inactive'] );
	}

	public function test_migration_version_is_unchanged(): void {
		$this->assertSame( 2, ZDZ_Rename_Migration::VERSION );
	}
}
