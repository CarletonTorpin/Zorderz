<?php
/**
 * ZDZ_Core_FB_Credentials — ONE billing (FreshBooks) credential, served to every app that
 * reads its own option family, in the format that app reads.
 *
 * WHY
 * ---
 * An app that talks to FreshBooks on its own keeps a family of options:
 * <prefix>fb_client_id, _client_secret, _access_token, _refresh_token, _account_id. Several
 * families holding a value that must never disagree is a bug waiting for a trigger: one
 * family ends up with one FreshBooks app's client id beside another app's refresh token,
 * FreshBooks answers invalid_grant to that pair forever, and the only symptom is a banner
 * nobody can clear by reconnecting. Writing every refresh to every family only promises to
 * keep them in step.
 *
 * WHAT THIS DOES INSTEAD
 * ----------------------
 * Core's own family (zdz_core_fb_*) is the only real credential; every app family listed
 * in views() becomes a VIEW onto it:
 *
 *   READ   pre_option_{prefix}fb_*       -> Core's value, in the format that app reads
 *   WRITE  update_option_{prefix}fb_*    -> a connection the app completed itself
 *          add_option_{prefix}fb_*          (tokens, account) is copied into Core's row
 *
 * Apps keep calling get_option( '<prefix>fb_refresh_token' ) exactly as before and need no
 * change. There is one token, so there is nothing to drift. An installer registers ONE
 * FreshBooks app, runs ONE sign-in, and has one place to look when it breaks.
 *
 * Formats: Core seals secrets and tokens at rest (fixed-IV); an app's family may expect its
 * tokens plain and its client secret in its own format. The tokens, the client id and the
 * account id are served decoded; the client secret is served as stored here and re-encoded
 * per reader by ZDZ_Core_Credentials (priority 20 on the same filter).
 *
 * WHAT IT GIVES UP
 * ----------------
 * Independent failure domains: a dead token is dead everywhere at once. That is acceptable
 * only because failure is visible (the token service's reconnect notice, honest KPI
 * freshness), never because it is rare.
 *
 * SAFETY
 * ------
 * Fails OPEN. If Core's set is not complete the filters do not engage at all and every
 * family behaves exactly as it did before. Killable at runtime with
 * add_filter( 'zdz_fb_single_credential', '__return_false' ).
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Core_FB_Credentials {

	/** The one true home. */
	const MASTER = 'zdz_core_';

	/** Records the one-time consolidation's outcome. */
	const DONE = 'zdz_core_fb_consolidated';

	/** The credential set. All of it moves together or none of it does. */
	const KEYS = array( 'fb_client_id', 'fb_client_secret', 'fb_access_token', 'fb_refresh_token', 'fb_account_id' );

	/** Memoized canonical values for this request. */
	private static $memo = array();

	/** Guard so a write we make ourselves does not re-enter. */
	private static $writing = false;

	/**
	 * The app families that read their own FreshBooks rows, and the format each one's
	 * client-secret reader decodes: prefix => ZDZ_Core_Crypto format.
	 *
	 * Core default: Leads (zl_), whose ZL_Admin::decrypt_shared() reads the client secret
	 * fixed-IV only. An app that keeps its own family adds itself with the
	 * zdz_fb_credential_views filter. An app on its OWN FreshBooks app (Invoices) must not
	 * be listed: its family is a different registration, not a copy.
	 *
	 * @return array<string,string>
	 */
	public static function views(): array {
		$v   = apply_filters( 'zdz_fb_credential_views', array( 'zl_' => ZDZ_Core_Crypto::FIXED ) );
		$ok  = array( ZDZ_Core_Crypto::PLAIN, ZDZ_Core_Crypto::FIXED, ZDZ_Core_Crypto::PAIR );
		$out = array();
		foreach ( (array) $v as $prefix => $format ) {
			$prefix = (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $prefix ) );
			if ( '' === $prefix || self::MASTER === $prefix || ! in_array( $format, $ok, true ) ) {
				continue;
			}
			$out[ $prefix ] = (string) $format;
		}
		return $out;
	}

	/* =====================================================================
	 * BOOT
	 * ===================================================================== */

	public static function init(): void {
		if ( ! apply_filters( 'zdz_fb_single_credential', true ) ) {
			return;
		}
		// Only ever engage on a COMPLETE canonical set. A half-populated master silently
		// blanking an app's credentials would be a far worse failure than the one fixed.
		if ( ! self::master_is_usable() ) {
			return;
		}
		// A write to Core's own rows (the token service's projection, a save on
		// Credentials) must be what the views serve for the rest of this request.
		add_action( 'updated_option', function ( $name ) {
			if ( 0 === strpos( (string) $name, self::MASTER . 'fb_' ) ) {
				self::reset_memo();
			}
		}, 10, 1 );
		foreach ( array_keys( self::views() ) as $prefix ) {
			foreach ( self::KEYS as $key ) {
				$opt = $prefix . $key;
				add_filter( 'pre_option_' . $opt, function ( $pre ) use ( $key ) {
					$v = self::canonical( $key );
					return ( '' === $v ) ? $pre : $v;
				}, 10, 1 );
				// An app that completes its own sign-in writes its own family. Promote the
				// connection itself (tokens, account) into Core's row instead of letting it
				// become another opinion. The app's id and secret are managed on
				// Zorderz → Credentials and are never copied from an app's family.
				// WordPress fires "update_option_{$option}" ( $old, $new, $option ) and
				// "add_option_{$option}" ( $option, $value ).
				if ( ! in_array( $key, array( 'fb_access_token', 'fb_refresh_token', 'fb_account_id' ), true ) ) {
					continue;
				}
				add_action( 'update_option_' . $opt, function ( $old, $new ) use ( $key ) {
					self::adopt( $key, is_scalar( $new ) ? (string) $new : '' );
				}, 10, 2 );
				add_action( 'add_option_' . $opt, function ( $name, $new ) use ( $key ) {
					self::adopt( $key, is_scalar( $new ) ? (string) $new : '' );
				}, 10, 2 );
			}
		}
	}

	/* =====================================================================
	 * THE CANONICAL VALUE
	 * ===================================================================== */

	/**
	 * Core's value, read straight from the master row.
	 *
	 * Never filtered: MASTER is never a view, so this cannot recurse through the
	 * pre_option filters registered above. The client secret is returned as stored; every
	 * other key is returned decoded (Core seals tokens at rest, and the apps read them
	 * plain).
	 */
	public static function canonical( string $key ): string {
		if ( ! array_key_exists( $key, self::$memo ) ) {
			$raw = get_option( self::MASTER . $key, '' );
			$raw = is_scalar( $raw ) ? (string) $raw : '';
			if ( 'fb_client_secret' !== $key && '' !== $raw && class_exists( 'ZDZ_Core_Crypto' ) ) {
				$raw = ZDZ_Core_Crypto::plain( $raw );
			}
			self::$memo[ $key ] = $raw;
		}
		return self::$memo[ $key ];
	}

	/** Forget this request's memo (after a write to the master row). */
	public static function reset_memo(): void {
		self::$memo = array();
	}

	/** Is the master set complete enough to serve every reader? */
	public static function master_is_usable(): bool {
		// account_id is detected on the first call and legitimately absent on a fresh
		// install, so it is not required. The other four are.
		foreach ( array( 'fb_client_id', 'fb_client_secret', 'fb_access_token', 'fb_refresh_token' ) as $k ) {
			if ( '' === self::canonical( $k ) ) {
				return false;
			}
		}
		return true;
	}

	/** Promote a value written to a view into the canonical slot. */
	private static function adopt( string $key, string $value ): void {
		if ( self::$writing || '' === $value ) {
			return;
		}
		if ( $value === self::canonical( $key ) ) {
			return; // already what we hold
		}
		self::$writing = true;
		update_option( self::MASTER . $key, $value );
		self::$memo[ $key ] = $value;
		self::$writing      = false;
	}

	/* =====================================================================
	 * ONE-TIME CONSOLIDATION
	 * ===================================================================== */

	/**
	 * Elect a surviving FreshBooks app and seed Core's set from it.
	 *
	 * Runs once. Chooses by MAJORITY: whichever client id the most families already
	 * declare is the one the platform is really using, and switching to it disturbs the
	 * fewest apps. A family with no client id is not a vote: it inherits.
	 *
	 * Deliberately refuses to guess:
	 *   - no winner, or a tie: it does nothing and says so;
	 *   - the winning group must agree with itself on the refresh token, or it does
	 *     nothing and says so;
	 *   - it never fabricates a credential, only copies a complete existing set.
	 */
	public static function consolidate(): void {
		if ( get_option( self::DONE ) ) {
			return;
		}
		$plain = static function ( $v ) {
			$v = is_scalar( $v ) ? (string) $v : '';
			return ( '' !== $v && class_exists( 'ZDZ_Core_Crypto' ) ) ? ZDZ_Core_Crypto::plain( $v ) : $v;
		};

		$votes = array();
		$rows  = array();
		foreach ( array_merge( array( self::MASTER ), array_keys( self::views() ) ) as $prefix ) {
			$cid = $plain( get_option( $prefix . 'fb_client_id', '' ) );
			if ( '' === $cid ) {
				continue;
			}
			$votes[ $cid ]           = ( $votes[ $cid ] ?? 0 ) + 1;
			$rows[ $cid ][ $prefix ] = array(
				'fb_client_id'     => (string) get_option( $prefix . 'fb_client_id', '' ),
				'fb_client_secret' => (string) get_option( $prefix . 'fb_client_secret', '' ),
				'fb_access_token'  => (string) get_option( $prefix . 'fb_access_token', '' ),
				'fb_refresh_token' => (string) get_option( $prefix . 'fb_refresh_token', '' ),
				'fb_account_id'    => (string) get_option( $prefix . 'fb_account_id', '' ),
			);
		}

		if ( empty( $votes ) ) {
			update_option( self::DONE, 'no_credentials', true );
			return;
		}

		// A single app is the NORMAL case, not an early exit: a clean single-app install
		// must still get its canonical set seeded, or the read-through never engages.
		arsort( $votes );
		$ids = array_keys( $votes );
		if ( count( $ids ) > 1 && $votes[ $ids[0] ] === $votes[ $ids[1] ] ) {
			error_log( 'Zorderz billing credential: consolidation declined, no majority app ('
				. implode( ', ', array_map( function ( $i ) use ( $votes ) {
					return substr( (string) $i, 0, 8 ) . '...=' . $votes[ $i ];
				}, $ids ) ) . '). A person must choose on Zorderz → Credentials.' );
			update_option( self::DONE, 'declined_tie', true );
			return;
		}

		$winner = $ids[0];
		$group  = $rows[ $winner ];

		$tokens = array();
		foreach ( $group as $r ) {
			$rt = $plain( $r['fb_refresh_token'] );
			if ( '' !== $rt ) {
				$tokens[ $rt ] = true;
			}
		}
		if ( 1 !== count( $tokens ) ) {
			error_log( 'Zorderz billing credential: consolidation declined, the winning app ' . substr( (string) $winner, 0, 8 )
				. '... has ' . count( $tokens ) . ' different refresh tokens across its families.' );
			update_option( self::DONE, 'declined_ambiguous_token', true );
			return;
		}

		// Take the most complete member as the template.
		$best       = null;
		$best_score = -1;
		foreach ( $group as $prefix => $r ) {
			$score = 0;
			foreach ( self::KEYS as $k ) {
				if ( '' !== $r[ $k ] ) {
					$score++;
				}
			}
			if ( $score > $best_score ) {
				$best_score = $score;
				$best       = array( $prefix, $r );
			}
		}
		if ( ! $best || $best_score < 4 ) {
			update_option( self::DONE, 'declined_incomplete', true );
			return;
		}
		list( $from, $set ) = $best;

		self::$writing = true;
		foreach ( self::KEYS as $k ) {
			if ( '' === $set[ $k ] || self::MASTER === $from ) {
				continue;
			}
			// Written decoded: Core seals its own secrets on save, and every reader of the
			// master (ZDZ_Core_Settings::get_credential()) decodes any format. Copying an
			// app's ciphertext as it stands could have it sealed a second time.
			update_option( self::MASTER . $k, $plain( $set[ $k ] ) );
		}
		self::$writing = false;
		self::reset_memo();

		update_option( self::DONE, 'consolidated_from_' . $from, true );
		error_log( sprintf(
			'Zorderz billing credential: consolidated onto app %s... (declared by %d famil%s), seeded from %s.',
			substr( (string) $winner, 0, 8 ),
			$votes[ $winner ],
			1 === $votes[ $winner ] ? 'y' : 'ies',
			$from
		) );
	}
}
