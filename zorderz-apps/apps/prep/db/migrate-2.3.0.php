<?php
/**
 * Zorderz Prep — v2.3.0 schema migration.
 *
 * Idempotent + safe to re-run. Delegates to ZPREP_Leftovers::migrate() (dbDelta + guarded
 * ALTERs). The legacy `wp_tsemc_leftovers` table is renamed to `wp_zdz_prep_leftovers` by
 * the platform ZDZ_Rename_Migration (declared in app.php's zdz_rename_map) BEFORE this
 * runs, so an upgraded prior-build install and a fresh Zorderz install both converge on
 * the neutral table. NO business data is seeded.
 *
 * schema_migrations: zdz_prep_leftovers (table rename tsemc_leftovers -> zdz_prep_leftovers).
 *
 * @package Zorderz\Prep
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ZPREP_Leftovers' ) ) {
	require_once dirname( __DIR__ ) . '/includes/class-zprep-leftovers.php';
}

ZPREP_Leftovers::migrate();
