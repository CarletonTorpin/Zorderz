<?php
/**
 * Make the app's own key for one Zorderz site, and the zdz-secrets.php file that holds it.
 *
 *     php bin/make-app-keys.php --site=app.example.com NEW-FOLDER
 *
 * Run it on your own computer, never on the web server: the key should exist in your hands (a password manager, a
 * sheet of paper) before it exists anywhere else. It needs only PHP on the command line. It sends nothing anywhere
 * and reads nothing but its own arguments.
 *
 * What it does:
 *   - draws 32 random bytes from the system's random source and writes them as a printed key (base32: 52 characters
 *     in 13 groups of four);
 *   - writes NEW-FOLDER/zdz-secrets.php with that key and the site it is for. The folder is made if it is not there
 *     (yours only, 0700); the file is yours only (0600) and is never written over: if one is already there, it stops;
 *   - prints the KEY ID (eight characters that say which key it is, and nothing about it). It never prints the key:
 *     open the file to copy the key into your password manager and onto paper.
 *
 * One key per site. A staging or test copy of a site gets a key of its own: run this again with that site's address
 * and another folder. Then, on the site: put its zdz-secrets.php in the folder that holds wp-config.php, add the two
 * lines this script prints to wp-config.php, and open Zorderz -> App key, which shows the same key id.
 *
 * Never make a second key for a site whose key is already in use: what was sealed under a key opens with no other.
 *
 * @package Zorderz
 */

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 404 );
	exit;
}

const ZDZ_MAKE_KEY_FILE  = 'zdz-secrets.php';
const ZDZ_MAKE_KEY_LABEL = 'zdzk1 key id';   // the key id is the first 8 hex of HMAC-SHA256( this label, key )

/** Write a line to the screen (or to the error stream). */
function zdz_make_key_say( $line, $to_error = false ) {
	fwrite( $to_error ? STDERR : STDOUT, $line . "\n" );
}

/** 32 bytes as a printed key: RFC 4648 base32 without padding, in groups of four. */
function zdz_make_key_printed( $raw ) {
	$alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	$bits  = '';
	foreach ( str_split( $raw ) as $c ) {
		$bits .= str_pad( decbin( ord( $c ) ), 8, '0', STR_PAD_LEFT );
	}
	$out = '';
	foreach ( str_split( $bits, 5 ) as $group ) {
		$out .= $alpha[ bindec( str_pad( $group, 5, '0', STR_PAD_RIGHT ) ) ];
	}
	return implode( ' ', str_split( $out, 4 ) );
}

/** A printed key read back into its 32 bytes (spaces and dashes ignored, 0, 1 and 8 read as O, I and B), or null. */
function zdz_make_key_read( $printed ) {
	$s = strtr( strtoupper( (string) $printed ), array( '0' => 'O', '1' => 'I', '8' => 'B' ) );
	$s = (string) preg_replace( '/[\s\-]/', '', $s );
	if ( 52 !== strlen( $s ) || ! preg_match( '/^[A-Z2-7]+$/D', $s ) ) {
		return null;
	}
	$alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	$bits  = '';
	foreach ( str_split( $s ) as $c ) {
		$bits .= str_pad( decbin( strpos( $alpha, $c ) ), 5, '0', STR_PAD_LEFT );
	}
	$out = '';
	foreach ( str_split( substr( $bits, 0, 256 ), 8 ) as $byte ) {
		$out .= chr( bindec( $byte ) );
	}
	return 32 === strlen( $out ) ? $out : null;
}

/** The key id: what Zorderz -> App key shows for this key. Never the key. */
function zdz_make_key_id( $raw ) {
	return substr( hash_hmac( 'sha256', ZDZ_MAKE_KEY_LABEL, $raw ), 0, 8 );
}

/**
 * A site's address as the app compares it: lower case, no scheme, path or port, no leading "www.". '' when what is
 * left is not a host name.
 */
function zdz_make_key_host( $typed ) {
	$s = strtolower( trim( (string) $typed ) );
	$s = (string) preg_replace( '#^[a-z][a-z0-9+.\-]*://#', '', $s );
	$s = (string) preg_replace( '#[/?\#].*$#', '', $s );
	$s = (string) preg_replace( '#:\d+$#', '', $s );
	$s = (string) preg_replace( '#^www\.#', '', $s );
	if ( '' === $s || strlen( $s ) > 253 ) {
		return '';
	}
	return preg_match( '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/D', $s ) ? $s : '';
}

/** The two lines for wp-config.php. The require is guarded: a missing file must never take the site down. */
function zdz_make_key_config_lines() {
	return array(
		"define( 'ZDZ_SECRETS_LOAD', true );",
		"if ( is_readable( __DIR__ . '/zdz-secrets.php' ) ) { require_once __DIR__ . '/zdz-secrets.php'; }",
	);
}

