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
 * The curated map is private data that lives in the option `zdz_name_homophones`, so this suite
 * supplies a made-up map through a get_option() stand-in. Its groups keep the relations the
 * matcher has to handle: Casey/Kacie share a metaphone key; Quinn/Kwin and Sean/Shawn are split by
 * metaphone and joined only by the curated map; "Example Glass" and "Exemple Glass" are distinct
 * businesses that must co-surface but never merge. It also covers moving an older build's
 * name-homophones.json into the option and deleting the file.
 */

error_reporting( E_ALL & ~E_DEPRECATED );
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );

/* The made-up curated map, as the site option would hold it. */
$GLOBALS['__zdz_nm_opts']    = array();
$GLOBALS['__zdz_nm_fixture'] = array(
	'resource' => 'zdz-name-homophones', 'version' => 'test',
	'surnames' => array(
		array( 'sound' => 'casey', 'spellings' => array( 'Casey', 'Kacie' ), 'type' => 'homophone' ),
		array( 'sound' => 'quinn', 'spellings' => array( 'Quinn', 'Kwin' ),  'type' => 'homophone' ),
		array( 'sound' => 'devin', 'spellings' => array( 'Devin', 'Devon' ), 'type' => 'homophone' ),
	),
	'given_names' => array(
		array( 'sound' => 'sean', 'spellings' => array( 'Sean', 'Shawn', 'Shaun' ), 'type' => 'homophone' ),
	),
	'distinct_but_confusable' => array( array( 'names' => array( 'Example Glass', 'Exemple Glass' ), 'status' => 'distinct', 'type' => 'business' ) ),
	'not_soundalike_flags'    => array( array( 'names' => array( 'Robin', 'Morgan' ), 'status' => 'not a group' ) ),
);
$GLOBALS['__zdz_nm_opts']['zdz_name_homophones'] = $GLOBALS['__zdz_nm_fixture'];
if ( function_exists( 'get_option' ) ) { fwrite( STDERR, "Run this from the command line, not inside WordPress: it writes test data to the option.\n" ); exit( 2 ); }
$GLOBALS['__zdz_nm_tr'] = array();
if ( ! function_exists( 'get_option' ) ) {
	function get_transient( $k ) { return $GLOBALS['__zdz_nm_tr'][ $k ] ?? false; }
	function set_transient( $k, $v, $t ) { $GLOBALS['__zdz_nm_tr'][ $k ] = $v; return true; }
	function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__zdz_nm_opts'] ) ? $GLOBALS['__zdz_nm_opts'][ $k ] : $d; }
	function add_option( $k, $v, $dep = '', $autoload = 'yes' ) { $GLOBALS['__zdz_nm_opts'][ $k ] = $v; $GLOBALS['__zdz_nm_autoload'][ $k ] = $autoload; return true; }
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['__zdz_nm_opts'][ $k ] = $v; return true; }
}

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
ok( 'VERSION 1.1.0', ZDZ_Name_Match::VERSION === '1.1.0' );

/* ── the map comes from the private option, and the zdz_name_homophones_map FILTER ran ── */
$map = ZDZ_Name_Match::load_homophone_map();
ok( 'map loads from the option (non-empty)', is_array($map) && ! empty($map) );
ok( 'the option\'s 3 surname groups (+1 filter-injected = 4)', isset($map['surnames']) && count($map['surnames']) === 4 );
ok( 'the option\'s given-name group', isset($map['given_names']) && count($map['given_names']) === 1 );
ok( 'no name list ships with the code', ! file_exists( __DIR__ . '/../../zorderz/inc/name-homophones.json' ) );
$cls = file_get_contents( __DIR__ . '/../../zorderz/inc/class-zdz-name-match.php' );
ok( 'the class reads the option, not a file', strpos( $cls, 'get_option( self::OPTION, null )' ) !== false && strpos( $cls, "file_get_contents( __DIR__ . '/name-homophones.json' )" ) === false );
ok( 'extension filter zdz_name_homophones_map ran', $GLOBALS['__zdz_filter_hit'] === true );
ok( 'filter-injected group is queryable (Zephyr→Zefir)', has('zefir', hv('zephyr')) );
ok( 'distinct_but_confusable present', isset($map['distinct_but_confusable']) );
ok( 'not_soundalike_flags present', isset($map['not_soundalike_flags']) );

