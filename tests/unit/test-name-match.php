<?php
/**
 * ZDZ_Name_Match — shared soundalike matcher harness.
 *
 * Standalone: no WordPress, no network. Run: php tests/unit/test-name-match.php
 *
 * Because ZDZ_Name_Match is pure and WP-independent, this REQUIRES the shipped class file
 * directly (no method-lifting) — the logic exercised is the logic that deploys. It also
 * exercises the shared public API (name_score, soundalike_variants, parse_rule) and the
 * `zdz_name_homophones_map` extension filter.
 *
 * Uses ONLY general-English names present in the neutral shipped seed — never a tenant record.
 */

error_reporting( E_ALL & ~E_DEPRECATED );
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );

/* Stub apply_filters BEFORE the class loads its map, so the extension-filter test is real
 * (load_homophone_map memoises on first call). The stub appends one surname group. */
$GLOBALS['__zdz_filter_hit'] = false;
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		if ( $hook === 'zdz_name_homophones_map' && is_array( $value ) ) {
			$GLOBALS['__zdz_filter_hit'] = true;
			$value['surnames'][] = array(
				'sound' => 'zztest', 'spellings' => array( 'Zephyr', 'Zefir', 'Zephir' ),
				'type' => 'homophone',
			);
		}
		return $value;
	}
}

require __DIR__ . '/../../zorderz/inc/class-zdz-name-match.php';

$pass = 0; $fail = 0; $checks = [];
function ok( $label, $cond, $detail = '' ) {
	global $pass, $fail, $checks;
	if ( $cond ) { $pass++; $checks[] = "  \xE2\x9C\x93 {$label}"; }
	else         { $fail++; $checks[] = "  \xE2\x9C\x97 {$label}" . ( $detail ? " — {$detail}" : '' ); }
}
function nc($a,$b){ return ZDZ_Name_Match::name_close($a,$b); }
function fe($a,$b){ return ZDZ_Name_Match::first_names_equivalent($a,$b); }
function key_($a){ return ZDZ_Name_Match::name_sound_key($a); }
function hv($a){ return ZDZ_Name_Match::soundalike_variants($a); }
function sl($a,$b){ return ZDZ_Name_Match::sounds_like($a,$b); }
function hp($cf,$cl,$sf,$sl){ return ZDZ_Name_Match::homophone_points($cf,$cl,$sf,$sl); }
function mp($cf,$cl,$sf,$sl){ return ZDZ_Name_Match::name_match_points($cf,$cl,$sf,$sl); }
function sc($cf,$cl,$sf,$sl){ return ZDZ_Name_Match::name_score($cf,$cl,$sf,$sl); }
function has($n,$arr){ return in_array( strtolower($n), array_map('strtolower',$arr), true ); }

/* ── class present + version ── */
ok( 'ZDZ_Name_Match class loaded', class_exists('ZDZ_Name_Match') );
ok( 'VERSION present', is_string(ZDZ_Name_Match::VERSION) && ZDZ_Name_Match::VERSION !== '' );

/* ── asset loads via __DIR__, and the zdz_name_homophones_map FILTER ran ── */
$map = ZDZ_Name_Match::load_homophone_map();
ok( 'asset loads (map non-empty)', is_array($map) && ! empty($map) );
ok( 'asset has 24 surname groups (+1 filter-injected = 25)', isset($map['surnames']) && count($map['surnames']) === 25 );
ok( 'asset has 28 given-name groups', isset($map['given_names']) && count($map['given_names']) === 28 );
ok( 'extension filter zdz_name_homophones_map ran', $GLOBALS['__zdz_filter_hit'] === true );
ok( 'filter-injected group is queryable (Zephyr→Zefir)', has('zefir', hv('zephyr')) );
ok( 'distinct_but_confusable present (Identity extension point)', isset($map['distinct_but_confusable']) );
ok( 'not_soundalike_flags present (Identity extension point)', isset($map['not_soundalike_flags']) );
ok( 'seed ships EMPTY tenant guard-lists (no baked customer/business)', $map['distinct_but_confusable'] === array() && $map['not_soundalike_flags'] === array() );