/** The file's text. It defines nothing unless wp-config.php asked for it, and answers "not found" on its own. */
function zdz_make_key_file_text( $printed, $host, $kid, $made ) {
	$lines = array(
		'<?php',
		'/**',
		' * Zorderz: this site\'s own key. It belongs in the folder that holds wp-config.php, which loads it with:',
		' *',
		' *     ' . zdz_make_key_config_lines()[0],
		' *     ' . zdz_make_key_config_lines()[1],
		' *',
		' * Keep a copy of the ZDZ_APP_KEY line in your password manager and on paper. Never share this file, never put it',
		' * in a repository, and never make a new key in its place: what the app sealed under this key opens with no other.',
		' *',
		' * Made ' . $made . ' UTC for ' . $host . '. Key id: ' . $kid . ' (Zorderz -> App key shows the same).',
		' */',
		'if ( ! defined( \'ZDZ_SECRETS_LOAD\' ) ) {',
		'	// Not asked for by wp-config.php: define nothing. Asked for on its own, over the web: "not found", and no output.',
		'	if ( __FILE__ === ( get_included_files()[0] ?? \'\' ) ) {',
		'		http_response_code( 404 );',
		'		exit;',
		'	}',
		'	return;',
		'}',
		'',
		'// The app\'s key, as printed: 13 groups of four letters and digits.',
		'if ( ! defined( \'ZDZ_APP_KEY\' ) ) {',
		'	define( \'ZDZ_APP_KEY\', \'' . $printed . '\' );',
		'}',
		'',
		'// The site this key is for. Another site that loads this file locks its app secrets instead of reading them.',
		'if ( ! defined( \'ZDZ_APP_KEY_SITE\' ) ) {',
		'	define( \'ZDZ_APP_KEY_SITE\', \'' . $host . '\' );',
		'}',
		'',
		'// Only during a deliberate key change: the key being replaced, exactly as it was printed. Take the line out again',
		'// once Zorderz -> App key says everything is under the new key.',
		'// define( \'ZDZ_APP_KEY_PREVIOUS\', \'\' );',
		'',
	);
	return implode( "\n", $lines );
}

/** Stop with a message and change nothing more. */
function zdz_make_key_stop( $why, $made_dir = '', $made_file = '' ) {
	if ( '' !== $made_file && is_file( $made_file ) ) {
		unlink( $made_file );
	}
	if ( '' !== $made_dir && is_dir( $made_dir ) ) {
		rmdir( $made_dir );   // only a folder this run made, and only when it is empty
	}
	zdz_make_key_say( 'Nothing was made: ' . $why, true );
	exit( 1 );
}

function zdz_make_key_usage() {
	return implode(
		"\n",
		array(
			'Usage: php make-app-keys.php --site=SITE-ADDRESS NEW-FOLDER',
			'',
			'  --site=SITE-ADDRESS   the address of the site this key is for, such as app.example.com',
			'  NEW-FOLDER            where zdz-secrets.php is written (made if it is not there; never written over)',
			'',
			'Makes one site\'s key on this computer and prints its key id. It never prints the key and sends nothing anywhere.',
		)
	);
}

/* ---------------------------------------------------------------------- the arguments */

$zdz_args   = isset( $argv ) && is_array( $argv ) ? array_slice( $argv, 1 ) : array();
$zdz_site   = null;
$zdz_folder = null;
$zdz_count  = count( $zdz_args );
for ( $zdz_i = 0; $zdz_i < $zdz_count; $zdz_i++ ) {
	$zdz_arg = (string) $zdz_args[ $zdz_i ];
	if ( '--help' === $zdz_arg || '-h' === $zdz_arg ) {
		zdz_make_key_say( zdz_make_key_usage() );
		exit( 0 );
	}
	if ( 0 === strpos( $zdz_arg, '--site=' ) ) {
		$zdz_site = substr( $zdz_arg, 7 );
	} elseif ( '--site' === $zdz_arg && $zdz_i + 1 < $zdz_count ) {
		$zdz_site = (string) $zdz_args[ ++$zdz_i ];
	} elseif ( 0 === strpos( $zdz_arg, '-' ) ) {
		zdz_make_key_say( zdz_make_key_usage(), true );
		zdz_make_key_stop( 'that option is not one this script knows.' );
	} elseif ( null === $zdz_folder ) {
		$zdz_folder = $zdz_arg;
	} else {
		zdz_make_key_say( zdz_make_key_usage(), true );
		zdz_make_key_stop( 'name one folder only.' );
	}
}
if ( null === $zdz_site || null === $zdz_folder || '' === trim( $zdz_folder ) ) {
	zdz_make_key_say( zdz_make_key_usage(), true );
	zdz_make_key_stop( 'name the site (--site=...) and a folder for the file.' );
}
$zdz_host = zdz_make_key_host( $zdz_site );
if ( '' === $zdz_host ) {
	zdz_make_key_stop( 'that is not a site address. Give the host name the site is reached at, such as app.example.com.' );
}

/* ---------------------------------------------------------------------- the folder and the file */

