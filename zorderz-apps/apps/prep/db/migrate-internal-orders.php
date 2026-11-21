<?php
/**
 * Zorderz Prep — schema migration: internal orders.
 *
 * Idempotent + safe to re-run. Delegates to ZPREP_Remakes::migrate() (dbDelta) on the
 * `wp_zprep_remakes` table, which gains what an internal order keeps: kind, purpose_code,
 * for_whom, ref_code, sheet_w, sheet_l, sheets_used, labor_minutes, labor_by, labor_at,
 * cost_rate, and the index idx_kind. Every existing row reads kind = 'remake'. NO business
 * data is seeded.
 *
 * @package Zorderz\Prep
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ZPREP_Remakes' ) ) {
	require_once dirname( __DIR__ ) . '/includes/class-zprep-remakes.php';
}

ZPREP_Remakes::migrate();
