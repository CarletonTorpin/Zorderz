<?php
/**
 * Zorderz Theme — Ai ledger tables
 *
 * Creates {prefix}zdz_llm_ledger (one row per Ai call, numbers and code words only) and {prefix}zdz_llm_holds (the
 * spend a call books before it is sent). Versioned by ZDZ_LLM_Ledger::DB_VERSION in the option zdz_llm_ledger_db, so
 * it runs once per schema version; the router also makes the tables on first use if this has not run yet.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function zdz_theme_migrate_llm_ledger() {
	if ( ! class_exists( 'ZDZ_LLM_Ledger' ) ) {
		return;
	}
	if ( ZDZ_LLM_Ledger::DB_VERSION === (string) get_option( ZDZ_LLM_Ledger::DB_OPTION, '' ) ) {
		return;
	}
	if ( ! ZDZ_LLM_Ledger::maybe_install() ) {
		error_log( 'Zorderz: the Ai ledger tables could not be created; Ai calls are refused until they are.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}

add_action( 'admin_init', 'zdz_theme_migrate_llm_ledger' );
add_action( 'after_switch_theme', 'zdz_theme_migrate_llm_ledger' );