/* ── Layer 1: metaphone key ── */
ok( 'key: Reed==Read', key_('Reed') !== '' && key_('Reed') === key_('Read') );
ok( 'key: Jon==John', key_('Jon') === key_('John') );
ok( 'key SPLITS Sean vs Shawn (curated must repair)', key_('Sean') !== '' && key_('Sean') !== key_('Shawn') );
ok( 'key: empty -> ""', key_('') === '' );

/* ── Layer 2: curated groups ── */
ok( 'variants(sean) has shawn', has('shawn', hv('sean')) );
ok( 'variants(jon) has john', has('john', hv('jon')) );
ok( 'variants symmetric (john→jon)', has('jon', hv('john')) );
ok( 'variants never returns the query itself', ! has('jon', hv('jon')) );
ok( 'variants(unknown) === []', hv('zzznotaname') === array() );

/* ── sounds_like ── */
ok( 'sounds_like sean~shawn (curated-only; metaphone splits)', sl('sean','shawn') === true );
ok( 'sounds_like reed~read', sl('reed','read') === true );
ok( 'sounds_like jon~john', sl('jon','john') === true );
ok( 'sounds_like unrelated miller~johnson -> false', sl('miller','johnson') === false );

/* ── nickname/typo (carried-in layer) ── */
ok( 'fe mike~michael (nickname)', fe('mike','michael') === true );
ok( 'nc millar~miller (typo)', nc('millar','miller') === true );
ok( 'nc sean~shawn -> false (the GAP; curated repairs it)', nc('sean','shawn') === false );

/* ── scoring: name_match_points + homophone_points, and the combined name_score ── */
ok( 'first-name soundalike Sean↔Shawn: hp +10, mp 5', hp('Shawn','Smith','sean','smith') === 10 && mp('Shawn','Smith','sean','smith') === 5 );
ok( 'name_score(Shawn,Smith,sean,smith) === 15', sc('Shawn','Smith','sean','smith') === 15 );
ok( 'exact Mike Miller: name_score 15, hp 0', sc('Mike','Miller','mike','miller') === 15 && hp('Mike','Miller','mike','miller') === 0 );
ok( 'surname near-match Reece↔Reese: name_score 4 (below the 5 suggestion bar alone)', sc('','Reece','','reese') === 4 );
ok( 'PRECISION unrelated Anderson/Miller: name_score 0', sc('','Anderson','','miller') === 0 );

/* ── the shared parse rule ── */
$rule = ZDZ_Name_Match::parse_rule();
ok( 'parse_rule() non-empty string', is_string($rule) && strlen($rule) > 80 );
ok( 'parse_rule() names the sound-not-spelling principle', stripos($rule,'sound') !== false && stripos($rule,'spelling') !== false );
ok( 'parse_rule() carries the [AMBIGUOUS] escape hatch', strpos($rule,'[AMBIGUOUS') !== false );
ok( 'parse_rule() forbids merging soundalikes', stripos($rule,'never merge') !== false );

/* neutrality guard: the shipped seed is NEUTRAL. Structural checks only - the
 * tenant-name string scan lives in the OFF-REPO PII gate (scripts/pii-gate.sh + a
 * gitignored wordlist), never hardcoded here, so this committed test names no real
 * customer, owner or business. */
$seed = json_decode( file_get_contents( __DIR__ . '/../../zorderz/inc/name-homophones.json' ), true );
$neutral =
	   is_array( $seed )
	&& ( $seed['distinct_but_confusable'] ?? null ) === array()
	&& ( $seed['not_soundalike_flags'] ?? null ) === array()
	&& stripos( (string) json_encode( $seed ), 'seen_in_data' ) === false;
ok( 'shipped seed is neutral - empty tenant guard-lists, no seen_in_data provenance', $neutral );

/* summary */
echo implode("\n", $checks), "\n";
if ( $fail === 0 ) { echo "\nAll ZDZ_Name_Match shared-matcher tests passed. ({$pass} assertions)\n"; exit(0); }
echo "\n{$pass} passed, {$fail} failed, " . ($pass+$fail) . " total\n";
exit(1);
