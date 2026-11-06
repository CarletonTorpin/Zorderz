<?php
/**
 * What an Identity Pack writes into the platform's own settings.
 *
 *   - Preview → apply → revert: a preview writes nothing; apply writes each setting through its own check;
 *     revert puts every option back exactly as it was, "not set" included.
 *   - Invalid values are refused with a reason, never guessed or rounded into range.
 *   - Floors are never widened: a pack may choose a narrower value, never a wider one.
 *   - A pack that carries a credential value is refused whole; secret:// references are only checked.
 *   - Keys nothing reads are reported; settings a pack may not set say where they are set instead.
 *   - The registry keeps one entry per id: a filter cannot take a built-in id.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/zdz-pb-no-content' );
}
if ( ! function_exists( 'get_template_directory' ) ) {
	function get_template_directory() { return sys_get_temp_dir() . '/zdz-pb-no-theme'; }
}
if ( ! function_exists( 'wp_roles' ) ) {
	function wp_roles() { return (object) array( 'role_names' => array() ); }
}
if ( ! function_exists( 'get_role' ) ) {
	function get_role( $slug ) { return $GLOBALS['zdz_test_roles'][ $slug ] ?? null; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['zdz_test_options'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $k ) { unset( $GLOBALS['zdz_test_options'][ $k ] ); return true; }
}

require_once __DIR__ . '/notify-media-stubs.php';
require_once __DIR__ . '/support/privacy-stubs.php';
require_once __DIR__ . '/llm-test-doubles.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-quiet-hours.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-alert-router.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-contacts.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-geocoder.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-consent.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-admin-dashboard.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-sealed.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-ask-context.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-private.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-business-profile.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-identity-pack.php';

final class WrittenByAPackTest extends TestCase {

	private $root;

	protected function setUp(): void {
		$GLOBALS['zdz_test_options'] = array();
		$this->root                  = sys_get_temp_dir() . '/zdz-pack-settings-' . getmypid() . '-' . mt_rand();
		@mkdir( $this->root . '/example-co', 0700, true );
		$root                        = $this->root;
		$GLOBALS['zdz_test_filters'] = array(
			'zdz_identity_pack_paths'     => function () use ( $root ) { return array( $root ); },
			'zdz_identity_pack_consumers' => function ( $c ) { return $c; },
		);
		ZDZ_Private::flush_lists();
		ZDZ_Sealed::flush_lists();
		ZDZ_Business_Profile::flush();
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->root . '/example-co/*' ) as $f ) {
			@unlink( $f );
		}
		@rmdir( $this->root . '/example-co' );
		@rmdir( $this->root );
		ZDZ_Private::flush_lists();
		ZDZ_Sealed::flush_lists();
		unset( $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_filters'] );
		unset( $GLOBALS['zdz_test_user_meta'] );
	}

	private function file( $stem, $yaml ) {
		file_put_contents( $this->root . '/example-co/' . $stem . '.yml', $yaml );
	}

	private static function refused_paths( array $p ) {
		return array_map( function ( $r ) { return $r['file'] . ':' . $r['path']; }, $p['refused'] );
	}

	private static function opt( $k ) {
		return $GLOBALS['zdz_test_options'][ $k ] ?? 'ABSENT';
	}

	/* ── rules.yml: preview, apply, revert ───────────────────────────────────────────────────── */

	public function test_rules_round_trip_preview_writes_nothing_and_revert_restores_exactly(): void {
		$GLOBALS['zdz_test_options']['zdz_quiet_hours'] = array( 'start' => '22:00', 'end' => '06:00' );
		$before                                          = $GLOBALS['zdz_test_options'];
		$this->file( 'rules', "pack:\n  schema: 1\nnotifications:\n  quiet_hours: { start: \"21:30\", end: \"06:30\" }\ncontacts:\n  reaching_results: [talked, replied, voicemail]\nusage_records:\n  raw_days: 120\nmedia:\n  same_property_m: 150\nai_spend:\n  monthly_usd: { app: 40, all: 60, ceiling: 100 }\nsecurity_monitor:\n  public_registration: open\n" );

		$p = ZDZ_Identity_Pack::preview( 'example-co' );
		$this->assertSame( array(), $p['errors'] );
		$this->assertSame( array(), $p['refused'] );
		$paths = array_column( $p['changes'], 'path' );
		foreach ( array( 'notifications.quiet_hours', 'contacts.reaching_results', 'usage_records.raw_days', 'media.same_property_m', 'ai_spend.monthly_usd', 'security_monitor.public_registration' ) as $want ) {
			$this->assertContains( $want, $paths );
		}
		$this->assertSame( $before, $GLOBALS['zdz_test_options'], 'a preview writes nothing' );

		$r = ZDZ_Identity_Pack::apply( 'example-co', true );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( array( 'start' => '21:30', 'end' => '06:30' ), get_option( 'zdz_quiet_hours' ) );
		$this->assertSame( array( 'start' => '21:30', 'end' => '06:30' ), ZDZ_Quiet_Hours::business_default() );
		$this->assertSame( array( 'talked', 'voicemail', 'replied' ), ZDZ_Identity_Pack::filtered( 'contacts.reaching_results', ZDZ_Contacts::REACHING_RESULTS ) );
		$this->assertSame( 120, ZDZ_Identity_Pack::filtered( 'usage_records.raw_days', 180 ) );
		$this->assertSame( 150.0, ZDZ_Identity_Pack::filtered( 'media.same_property_m', 120.0 ) );
		$this->assertSame( array( 'app' => 40.0, 'all' => 60.0, 'ceiling' => 100.0 ), get_option( ZDZ_Core_LLM::BUDGET_OPTION ) );
		$this->assertSame( 'yes', get_option( 'zsm_registration_open' ) );

		$v = ZDZ_Identity_Pack::revert();
		$this->assertTrue( $v['ok'] );
		foreach ( array( 'zdz_quiet_hours', ZDZ_Identity_Pack::SETTINGS_OPTION, ZDZ_Core_LLM::BUDGET_OPTION, 'zsm_registration_open' ) as $k ) {
			$this->assertSame( $before[ $k ] ?? 'ABSENT', self::opt( $k ), "$k is exactly as it was" );
		}
		$this->assertSame( 'ABSENT', self::opt( ZDZ_Identity_Pack::SNAPSHOT_OPTION ), 'a snapshot is used once' );
		$this->assertSame( array( 'talked', 'replied' ), ZDZ_Identity_Pack::filtered( 'contacts.reaching_results', ZDZ_Contacts::REACHING_RESULTS ), 'the filter hands back what it was given again' );
		$this->assertFalse( ZDZ_Identity_Pack::revert()['ok'], 'nothing left to revert' );
	}

	/* ── refused, never guessed ──────────────────────────────────────────────────────────────── */

	public function test_invalid_values_are_refused_with_a_reason_and_not_written(): void {
		$this->file( 'rules', "notifications:\n  quiet_hours: { start: \"25:00\", end: \"07:00\", mood: calm }\ncontacts:\n  reaching_results: [waved]\nusage_records:\n  raw_days: ninety\nmedia:\n  same_property_m: 5000\nai_spend:\n  monthly_usd: { all: 10 }\nsecurity_monitor:\n  public_registration: sometimes\n" );
		$p       = ZDZ_Identity_Pack::preview( 'example-co' );
		$refused = self::refused_paths( $p );
		foreach ( array( 'rules:notifications.quiet_hours', 'rules:notifications.quiet_hours.mood', 'rules:contacts.reaching_results', 'rules:usage_records.raw_days', 'rules:media.same_property_m', 'rules:ai_spend.monthly_usd.app', 'rules:security_monitor.public_registration' ) as $want ) {
			$this->assertContains( $want, $refused );
		}
		foreach ( $p['refused'] as $r ) {
			$this->assertNotSame( '', $r['why'], 'every refusal says why' );
		}
		$this->assertSame( array(), $p['changes'] );

		ZDZ_Identity_Pack::apply( 'example-co', true );
		foreach ( array( 'zdz_quiet_hours', ZDZ_Identity_Pack::SETTINGS_OPTION, ZDZ_Core_LLM::BUDGET_OPTION, 'zsm_registration_open' ) as $k ) {
			$this->assertSame( 'ABSENT', self::opt( $k ), "$k was not written" );
		}
	}

	/* ── floors ──────────────────────────────────────────────────────────────────────────────── */

	public function test_floors_are_never_widened(): void {
		// Usage records: never under the readers' floor.
		$this->assertNotEmpty( ZDZ_Identity_Pack::plan_raw_days( array( 'usage_records.raw_days' => ZDZ_Admin_Dashboard::RAW_DAYS_MIN - 1 ) )['refused'] );
		$this->assertSame( array(), ZDZ_Identity_Pack::plan_raw_days( array( 'usage_records.raw_days' => ZDZ_Admin_Dashboard::RAW_DAYS_MIN ) )['refused'] );
		// Registration: "closed" would lower how serious login-only flaws read; only a person on the console says so.
		$closed = ZDZ_Identity_Pack::plan_registration( array( 'security_monitor.public_registration' => 'closed' ) );
		$this->assertNotEmpty( $closed['refused'] );
		$this->assertSame( array(), $closed['writes'] );
		$this->assertNotEmpty( ZDZ_Identity_Pack::plan_registration( array( 'security_monitor.public_registration' => false ) )['refused'], 'a YAML "no" is "closed" too' );
		// The shared device never gets a notice grant; unknown channels are refused.
		$g = ZDZ_Identity_Pack::plan_alert_grants( array( 'alerts.role_category_grants' => array( 'zdz_general' => array( 'jobs' => array( 'push' ) ), 'zdz_tech' => array( 'jobs' => array( 'in_app', 'pigeon' ) ) ) ) );
		$this->assertContains( 'alerts.role_category_grants.zdz_general', array_column( $g['refused'], 'path' ) );
		$this->assertContains( 'alerts.role_category_grants.zdz_tech.jobs', array_column( $g['refused'], 'path' ) );
		$this->assertSame( array( 'zdz_tech' => array( 'jobs' => array( 'in_app' ) ) ), $g['writes'][0]['value'] );
		// A same-property radius outside the geocoder's range.
		$this->assertNotEmpty( ZDZ_Identity_Pack::plan_same_property( array( 'media.same_property_m' => 5 ) )['refused'] );
		// Ai: a region never makes a call cheaper; a call's cap never above the router's; an app of this site is never "outside".
		$GLOBALS['zdz_test_options']['zdz_model_slot_llm_primary'] = 'example-model-large';
		$ai = ZDZ_Identity_Pack::plan_ai_connection( array(
			'ai.anthropic'        => array( 'geo_price_factor' => 0.5 ),
			'ai.roles'            => array( 'chat' => array( 'cap_usd' => ZDZ_Core_LLM::MAX_CAP_USD + 1, 'provider' => 'poe' ) ),
			'ai.external_callers' => array( 'leads', 'example-engine' ),
		) );
		$why = array_column( $ai['refused'], 'path' );
		$this->assertContains( 'ai.anthropic.geo_price_factor', $why );
		$this->assertContains( 'ai.roles.chat.cap_usd', $why );
		$this->assertContains( 'ai.roles.chat.provider', $why, 'which provider gets the data is a person\'s choice' );
		$this->assertContains( 'ai.external_callers', $why );
		$ext = array_values( array_filter( $ai['writes'], function ( $w ) { return 'ai.external_callers' === $w['key']; } ) );
		$this->assertSame( array( 'example-engine' ), $ext[0]['value'], 'only the outside caller is kept' );
		// Privacy: a postcode shape must be whole (^…$); a one-word town is never a place that hides a surname.
		$this->assertNotEmpty( ZDZ_Identity_Pack::plan_jurisdiction( array( 'jurisdiction.postcode' => array( 'digits' => '\d' ) ) )['refused'] );
		$this->assertSame( array(), ZDZ_Identity_Pack::plan_jurisdiction( array( 'jurisdiction.postcode' => array( 'digits' => '^\d{5}$', 'region' => 'ST' ) ) )['refused'] );
		$pl = ZDZ_Identity_Pack::plan_places( array( 'place_names.multi_word' => array( 'Springfield', 'North Example' ) ) );
		$this->assertCount( 1, $pl['refused'] );
		$this->assertSame( array( 'North Example' ), $pl['writes'][0]['value'] );
	}

	/* ── connections.yml ─────────────────────────────────────────────────────────────────────── */

	public function test_ai_connection_round_trip_keeps_other_prices_and_checks_references_without_reading_them(): void {
		$GLOBALS['zdz_test_options'][ ZDZ_Core_LLM::PRICES_OPTION ]  = array( 'old-model' => array( 1.0, 2.0, 3.0, 4.0, 5.0 ) );
		$GLOBALS['zdz_test_options']['zdz_core_anthropic_api_key']   = 'opaque-stored-value';
		$before                                                      = $GLOBALS['zdz_test_options'];
		$this->file( 'connections', "ai:\n  models:\n    primary: example-model-large\n    quick: example-model-small\n  prices:\n    example-model-large: [3, 3.75, 6, 0.3, 15]\n    example-model-small: [1, 1.25, 2, 0.1, 5]\n    unnamed-model: [1, 1, 1, 1, 1]\n  anthropic:\n    inference_geo: eu\n    geo_price_factor: 1.1\n    api_key: \"secret://anthropic_api_key\"\n  roles:\n    chat: { model: example-model-small, effort: high, cap_usd: 2 }\n  external_callers: [example-engine]\nvulnerability_feed:\n  key: \"secret://zdz_core_wordfence_feed_key\"\n" );

		$p = ZDZ_Identity_Pack::preview( 'example-co' );
		$this->assertSame( array(), $p['errors'] );
		$this->assertFalse( $p['blocked'] );
		$refused = self::refused_paths( $p );
		$this->assertContains( 'connections:ai.prices.unnamed-model', $refused, 'prices only for a model a slot names' );
		$this->assertSame( array( 'connections:ai.prices.unnamed-model' ), array_values( array_filter( $refused, function ( $r ) { return 0 === strpos( $r, 'connections:ai.' ); } ) ) );
		$notes = implode( "\n", $p['notes'] );
		$this->assertStringContainsString( 'zdz_core_anthropic_api_key is filled', $notes );
		// The feed key's row is listed only while its app is active: either it is checked, or it is named as missing.
		$this->assertTrue( false !== strpos( $notes, 'zdz_core_wordfence_feed_key is empty' ) || in_array( 'connections:vulnerability_feed.key', $refused, true ) );
		$this->assertStringNotContainsString( 'opaque-stored-value', $notes . json_encode( $p ), 'a reference is checked, never read out' );

		$this->assertTrue( ZDZ_Identity_Pack::apply( 'example-co', true )['ok'] );
		$this->assertSame( 'example-model-large', get_option( 'zdz_model_slot_llm_primary' ) );
		$this->assertSame( 'example-model-small', get_option( 'zdz_model_slot_llm_quick' ) );
		$prices = get_option( ZDZ_Core_LLM::PRICES_OPTION );
		$this->assertSame( array( 'old-model', 'example-model-large', 'example-model-small' ), array_keys( $prices ), 'other models\' prices are kept' );
		$this->assertSame( array( 'inference_geo' => 'eu', 'geo_price_factor' => 1.1 ), get_option( ZDZ_Core_LLM::CONN_OPTION ) );
		$this->assertSame( array( 'model' => 'example-model-small', 'effort' => 'high', 'cap_usd' => 2.0 ), get_option( ZDZ_Core_LLM::ROLES_OPTION )['chat'] );
		$this->assertSame( array( 'example-engine' ), ZDZ_Identity_Pack::filtered( 'ai.external_callers', array() ) );
		$this->assertSame( 'opaque-stored-value', get_option( 'zdz_core_anthropic_api_key' ), 'a reference never writes the credentials row' );

		ZDZ_Identity_Pack::revert();
		$this->assertSame( $before, array_diff_key( $GLOBALS['zdz_test_options'], array( ZDZ_Identity_Pack::LOG_OPTION => 1 ) ), 'every option back exactly, "not set" included' );
	}

	/* ── secrets ─────────────────────────────────────────────────────────────────────────────── */

	public function test_a_pack_carrying_a_credential_value_is_refused_whole(): void {
		$this->file( 'rules', "notifications:\n  quiet_hours: { start: \"21:00\", end: \"07:00\" }\n" );
		$this->file( 'connections', "ai:\n  anthropic:\n    api_key: \"example-not-a-real-key\"\n" );
		$p = ZDZ_Identity_Pack::preview( 'example-co' );
		$this->assertTrue( $p['blocked'] );
		$this->assertStringContainsString( 'connections: ai.anthropic.api_key', implode( ' ', $p['errors'] ) );
		$this->assertStringNotContainsString( 'example-not-a-real-key', json_encode( $p ), 'the value itself is never shown' );
		$r = ZDZ_Identity_Pack::apply( 'example-co', true );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( array(), $GLOBALS['zdz_test_options'], 'nothing at all was written, not even the quiet hours' );

		$this->assertSame( array( 'rules: integrations.webhook_token' ), ZDZ_Identity_Pack::find_secrets( array( 'rules' => array( 'integrations' => array( 'webhook_token' => 'abc' ) ) ) ), 'any file' );
		$this->assertSame( array( 'connections: crm.key' ), ZDZ_Identity_Pack::find_secrets( array( 'connections' => array( 'crm' => array( 'key' => 'secret://ns key' ) ) ) ), 'a malformed reference is a value' );
		$this->assertSame( array(), ZDZ_Identity_Pack::find_secrets( array(
			'connections' => array( 'pack' => array( 'tenant_key' => 'example-co' ), 'crm' => array( 'api_key' => 'secret://ns_api_key', 'client_id' => 'example' ) ),
			'org'         => array( 'departments' => array( array( 'key' => 'sales' ) ) ),
		) ), 'references, ids and ordinary keys pass' );
		$this->assertSame( 'zdz_core_ns_api_key', ZDZ_Identity_Pack::secret_row( 'secret://ns_api_key' ) );
		$this->assertSame( 'zdz_core_ns_api_key', ZDZ_Identity_Pack::secret_row( 'secret://zdz_core_ns_api_key' ) );
		$this->assertNull( ZDZ_Identity_Pack::secret_row( 'secret://../x' ) );
	}

	/* ── privacy lists, profile and org ──────────────────────────────────────────────────────── */

	public function test_privacy_lists_work_domains_and_grants_round_trip(): void {
		$this->file( 'territories', "place_names:\n  multi_word: [\"North Example\", \"Example Bay\"]\n  ask_visible: [\"Springfield\"]\njurisdiction:\n  code_words: [\"example code\"]\n  postcode: { digits: '^\\d{5}$', region: ST }\n" );
		$this->file( 'catalog', "vocabulary:\n  plain_words: [widget, \"two words\"]\n  model_words: [ex-100]\nitems:\n  - id: widget\n" );
		$this->file( 'profile', "email:\n  work_domains: [example.com, \"not a domain\", com]\n" );
		$this->file( 'org', "alerts:\n  role_category_grants:\n    zdz_tech:\n      jobs: [in_app, push]\nchannels:\n  - slug: field\n" );

		$p = ZDZ_Identity_Pack::preview( 'example-co' );
		$this->assertContains( 'catalog:vocabulary.plain_words', self::refused_paths( $p ) );
		$this->assertContains( 'profile:email.work_domains', self::refused_paths( $p ) );
		$this->assertContains( 'catalog: items', $p['unconsumed'], 'what nothing reads is reported' );
		$this->assertContains( 'org: channels', $p['unconsumed'] );
		$this->assertNotContains( 'profile: email.work_domains', $p['unconsumed'], 'a key another consumer reads is not "unused"' );

		ZDZ_Identity_Pack::apply( 'example-co', true );
		ZDZ_Private::flush_lists();
		$this->assertContains( 'north example', ZDZ_Private::places() );
		$this->assertArrayHasKey( 'widget', ZDZ_Private::common_words() );
		$this->assertSame( array( 'springfield' ), ZDZ_Ask_Context::place_names() );
		$this->assertSame( array( 'example code' ), get_option( ZDZ_Sealed::REGION_WORDS_OPT ) );
		$this->assertSame( array( 'digits' => '^\d{5}$', 'region' => 'ST' ), get_option( ZDZ_Sealed::POSTCODE_OPT ) );
		$this->assertSame( 'example.com', get_option( 'zsch_work_domains' ) );
		$this->assertSame( array( 'zdz_tech' => array( 'jobs' => array( 'in_app', 'push' ) ) ), ZDZ_Alert_Router::role_category_grants() );
		$this->assertArrayNotHasKey( 'email', (array) get_option( ZDZ_Business_Profile::OPTION ), 'the profile does not keep what another consumer read' );

		ZDZ_Identity_Pack::revert();
		$this->assertSame( array(), ZDZ_Private::places(), 'the coder reads its lists again after a revert' );
		foreach ( array( ZDZ_Private::PLACES_OPT, ZDZ_Private::PLAIN_WORDS_OPT, ZDZ_Ask_Context::PLACES_OPT, ZDZ_Sealed::REGION_WORDS_OPT, ZDZ_Sealed::MODEL_WORDS_OPT, ZDZ_Sealed::POSTCODE_OPT, 'zsch_work_domains', ZDZ_Alert_Router::OPT_ROLE_CATEGORY_GRANTS, ZDZ_Business_Profile::OPTION ) as $k ) {
			$this->assertSame( 'ABSENT', self::opt( $k ), "$k is gone again" );
		}
	}

	public function test_settings_a_pack_may_not_set_say_where_they_are_set(): void {
		$this->file( 'rules', "security_monitor:\n  first_party: [example-plugin]\nflows_of_work: yes\n" );
		$this->file( 'connections', "staging:\n  hosts: [staging.example.com]\n" );
		$p = ZDZ_Identity_Pack::preview( 'example-co' );
		$why = array();
		foreach ( $p['refused'] as $r ) {
			$why[ $r['file'] . ':' . $r['path'] ] = $r['why'];
		}
		$this->assertStringContainsString( 'Security Monitor', $why['rules:security_monitor.first_party'] );
		$this->assertStringContainsString( 'wp-config.php', $why['connections:staging'] );
		$this->assertContains( 'rules: flows_of_work', $p['unconsumed'] );
		ZDZ_Identity_Pack::apply( 'example-co', true );
		$this->assertSame( 'ABSENT', self::opt( 'zsm_first_party' ) );
	}

	/* ── the shipped example ───────────────────────────────────────────────────────────────── */

	public function test_the_shipped_example_pack_previews_cleanly_on_a_blank_site(): void {
		$GLOBALS['zdz_test_filters']['zdz_identity_pack_paths'] = function () { return array( dirname( __DIR__, 2 ) . '/zorderz/identity-packs' ); };
		$p = ZDZ_Identity_Pack::preview( 'example-business' );
		$this->assertSame( array(), $p['errors'] );
		$this->assertFalse( $p['blocked'], 'the example carries references, never values' );
		foreach ( $p['refused'] as $r ) {
			$this->assertNotSame( '', $r['why'] );
			// A blank site has no team and no catalog: only the people and items the example names are refused.
			$this->assertTrue( in_array( $r['file'], array( 'territories', 'parties' ), true ) || 'vulnerability_feed.key' === $r['path'], $r['file'] . ':' . $r['path'] . ' — ' . $r['why'] );
		}
		$paths = array_column( $p['changes'], 'path' );
		foreach ( array( 'notifications.quiet_hours', 'ai_spend.monthly_usd', 'ai.models.primary', 'ai.prices.example-model-large', 'vocabulary.plain_words', 'place_names.multi_word', 'jurisdiction.postcode', 'email.work_domains', 'alerts.role_category_grants' ) as $want ) {
			$this->assertContains( $want, $paths );
		}
		$this->assertSame( array(), $GLOBALS['zdz_test_options'], 'a preview writes nothing' );
	}

	/* ── the registry ────────────────────────────────────────────────────────────────────────── */

	public function test_the_registry_keeps_built_in_ids_and_checks_what_apps_register(): void {
		$noop = function () { return array(); };
		$GLOBALS['zdz_test_filters']['zdz_identity_pack_consumers'] = function ( $c ) use ( $noop ) {
			$c['quiet-hours'] = array( 'files' => array( 'rules' ), 'preview' => $noop, 'apply' => $noop, 'snapshot' => $noop, 'restore' => $noop );
			$c['example-app'] = array( 'files' => array( 'flows', 'not-a-file' ), 'paths' => array( 'flows' => array( 'stages' ), 'voice' => array( 'x' ) ), 'preview' => $noop, 'apply' => $noop, 'snapshot' => $noop, 'restore' => $noop );
			$c['half-done']   = array( 'files' => array( 'voice' ), 'preview' => $noop );
			return $c;
		};
		$all = ZDZ_Identity_Pack::consumers();
		$this->assertTrue( ! empty( $all['quiet-hours']['builtin'] ), 'a filter cannot take a built-in id' );
		$this->assertSame( array( 'flows' ), $all['example-app']['files'] );
		$this->assertSame( array( 'flows' => array( 'stages' ) ), $all['example-app']['paths'] );
		$this->assertArrayNotHasKey( 'half-done', $all, 'a consumer without every callback is left out' );
		$this->assertContains( 'flows', ZDZ_Identity_Pack::supported_files() );
	}

	public function test_a_comment_after_a_key_opens_its_block(): void {
		$this->assertSame(
			array( 'ai' => array( 'prices' => array( 'example-model' => array( 1, 2, 3, 4, 5 ) ) ), 'list' => array( 'a' ) ),
			ZDZ_Identity_Pack::parse_yaml( "ai:\n  prices:   # USD per million tokens\n    example-model: [1, 2, 3, 4, 5]\nlist:\n  - a   # first\n" )
		);
	}

	public function test_person_references_resolve_to_exactly_one_person(): void {
		$GLOBALS['zdz_test_user_meta'] = array( 31 => array( 'zdz_user_initials' => 'AB' ), 32 => array( 'zdz_user_initials' => 'cd' ), 33 => array( 'zdz_user_initials' => 'CD' ) );
		$this->assertSame( 31, ZDZ_Identity_Pack::person_id( 'ab', array( 31, 32, 33 ) )['id'] );
		$this->assertSame( 31, ZDZ_Identity_Pack::person_id( array( 'initials' => 'AB' ), array( 31 ) )['id'] );
		$this->assertSame( 0, ZDZ_Identity_Pack::person_id( 'CD', array( 31, 32, 33 ) )['id'], 'two people share these initials: no one' );
		$this->assertSame( 0, ZDZ_Identity_Pack::person_id( 'AB', array( 32, 33 ) )['id'], 'not among the people the app may name' );
		$this->assertSame( 0, ZDZ_Identity_Pack::person_id( 'Alex', array( 31 ) )['id'], 'a name is not initials' );
		unset( $GLOBALS['zdz_test_user_meta'] );
	}
}
