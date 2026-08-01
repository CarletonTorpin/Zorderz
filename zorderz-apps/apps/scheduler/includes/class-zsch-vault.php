<?php
/**
 * ZSCH_Vault — per-account encrypted token store + single-flight refresh.
 *
 * The Connected Calendars feature stores one OAuth grant per
 * (user × provider × external account) on wp_zsch_calendar_accounts. This
 * class owns the two hard parts:
 *
 *   1. ENCRYPTION AT REST. Tokens are sealed with libsodium secretbox
 *      (XSalsa20-Poly1305) when available, else OpenSSL AES-256-CTR with an
 *      HMAC-SHA256 tag (encrypt-then-MAC — CTR alone is malleable). The key is
 *      derived from wp_salt('auth') + a fixed domain string, so a DB dump
 *      alone can never yield a usable token (INV-Token). Values are prefixed
 *      ('v1s:' / 'v1o:') so both schemes decrypt side by side if the PHP build
 *      changes.
 *
 *   2. SINGLE-FLIGHT REFRESH (INV-4 — the FreshBooks lesson). Refresh tokens
 *      race: two requests refreshing the same account can strand it, and
 *      Microsoft ROTATES the refresh token on every use, so a lost rotation is
 *      fatal for that grant. One MySQL GET_LOCK per account serializes the
 *      refresh; the lock holder re-reads the row first and ADOPTS a sibling's
 *      fresh token (token_version bump) instead of refreshing again; waiters
 *      poll the row briefly and adopt rather than queueing a second refresh.
 *
 * Provider quirks owned here:
 *   - Microsoft: rotated access+refresh pair persisted atomically (one UPDATE).
 *   - Google: no rotation; a missing refresh_token in the response keeps the
 *     stored one. Reconnecting an account REPLACES its row (ZSCH_Connections),
 *     never inserts a sibling — Google caps live refresh tokens per client.
 *   - invalid_grant (revoked / 90-day idle / password change) is NORMAL, not an
 *     incident: status flips to 'reauth_needed', the stored tokens are kept
 *     (never blanked blindly), and the UI shows a quiet Reconnect chip.
 *
 * LOGGING: token VALUES never appear in any log — lengths and versions only
 * (token-service house style).
 *
 * @since 1.6.0 (Connected Calendars Phase 0)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSCH_Vault {

	/** Refresh when the access token has less than this many seconds left. */
	const REFRESH_SKEW = 300;

	/** GET_LOCK wait (seconds) for the refresh single-flight. */
	const LOCK_WAIT = 5;

	// ── encryption ─────────────────────────────────────────────────

	/**
	 * 32-byte binary key, derived per install from the auth salt. The domain
	 * string means this key equals nothing else derived from the same salt.
	 *
	 * @return string 32 raw bytes.
	 */
	private static function key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . 'zsch-vault-v1', true );
	}

	/** Separate MAC key for the OpenSSL path (never reuse the cipher key). */
	private static function mac_key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . 'zsch-vault-mac-v1', true );
	}

	/**
	 * Seal a secret for storage. '' stays '' (nothing to protect).
	 *
	 * @param string $plain
	 * @return string Prefixed ciphertext, or '' on empty input.
	 */
	public static function encrypt( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$sealed = sodium_crypto_secretbox( $plain, $nonce, self::key() );
			return 'v1s:' . base64_encode( $nonce . $sealed );
		}
		// OpenSSL fallback: AES-256-CTR + HMAC tag (encrypt-then-MAC).
		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plain, 'aes-256-ctr', self::key(), OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return ''; // Refuse to store anything rather than store plaintext.
		}
		$mac = hash_hmac( 'sha256', $iv . $cipher, self::mac_key(), true );
		return 'v1o:' . base64_encode( $iv . $cipher . $mac );
	}

	/**
	 * Open a sealed value. Returns '' for empty/undecryptable input — callers
	 * treat '' as "no token on file".
	 *
	 * @param string $stored
	 * @return string
	 */
	public static function decrypt( string $stored ): string {
		if ( '' === $stored ) {
			return '';
		}
		$prefix = substr( $stored, 0, 4 );
		$raw    = base64_decode( substr( $stored, 4 ), true );
		if ( false === $raw ) {
			return '';
		}
		if ( 'v1s:' === $prefix && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			if ( strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}
			$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$box   = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain = sodium_crypto_secretbox_open( $box, $nonce, self::key() );
			return ( false === $plain ) ? '' : $plain;
		}
		if ( 'v1o:' === $prefix ) {
			if ( strlen( $raw ) <= 48 ) { // 16 iv + ≥0 cipher + 32 mac
				return '';
			}
			$iv     = substr( $raw, 0, 16 );
			$mac    = substr( $raw, -32 );
			$cipher = substr( $raw, 16, -32 );
			$expect = hash_hmac( 'sha256', $iv . $cipher, self::mac_key(), true );
			if ( ! hash_equals( $expect, $mac ) ) {
				return '';
			}
			$plain = openssl_decrypt( $cipher, 'aes-256-ctr', self::key(), OPENSSL_RAW_DATA, $iv );
			return ( false === $plain ) ? '' : $plain;
		}
		return '';
	}

	/**
	 * Round-trip sanity: seal must open back to the same value before we
	 * persist it (the token service's looks_decrypted posture — never store a
	 * value we can't read).
	 */
	public static function seal_checked( string $plain ): string {
		$sealed = self::encrypt( $plain );
		if ( '' === $plain ) {
			return '';
		}
		if ( '' === $sealed || self::decrypt( $sealed ) !== $plain ) {
			return '';
		}
		return $sealed;
	}

	// ── token persistence on the account row ───────────────────────

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zsch_calendar_accounts';
	}

	/**
	 * Persist a (possibly rotated) token pair atomically and bump the version.
	 * Empty $refresh keeps the stored refresh token (Google doesn't rotate).
	 *
	 * @param int    $account_id
	 * @param string $access
	 * @param string $refresh    '' = keep existing.
	 * @param int    $expires_in Seconds until the access token dies.
	 * @return bool
	 */
	public static function store_tokens( int $account_id, string $access, string $refresh, int $expires_in ): bool {
		global $wpdb;
		$access_enc = self::seal_checked( $access );
		if ( '' !== $access && '' === $access_enc ) {
			self::log( "store refused: seal failed (acct {$account_id}, len " . strlen( $access ) . ')' );
			return false;
		}
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + max( 60, $expires_in ) );

		$set  = array(
			'access_token_enc' => $access_enc,
			'token_expires_at' => $expires_at,
			'status'           => 'ok',
			'last_error'       => '',
			'updated_at'       => gmdate( 'Y-m-d H:i:s' ),
		);
		if ( '' !== $refresh ) {
			$refresh_enc = self::seal_checked( $refresh );
			if ( '' === $refresh_enc ) {
				self::log( "store refused: refresh seal failed (acct {$account_id})" );
				return false;
			}
			$set['refresh_token_enc'] = $refresh_enc;
		}

		// Atomic write of the pair + version bump in ONE statement.
		$assignments = array();
		$values      = array();
		foreach ( $set as $col => $val ) {
			$assignments[] = "`$col` = %s";
			$values[]      = $val;
		}
		$assignments[] = '`token_version` = `token_version` + 1';
		$values[]      = $account_id;

		$sql = 'UPDATE ' . self::table() . ' SET ' . implode( ', ', $assignments ) . ' WHERE id = %d';
		$ok  = $wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		self::log( "stored tokens acct {$account_id} (access len " . strlen( $access ) . ', refresh ' . ( '' !== $refresh ? 'rotated len ' . strlen( $refresh ) : 'kept' ) . ')' );
		return false !== $ok;
	}

	/** Flip an account to reauth_needed WITHOUT touching its tokens. */
	public static function mark_reauth( int $account_id, string $why ): void {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'status'     => 'reauth_needed',
				'last_error' => substr( sanitize_text_field( $why ), 0, 250 ),
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $account_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		self::log( "acct {$account_id} → reauth_needed ({$why})" );
	}

	// ── single-flight access-token acquisition ─────────────────────

	/**
	 * Get a valid access token for an account, refreshing if needed. This is
	 * the ONLY correct way to obtain a token for provider calls.
	 *
	 * @param int $account_id
	 * @return string|WP_Error Bearer token.
	 */
	public static function get_access_token( int $account_id ) {
		$row = self::row( $account_id );
		if ( ! $row ) {
			return new WP_Error( 'zsch_vault_missing', 'Unknown calendar account.' );
		}
		if ( 'reauth_needed' === $row->status ) {
			return new WP_Error( 'zsch_reauth', 'This calendar needs to be reconnected.' );
		}

		$access = self::decrypt( (string) $row->access_token_enc );
		if ( '' !== $access && self::fresh( $row ) ) {
			return $access;
		}
		return self::refresh_single_flight( $row );
	}

	private static function fresh( $row ): bool {
		return ! empty( $row->token_expires_at )
			&& ( strtotime( $row->token_expires_at . ' UTC' ) - time() ) > self::REFRESH_SKEW;
	}

	private static function row( int $account_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$account_id
		) );
	}

	/**
	 * Serialized refresh. Lock name is per-account and DB-scoped (GET_LOCK is
	 * server-global; DB_NAME prevents cross-site collisions on shared MySQL).
	 *
	 * @param object $row Fresh account row.
	 * @return string|WP_Error
	 */
	private static function refresh_single_flight( $row ) {
		global $wpdb;
		$lock = 'zsch_tok_' . (int) $row->id . '.' . DB_NAME;

		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::LOCK_WAIT ) );
		if ( '1' !== (string) $got ) {
			// Couldn't get the lock — a sibling is refreshing. Poll for its result.
			for ( $i = 0; $i < 8; $i++ ) {
				usleep( 500000 ); // 0.5s
				$again = self::row( (int) $row->id );
				if ( $again && (int) $again->token_version > (int) $row->token_version && self::fresh( $again ) ) {
					$adopted = self::decrypt( (string) $again->access_token_enc );
					if ( '' !== $adopted ) {
						self::log( "acct {$row->id}: adopted sibling refresh (v{$again->token_version})" );
						return $adopted;
					}
				}
			}
			return new WP_Error( 'zsch_vault_busy', 'Calendar account is busy refreshing — try again.' );
		}

		try {
			// Holder re-reads: a sibling may have refreshed while we waited.
			$fresh_row = self::row( (int) $row->id );
			if ( ! $fresh_row ) {
				return new WP_Error( 'zsch_vault_missing', 'Unknown calendar account.' );
			}
			if ( (int) $fresh_row->token_version > (int) $row->token_version && self::fresh( $fresh_row ) ) {
				$adopted = self::decrypt( (string) $fresh_row->access_token_enc );
				if ( '' !== $adopted ) {
					self::log( "acct {$row->id}: adopted at lock (v{$fresh_row->token_version})" );
					return $adopted;
				}
			}

			$refresh = self::decrypt( (string) $fresh_row->refresh_token_enc );
			if ( '' === $refresh ) {
				self::mark_reauth( (int) $row->id, 'no refresh token on file' );
				return new WP_Error( 'zsch_reauth', 'This calendar needs to be reconnected.' );
			}

			$result = ( 'microsoft' === $fresh_row->provider )
				? ZSCH_Graph_Delegated::refresh_token( $refresh )
				: ZSCH_Google::refresh_token( $refresh );

			if ( is_wp_error( $result ) ) {
				if ( 'invalid_grant' === $result->get_error_code() ) {
					self::mark_reauth( (int) $row->id, 'refresh rejected (invalid_grant)' );
					return new WP_Error( 'zsch_reauth', 'This calendar needs to be reconnected.' );
				}
				self::log( "acct {$row->id}: refresh transient error — " . $result->get_error_code() );
				return $result; // Transient (network/5xx): do NOT blank anything.
			}

			$ok = self::store_tokens(
				(int) $row->id,
				(string) $result['access_token'],
				(string) ( $result['refresh_token'] ?? '' ), // MS rotates; Google omits.
				(int) ( $result['expires_in'] ?? 3600 )
			);
			if ( ! $ok ) {
				return new WP_Error( 'zsch_vault_store', 'Could not persist refreshed token.' );
			}
			return (string) $result['access_token'];
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/** Length-only, version-only logging under WP_DEBUG. */
	private static function log( string $msg ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'ZSCH Vault: ' . $msg );
		}
	}
}