umask( 0077 );   // whatever this run makes is its owner's only, from the first byte
$zdz_dir      = rtrim( $zdz_folder, '/\\' );
$zdz_dir      = '' === $zdz_dir ? $zdz_folder : $zdz_dir;
$zdz_file     = $zdz_dir . DIRECTORY_SEPARATOR . ZDZ_MAKE_KEY_FILE;
$zdz_made_dir = '';
if ( file_exists( $zdz_file ) || is_link( $zdz_file ) ) {
	zdz_make_key_stop( $zdz_file . ' is already there, and a key file is never written over. Name another folder.' );
}
if ( file_exists( $zdz_dir ) || is_link( $zdz_dir ) ) {
	if ( ! is_dir( $zdz_dir ) || ! is_writable( $zdz_dir ) ) {
		zdz_make_key_stop( $zdz_dir . ' is not a folder this script can write in.' );
	}
} else {
	if ( ! @mkdir( $zdz_dir, 0700 ) ) {
		zdz_make_key_stop( 'the folder ' . $zdz_dir . ' could not be made (the folder above it must already be there).' );
	}
	$zdz_made_dir = $zdz_dir;
}

/* ---------------------------------------------------------------------- the key */

$zdz_raw     = random_bytes( 32 );
$zdz_printed = zdz_make_key_printed( $zdz_raw );
$zdz_kid     = zdz_make_key_id( $zdz_raw );
if ( zdz_make_key_read( $zdz_printed ) !== $zdz_raw ) {
	zdz_make_key_stop( 'the key did not read back as it was made.', $zdz_made_dir );
}

$zdz_handle = @fopen( $zdz_file, 'xb' );   // made new, or not at all: an existing file is never opened
if ( false === $zdz_handle ) {
	zdz_make_key_stop( $zdz_file . ' could not be made new (is one already there?).', $zdz_made_dir );
}
$zdz_text    = zdz_make_key_file_text( $zdz_printed, $zdz_host, $zdz_kid, gmdate( 'Y-m-d H:i' ) );
$zdz_written = fwrite( $zdz_handle, $zdz_text );
$zdz_closed  = fclose( $zdz_handle );
if ( strlen( $zdz_text ) !== $zdz_written || ! $zdz_closed ) {
	zdz_make_key_stop( 'the file could not be written whole.', $zdz_made_dir, $zdz_file );
}
@chmod( $zdz_file, 0600 );

// Read the file back: it must hold a key that reads as the one just made.
$zdz_back = (string) file_get_contents( $zdz_file );
if ( ! preg_match( "/define\( 'ZDZ_APP_KEY', '([A-Z2-7 ]+)' \);/", $zdz_back, $zdz_m ) || zdz_make_key_read( $zdz_m[1] ) !== $zdz_raw ) {
	zdz_make_key_stop( 'the file did not read back as it was written.', $zdz_made_dir, $zdz_file );
}
$zdz_open_to_others = '/' === DIRECTORY_SEPARATOR && 0 !== ( fileperms( $zdz_file ) & 0077 );
$zdz_shown          = realpath( $zdz_file );
$zdz_shown          = false === $zdz_shown ? $zdz_file : $zdz_shown;

// The key itself is in the file only: forget this run's copies as far as PHP allows.
if ( function_exists( 'sodium_memzero' ) && extension_loaded( 'sodium' ) ) {
	sodium_memzero( $zdz_raw );
	sodium_memzero( $zdz_printed );
	sodium_memzero( $zdz_text );
	sodium_memzero( $zdz_back );
}
unset( $zdz_raw, $zdz_printed, $zdz_text, $zdz_back, $zdz_m );

/* ---------------------------------------------------------------------- what to do next */

$zdz_config = zdz_make_key_config_lines();
zdz_make_key_say( 'Made the key for ' . $zdz_host . '.' );
zdz_make_key_say( '' );
zdz_make_key_say( '  Key id:  ' . $zdz_kid );
zdz_make_key_say( '  File:    ' . $zdz_shown );
zdz_make_key_say( '' );
if ( $zdz_open_to_others ) {
	zdz_make_key_say( '  CAREFUL: this computer did not let the file be closed to other accounts. Close it by hand.' );
	zdz_make_key_say( '' );
}
zdz_make_key_say( 'The key itself is in that file and nowhere else, and it is not shown here. Next:' );
zdz_make_key_say( '  1. Copy the ZDZ_APP_KEY line from the file into your password manager and onto paper.' );
zdz_make_key_say( '  2. Put the file in the folder that holds that site\'s wp-config.php.' );
zdz_make_key_say( '  3. Add these two lines to wp-config.php, above the line that loads wp-settings.php:' );
zdz_make_key_say( '       ' . $zdz_config[0] );
zdz_make_key_say( '       ' . $zdz_config[1] );
zdz_make_key_say( '  4. Open Zorderz -> App key on the site: it should show key id ' . $zdz_kid . '.' );
zdz_make_key_say( '  5. Then delete this folder: the password manager and the paper are your copies.' );
zdz_make_key_say( '' );
zdz_make_key_say( 'One key per site: a staging or test copy gets its own (run this again with its address and another folder).' );
zdz_make_key_say( 'Never make a second key for a site whose key is in use: what was sealed under a key opens with no other.' );
exit( 0 );
