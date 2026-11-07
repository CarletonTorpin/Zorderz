<?php
/**
 * Zorderz Prep — v2.3.0 schema migration.
 *
 * Idempotent + safe to re-run. Delegates to ZPREP_Leftovers::migrate() (dbDelta + guarded
 * ALTERs) on the `wp_zdz_prep_leftovers` table. NO business data is seeded.
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