/* ── Layer 1: metaphone key ── */
ok( 'key: Casey==Kacie', key_('Casey') !== '' && key_('Casey') === key_('Kacie') );
ok( 'key: Jordan==Jourdain', key_('Jordan') === key_('Jourdain') );
ok( 'key SPLITS Quinn vs Kwin (curated must repair)', key_('Quinn') !== '' && key_('Quinn') !== key_('Kwin') );
ok( 'key SPLITS Sean vs Shawn (curated must repair)', key_('Sean') !== key_('Shawn') );
ok( 'key: empty -> ""', key_('') === '' );

/* ── Layer 2: curated groups ── */
ok( 'variants(sean) has shawn', has('shawn', hv('sean')) );
ok( 'variants(quinn) has kwin', has('kwin', hv('quinn')) );
ok( 'variants symmetric (shawn→sean)', has('sean', hv('shawn')) );
ok( 'variants never returns the query itself', ! has('sean', hv('sean')) );
ok( 'variants(example glass) === [] (distinct_but_confusable, not a spelling group)', hv('example glass') === array() );
ok( 'variants(unknown) === []', hv('zzznotaname') === array() );

/* ── sounds_like ── */
ok( 'sounds_like sean~shawn (curated-only; metaphone splits)', sl('sean','shawn') === true && nc('sean','shawn') === false );
ok( 'sounds_like quinn~kwin (curated-only)', sl('quinn','kwin') === true && nc('quinn','kwin') === false );
ok( 'sounds_like casey~kacie (metaphone)', sl('casey','kacie') === true );
ok( 'sounds_like unrelated miller~johnson -> false', sl('miller','johnson') === false );

/* ── nickname/typo layer ── */
ok( 'fe mike~michael (nickname)', fe('mike','michael') === true );
ok( 'nc millar~miller (typo)', nc('millar','miller') === true );
ok( 'fe don !~ brandon (anchored prefix only)', fe('don','brandon') === false );

/* ── scoring: name_match_points + homophone_points, and the combined name_score ── */
ok( 'first-name soundalike Shawn↔Sean: hp +10, mp 5', hp('Shawn','Morgan','sean','morgan') === 10 && mp('Shawn','Morgan','sean','morgan') === 5 );
ok( 'name_score(Shawn,Morgan,sean,morgan) === 15', sc('Shawn','Morgan','sean','morgan') === 15 );
ok( 'surname soundalike Quinn↔Kwin: hp +4, mp 0', hp('','Quinn','','kwin') === 4 && mp('','Quinn','','kwin') === 0 );
ok( 'name_score(,Quinn,,kwin) === 4 (pure layer would give 0)', sc('','Quinn','','kwin') === 4 );
ok( 'no double-count Devin↔Devon: hp 0, mp 4', hp('','Devin','','devon') === 0 && mp('','Devin','','devon') === 4 );
ok( 'exact Alex Jordan: name_score 15, hp 0', sc('Alex','Jordan','alex','jordan') === 15 && hp('Alex','Jordan','alex','jordan') === 0 );
ok( 'PRECISION unrelated Anderson/Miller: name_score 0', sc('','Anderson','','miller') === 0 );

/* ── the shared parse rule ── */
$rule = ZDZ_Name_Match::parse_rule();
ok( 'parse_rule() non-empty string', is_string($rule) && strlen($rule) > 80 );
ok( 'parse_rule() names the sound-not-spelling principle', stripos($rule,'sound') !== false && stripos($rule,'spelling') !== false );
ok( 'parse_rule() carries the [AMBIGUOUS] escape hatch', strpos($rule,'[AMBIGUOUS') !== false );
ok( 'parse_rule() forbids merging soundalikes', stripos($rule,'never merge') !== false );

/* ── moving an older build's file into the private option, then deleting it ── */
$tmp = sys_get_temp_dir() . '/zdz-nm-' . getmypid();
@mkdir( $tmp );
file_put_contents( $tmp . '/name-homophones.json', json_encode( $GLOBALS['__zdz_nm_fixture'] ) );
unset( $GLOBALS['__zdz_nm_opts']['zdz_name_homophones'] );
ok( 'migrate: a valid old file is moved into the option', ZDZ_Name_Match::migrate_legacy_file( $tmp ) === 'imported'
	&& count( $GLOBALS['__zdz_nm_opts']['zdz_name_homophones']['surnames'] ) === 3 );
