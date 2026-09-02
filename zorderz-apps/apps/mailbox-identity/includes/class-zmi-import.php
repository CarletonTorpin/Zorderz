<?php
/**
 * ZMI_Import — bulk-load per-account Exchange identities from a pasted roster.
 *
 * Many providers structure a small business's mailboxes as a ROLE address (install@,
 * tech@, shop@…) with each person's first name as a personal alias (e.g. alex@…); some
 * people are just name@ with no role alias. This tool turns a pasted roster into
 * `zmi_mailbox_identity` records, matching each row to a WordPress user — which only the
 * live site can resolve, since users live in the DB, not the app files.
 *
 * Matching is exact + safe: try each address (primary, then aliases) against `user_email`;
 * else an EXACT, UNIQUE display-name match. Anything ambiguous or unmatched is REPORTED,
 * never guessed (same fail-safe posture as the resolver). Preview first; apply only what
 * matched.
 *
 * Roster line format (pipe-delimited; blank/`#` lines ignored):
 *   Display Name | primary@domain | alias1@domain, alias2@domain
 *
 * Ships with an EMPTY default roster — no person is pre-loaded for any tenant. Paste your
 * own roster on the settings screen; shared/service boxes go in the service-mailbox
 * registry, not here.
 *
 * @package Zorderz\Mailbox_Identity
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZMI_Import {

	/**
	 * The default roster shown in the settings textarea. Ships EMPTY (comment lines only,
	 * which the parser ignores) — no real person is compiled into this app. Paste your own
	 * roster and use Preview / Import below.
	 */
	public static function default_roster(): string {
		return <<<TXT
# Display Name | primary mailbox (Exchange role) | first-name / WP-login alias(es)
# One person per line, pipe-delimited. Matching is by email first, then an exact,
# unique display-name match — never a guess. Shared/service boxes (a support inbox,
# a sales inbox…) go in the service-mailbox registry above, not here. This roster
# ships empty; paste your own team below.
TXT;
	}

	/** Parse roster text → rows [{name, primary, aliases[]}]. PURE. */
	public static function parse( string $text ): array {
		$rows = array();
		foreach ( preg_split( '/\r?\n/', $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			$p       = array_map( 'trim', explode( '|', $line ) );
			$name    = $p[0] ?? '';
			$primary = ZDZ_Mailbox_Identity::normalize( $p[1] ?? '' );
			$aliases = array();
			if ( isset( $p[2] ) && '' !== trim( $p[2] ) ) {
				foreach ( preg_split( '/[\s,]+/', $p[2] ) as $a ) {
					$a = ZDZ_Mailbox_Identity::normalize( $a );
					if ( '' !== $a ) {
						$aliases[] = $a;
					}
				}
			}
			if ( '' === $primary && '' === $name ) {
				continue;
			}
			$rows[] = array( 'name' => $name, 'primary' => $primary, 'aliases' => array_values( array_unique( $aliases ) ) );
		}
		return $rows;
	}

	/**
	 * Find the WP user for a roster row. Returns [uid, by]. uid 0 = no confident match.
	 * by ∈ { email:<addr>, name, no-match, ambiguous-name }.
	 */
	public static function find_user( array $row ): array {
		foreach ( array_merge( array( $row['primary'] ), (array) $row['aliases'] ) as $addr ) {
			if ( '' === $addr ) {
				continue;
			}
			$u = get_user_by( 'email', $addr );
			if ( $u instanceof WP_User ) {
				return array( 'uid' => (int) $u->ID, 'by' => 'email:' . $addr );
			}
		}
		$name = strtolower( trim( (string) $row['name'] ) );
		if ( '' !== $name ) {
			$hits = array();
			foreach ( get_users( array( 'fields' => array( 'ID', 'display_name' ) ) ) as $u ) {
				if ( strtolower( trim( (string) $u->display_name ) ) === $name ) {
					$hits[] = (int) $u->ID;
				}
			}
			if ( 1 === count( $hits ) ) {
				return array( 'uid' => $hits[0], 'by' => 'name' );
			}
			if ( count( $hits ) > 1 ) {
				return array( 'uid' => 0, 'by' => 'ambiguous-name' );
			}
		}
		return array( 'uid' => 0, 'by' => 'no-match' );
	}

	/** Dry run: rows with their match + what would be written. No DB writes. */
	public static function preview( string $text ): array {
		$out = array();
		foreach ( self::parse( $text ) as $row ) {
			$m    = self::find_user( $row );
			$u    = $m['uid'] ? get_userdata( $m['uid'] ) : null;
			$out[] = array(
				'name'    => $row['name'],
				'primary' => $row['primary'],
				'aliases' => $row['aliases'],
				'uid'     => $m['uid'],
				'by'      => $m['by'],
				'user'    => $u ? ( $u->display_name . ' (' . $u->user_login . ')' ) : '',
			);
		}
		return $out;
	}

	/** Apply: write identities for confidently-matched rows. Returns a summary. */
	public static function apply( string $text ): array {
		$applied = 0;
		$skipped = 0;
		$errors  = array();
		foreach ( self::parse( $text ) as $row ) {
			$m = self::find_user( $row );
			if ( $m['uid'] <= 0 ) {
				$skipped++;
				continue;
			}
			$res = ZMI_Store::save_identity( $m['uid'], $row['primary'], $row['aliases'] );
			if ( is_wp_error( $res ) ) {
				$errors[] = ( '' !== $row['name'] ? $row['name'] : $row['primary'] ) . ': ' . $res->get_error_message();
			} else {
				$applied++;
			}
		}
		return array( 'applied' => $applied, 'skipped' => $skipped, 'errors' => $errors );
	}
}