ok( 'migrate: ...never autoloaded', ( $GLOBALS['__zdz_nm_autoload']['zdz_name_homophones'] ?? '' ) === 'no' );
ok( 'migrate: ...and the public file is deleted', ! file_exists( $tmp . '/name-homophones.json' ) );
ok( 'migrate: nothing to do when there is no file', ZDZ_Name_Match::migrate_legacy_file( $tmp ) === 'none' );
$GLOBALS['__zdz_nm_opts']['zdz_name_homophones']['surnames'][] = array( 'sound' => 'x', 'spellings' => array( 'Robin', 'Robyn' ) );
file_put_contents( $tmp . '/name-homophones.json', json_encode( $GLOBALS['__zdz_nm_fixture'] ) );
ok( 'migrate: an option that already holds every group of the file is kept as it is, and the file goes',
	ZDZ_Name_Match::migrate_legacy_file( $tmp ) === 'kept' && count( $GLOBALS['__zdz_nm_opts']['zdz_name_homophones']['surnames'] ) === 4 && ! file_exists( $tmp . '/name-homophones.json' ) );
/* a SMALLER list already in the option must not cost the file's groups */
$GLOBALS['__zdz_nm_opts']['zdz_name_homophones'] = array( 'surnames' => array( array( 'sound' => 'x', 'spellings' => array( 'Robin', 'Robyn' ) ) ) );
file_put_contents( $tmp . '/name-homophones.json', json_encode( $GLOBALS['__zdz_nm_fixture'] ) );
$res = ZDZ_Name_Match::migrate_legacy_file( $tmp );
ok( 'migrate: a smaller list already in the option is MERGED with the file (nothing dropped), then the file goes',
	$res === 'merged' && count( $GLOBALS['__zdz_nm_opts']['zdz_name_homophones']['surnames'] ) === 4
	&& count( $GLOBALS['__zdz_nm_opts']['zdz_name_homophones']['given_names'] ) === 1 && ! file_exists( $tmp . '/name-homophones.json' ), $res );
unset( $GLOBALS['__zdz_nm_opts']['zdz_name_homophones'] );
file_put_contents( $tmp . '/name-homophones.json', '{"not":"a list"}' );
ok( 'migrate: a file that is not a list is left alone and nothing is stored', ZDZ_Name_Match::migrate_legacy_file( $tmp ) === 'invalid'
	&& file_exists( $tmp . '/name-homophones.json' ) && ! isset( $GLOBALS['__zdz_nm_opts']['zdz_name_homophones'] ) );
ok( 'migrate: ...and is not tried again for an hour (not on every request)', ZDZ_Name_Match::migrate_legacy_file( $tmp ) === 'wait' );
@unlink( $tmp . '/name-homophones.json' ); @rmdir( $tmp );

/* merge_maps / is_map / group_count are pure */
ok( 'is_map: needs surname groups with spellings', ZDZ_Name_Match::is_map( $GLOBALS['__zdz_nm_fixture'] ) && ! ZDZ_Name_Match::is_map( array( 'surnames' => array( array( 'sound' => 'x' ) ) ) ) );
ok( 'merge_maps: the same group in another order is not added twice',
	ZDZ_Name_Match::group_count( ZDZ_Name_Match::merge_maps( array( 'surnames' => array( array( 'spellings' => array( 'Kwin', 'Quinn' ) ) ) ), array( 'surnames' => array( array( 'spellings' => array( 'quinn', 'kwin' ) ) ) ) ) ) === 1 );

$fn = file_get_contents( __DIR__ . '/../../zorderz/functions.php' );
ok( 'functions.php runs the move on init, only while the old file exists', strpos( $fn, 'migrate_legacy_file' ) !== false && strpos( $fn, "ZDZ_Name_Match::LEGACY_FILE" ) !== false && strpos( $fn, 'is_file(' ) !== false );

/* summary */
echo implode("\n", $checks), "\n";
if ( $fail === 0 ) { echo "\nAll ZDZ_Name_Match shared-matcher tests passed. ({$pass} assertions)\n"; exit(0); }
echo "\n{$pass} passed, {$fail} failed, " . ($pass+$fail) . " total\n";
exit(1);
