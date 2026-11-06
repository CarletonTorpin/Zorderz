<?php
/**
 * ZDZ_Core_Credentials — one key per service, written once, read by every app in the
 * format that app understands. Zorderz → Credentials.
 *
 * THE PROBLEM
 * -----------
 * Several apps keep their own copy of the same service key (the Ai gateway key, the CRM
 * login, the billing app's client secret, a Microsoft app's secret …), each in its own
 * option and its own at-rest format. Every app reads its OWN copy first, so "the shared
 * key" is whichever copy each app happens to hit, and rotating a key means finding every
 * copy. A copy written in a format its app cannot decode fails as a silent 401.
 *
 * WHAT THIS DOES
 * --------------
 * services() lists every service and every place each one is stored, with the at-rest
 * format each reader decodes (ZDZ_Core_Crypto: plain, fixed-IV, pair-IV).
 *
 * Key services (Ai gateway, CRM): the screen lists every distinct stored key by
 * fingerprint and offers "Use this one". Then, once:
 *   1. every copy row (or the one field of an array option) is backed up, byte for
 *      byte, into zdz_core_cred_backup_<service> (autoload off);
 *   2. the chosen value is written to Core's own row and into every copy that exists,
 *      each in its reader's format (a copy whose app has no fallback to Core is created
 *      when that app is loaded, so the app gets the key too);
 *   3. from then on any other write to those rows is refused, with a notice that says
 *      where the key is managed.
 * "Undo" puts every backed-up row back exactly and stops refusing writes.
 *
 * App credentials (billing app, Anthropic, Google calendar, the Microsoft 365 apps,
 * Stripe, the vulnerability-feed key): nothing to choose between. "Manage here" adopts
 * what the apps hold now; "Save for every app" writes new values; both take the same
 * backup first and the same guards after.
 *
 * Copies are written STRAIGHT TO THE ROW ($wpdb), never through update_option(): on
 * admin-post.php every app's register_setting() sanitize callback is live, and a callback
 * that encrypts whatever it is given would store an already-encoded value encrypted
 * twice; an app's own write guard could refuse a restore. Every write and every restore
 * is read back from the table and compared byte for byte; the backup is deleted only when
 * every row matches.
 *
 * Retire: once a key is chosen and the backup of the old keys is deleted, the copies
 * whose apps fall back to Core's row can be deleted ("retired"), leaving one stored key.
 * Copies read by an app with no fallback stay (and stay guarded).
 *
 * No key, secret or token is ever printed: the screen shows fingerprints (first 8 hex of
 * SHA-256) and lengths, and every input is a write-only field.
 *
 * Kill switch: add_filter( 'zdz_single_credentials', '__return_false' ) stops the write
 * guards and the billing secret re-encoding (rows stay as they were last written).
 *
 * Encryption keys: everything encrypted is keyed off wp_salt('auth'). Rotating AUTH_KEY /
 * AUTH_SALT makes every encrypted copy unreadable; save the keys here again afterwards.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Core_Credentials {

	const STATE   = 'zdz_core_cred_state';    // [ service => [ fp, at, by, retired? ] ]
	const BACKUP  = 'zdz_core_cred_backup_';  // + service: [ row => raw|null ]
	const REFUSED = 'zdz_core_cred_refused_'; // + user id (transient): rows a write was refused for
	const MSG     = 'zdz_core_cred_msg_';     // + user id (transient): the last action's result
	const PAGE    = 'zdz-core-credentials';

	/** Anthropic's Models API: one free call answers whether a key works (and which workspace it is in). */
	const ANTHROPIC_MODELS = 'https://api.anthropic.com/v1/models';

	/** The key services whose app copies can be retired. */
	const RETIRABLE = array( 'poe', 'nutshell' );

	/**
	 * The services this screen manages, and every place each one is stored.
	 *
	 * copies: field => [ option name => [ format, read by, 'field' => array key when the
	 * value lives inside an array option, 'fallback' => true when the reader falls back
	 * to Core's row once its own copy is gone, 'app' => a constant the reading app
	 * defines when it is loaded ] ]. Order fixes the K1, K2 … labels.
	 *
	 * Each format below was confirmed against the reading app's own code:
	 *   Core rows (zdz_core_*): secrets sealed fixed-IV by ZDZ_Core_Settings; logins and
	 *     ids plain. get_credential() decodes any format.
	 *   Knowledge Vault zkv_poe_api_key: fixed-IV (ZKV_Dashboard::encrypt_key); falls back
	 *     to Core.
	 *   Leads zl_poe_api_key, zl_ns_api_key, zl_fb_client_secret: fixed-IV
	 *     (ZL_Admin::encrypt / decrypt_shared); zl_ns_email, zl_fb_client_id plain. Leads
	 *     reads only its own rows, so its copies are never retired.
	 *   Scheduler zsch_graph_secret, zsch_google_secret, zsch_ms_delegated_secret; Inbox
	 *     zib_ms_secret; Knowledge Vault zkv_mail_secret: plain, as typed (their sealed
	 *     vaults hold each person's own sign-in tokens, which this screen never touches).
	 *     Their tenant and client ids live in array options, read plain.
	 *   Invoices zic_stripe_*: plain.
	 *
	 * Kernel services and apps add their own entries on zdz_core_credentials_services
	 * (see registered()).
	 */
	public static function services(): array {
		$P = ZDZ_Core_Crypto::PLAIN;
		$F = ZDZ_Core_Crypto::FIXED;
		return self::registered( array(
			'poe'      => array(
				'label'     => __( 'Ai gateway (Poe)', 'zorderz' ),
				'canonical' => array( 'api_key' => 'zdz_core_poe_api_key' ),
				'copies'    => array(
					'api_key' => array(
						'zdz_core_poe_api_key' => array( $F, __( 'Zorderz Core: every app that asks Core for the key', 'zorderz' ) ),
						'zkv_poe_api_key'      => array( $F, __( 'Knowledge Vault', 'zorderz' ), 'fallback' => true, 'app' => 'ZKV_VERSION' ),
						'zl_poe_api_key'       => array( $F, __( 'Leads', 'zorderz' ), 'fallback' => false, 'app' => 'ZL_VERSION' ),
					),
				),
			),
			'nutshell' => array(
				'label'     => __( 'CRM (Nutshell)', 'zorderz' ),
				'canonical' => array( 'email' => 'zdz_core_ns_email', 'api_key' => 'zdz_core_ns_api_key' ),
				'copies'    => array(
					'email'   => array(
						'zdz_core_ns_email' => array( $P, __( 'Zorderz Core: Jobs, Estimate, Prep, Receipts, Surveys', 'zorderz' ) ),
						'zl_ns_email'       => array( $P, __( 'Leads (lead generator, write-back)', 'zorderz' ), 'fallback' => false, 'app' => 'ZL_VERSION' ),
					),
					'api_key' => array(
						'zdz_core_ns_api_key' => array( $F, __( 'Zorderz Core: Jobs, Estimate, Prep, Receipts, Surveys', 'zorderz' ) ),
						'zl_ns_api_key'       => array( $F, __( 'Leads (lead generator, write-back)', 'zorderz' ), 'fallback' => false, 'app' => 'ZL_VERSION' ),
					),
				),
				'key_field' => 'api_key',
			),
		) + self::app_services() );
	}

	/**
	 * $built plus the entries added on filter zdz_core_credentials_services( $services ).
	 *
	 * Append-only: an added entry whose id is taken is ignored, so a filter can never
	 * replace, change or remove a service already listed. An added entry must be a whole
	 * app entry (kind 'app', label, group, fields; a canonical row and copies in a known
	 * format for every field), and none of its rows may be a row another service already
	 * stores in, so "Save for every app" on it can never write over another key.
	 */
	private static function registered( array $built ): array {
		$added = function_exists( 'apply_filters' ) ? apply_filters( 'zdz_core_credentials_services', $built ) : $built;
		if ( ! is_array( $added ) ) {
			return $built;
		}
		$taken = array();
		foreach ( $built as $def ) {
			$taken += self::rows_of( $def );
		}
		foreach ( $added as $svc => $def ) {
			if ( ! is_string( $svc ) || isset( $built[ $svc ] ) || 1 !== preg_match( '/^[a-z0-9_]{1,40}$/', $svc ) || ! self::valid_registered( $def ) ) {
				continue;
			}
			$rows = self::rows_of( $def );
			if ( array_intersect_key( $rows, $taken ) ) {
				continue;
			}
			$built[ $svc ] = $def;
			$taken        += $rows;
		}
		return $built;
	}

	/** Every option row an entry stores in: [ option name => true ]. */
	private static function rows_of( $def ): array {
		$rows = array();
		if ( ! is_array( $def ) ) {
			return $rows;
		}
		foreach ( (array) ( $def['canonical'] ?? array() ) as $name ) {
			if ( is_string( $name ) ) {
				$rows[ $name ] = true;
			}
		}
		foreach ( (array) ( $def['copies'] ?? array() ) as $copies ) {
			foreach ( array_keys( (array) $copies ) as $name ) {
				$rows[ (string) $name ] = true;
			}
		}
		return $rows;
	}

	/** Is $def a whole app entry this screen can draw, save, guard and undo? */
	private static function valid_registered( $def ): bool {
		if ( ! is_array( $def ) || 'app' !== ( $def['kind'] ?? '' ) || ! is_string( $def['label'] ?? null ) || ! is_string( $def['group'] ?? null )
			|| ! is_array( $def['fields'] ?? null ) || ! $def['fields'] || ! is_array( $def['canonical'] ?? null ) || ! is_array( $def['copies'] ?? null ) ) {
			return false;
		}
		$formats = array( ZDZ_Core_Crypto::PLAIN, ZDZ_Core_Crypto::FIXED, ZDZ_Core_Crypto::PAIR );
		foreach ( $def['fields'] as $field => $fm ) {
			if ( ! is_string( $field ) || ! is_array( $fm ) || ! is_string( $fm[0] ?? null ) || ! is_bool( $fm[1] ?? null )
				|| ! is_string( $def['canonical'][ $field ] ?? null ) || 1 !== preg_match( '/^[a-z0-9_]{1,64}$/', $def['canonical'][ $field ] )
				|| ! is_array( $def['copies'][ $field ] ?? null ) || ! $def['copies'][ $field ] ) {
				return false;
			}
			foreach ( $def['copies'][ $field ] as $name => $meta ) {
				if ( ! is_string( $name ) || 1 !== preg_match( '/^[a-z0-9_]{1,64}$/', $name ) || ! is_array( $meta ) || ! in_array( $meta[0] ?? null, $formats, true ) || ! is_string( $meta[1] ?? null ) ) {
					return false;
				}
			}
		}
		foreach ( (array) ( $def['required'] ?? array() ) as $field ) {
			if ( ! isset( $def['fields'][ $field ] ) ) {
				return false;
			}
		}
		return count( $def['canonical'] ) === count( $def['fields'] ) && count( $def['copies'] ) === count( $def['fields'] );
	}

	/**
	 * App credentials: an OAuth app's id + secret (or a provider's key set) registered
	 * once. Unlike the key services there is nothing to choose between: "Manage here"
	 * adopts what the apps already hold, and from then on this screen is the one place to
	 * change it. Each copy row is written in the format its app reads.
	 *
	 * kind 'app': fields => [ field => [ label, secret? ] ]; every field optional unless
	 * listed in 'required'; 'create_copies' => true lets a save create a copy row (or
	 * array field) the app has not created yet — the app reads nothing else. Separate
	 * entries stay separate on purpose: each is its own registration with its own
	 * permissions.
	 */
	public static function app_services(): array {
		$P = ZDZ_Core_Crypto::PLAIN;
		$F = ZDZ_Core_Crypto::FIXED;
		$services = array(
			'anthropic'        => array(
				'kind'          => 'app',
				'label'         => __( 'Anthropic API', 'zorderz' ),
				'group'         => 'Anthropic',
				'note'          => __( 'The API key from the Anthropic Console. Type it here yourself: never paste it into a chat or a document. Saving checks it with Anthropic first (one free Models API call). When a workspace ID is set, a key is kept only if Anthropic says it belongs to that workspace; left empty, any working key is kept. Only the site\'s own server code reads the key.', 'zorderz' ),
				'fields'        => array(
					'api_key'      => array( __( 'API key', 'zorderz' ), true ),
					'workspace_id' => array( __( 'Workspace ID (optional)', 'zorderz' ), false ),
				),
				'required'      => array( 'api_key' ),
				'canonical'     => array( 'api_key' => 'zdz_core_anthropic_api_key', 'workspace_id' => 'zdz_core_anthropic_workspace_id' ),
				'create_copies' => false,
				'test'          => 'anthropic',
				'copies'        => array(
					'api_key'      => array( 'zdz_core_anthropic_api_key' => array( $F, __( 'server code only', 'zorderz' ) ) ),
					'workspace_id' => array( 'zdz_core_anthropic_workspace_id' => array( $P, __( 'the key check', 'zorderz' ) ) ),
				),
			),
			'freshbooks'       => array(
				'kind'          => 'app',
				'label'         => __( 'Billing app (FreshBooks)', 'zorderz' ),
				'group'         => __( 'Billing', 'zorderz' ),
				'note'          => __( 'The one FreshBooks app every billing-connected app signs in through. Tokens are refreshed by the token service and are not edited here. An app set up on its own FreshBooks app (Invoices) keeps its own settings.', 'zorderz' ),
				'fields'        => array(
					'client_id'     => array( __( 'Client ID', 'zorderz' ), false ),
					'client_secret' => array( __( 'Client secret', 'zorderz' ), true ),
				),
				'required'      => array( 'client_id', 'client_secret' ),
				'canonical'     => array( 'client_id' => 'zdz_core_fb_client_id', 'client_secret' => 'zdz_core_fb_client_secret' ),
				'create_copies' => false, // only rows an app already uses; the billing views serve the rest
				'copies'        => array(
					'client_id'     => array(
						'zdz_core_fb_client_id' => array( $P, __( 'Zorderz Core (the token service, Estimate, Commission, Stock, Receipts)', 'zorderz' ) ),
						'zl_fb_client_id'       => array( $P, __( 'Leads', 'zorderz' ) ),
					),
					'client_secret' => array(
						'zdz_core_fb_client_secret' => array( $F, __( 'Zorderz Core (the token service, Estimate, Commission, Stock, Receipts)', 'zorderz' ) ),
						'zl_fb_client_secret'       => array( $F, __( 'Leads', 'zorderz' ) ),
					),
				),
			),
			'google_calendar'  => array(
				'kind'          => 'app',
				'label'         => __( 'Google Calendar (connected calendars)', 'zorderz' ),
				'group'         => 'Google',
				'note'          => __( 'The Google OAuth client people use to connect their own calendars in Scheduler. Each person\'s connection stays their own.', 'zorderz' ),
				'fields'        => array(
					'client_id'     => array( __( 'OAuth client ID', 'zorderz' ), false ),
					'client_secret' => array( __( 'OAuth client secret', 'zorderz' ), true ),
				),
				'canonical'     => array( 'client_id' => 'zdz_core_google_cal_client_id', 'client_secret' => 'zdz_core_google_cal_client_secret' ),
				'create_copies' => true,
				'copies'        => array(
					'client_id'     => array( 'zsch_conncal_config' => array( $P, __( 'Scheduler', 'zorderz' ), 'field' => 'google_client_id' ) ),
					'client_secret' => array( 'zsch_google_secret' => array( $P, __( 'Scheduler', 'zorderz' ) ) ),
				),
			),
			'ms_calendars'     => array(
				'kind'          => 'app',
				'label'         => __( 'Microsoft 365: connected calendars (Scheduler, delegated)', 'zorderz' ),
				'group'         => 'Microsoft 365',
				'note'          => __( 'The Azure app people sign in to when they connect their own Outlook calendar.', 'zorderz' ),
				'fields'        => array(
					'client_id'     => array( __( 'Application (client) ID', 'zorderz' ), false ),
					'client_secret' => array( __( 'Client secret', 'zorderz' ), true ),
				),
				'canonical'     => array( 'client_id' => 'zdz_core_ms_cal_client_id', 'client_secret' => 'zdz_core_ms_cal_client_secret' ),
				'create_copies' => true,
				'copies'        => array(
					'client_id'     => array( 'zsch_conncal_config' => array( $P, __( 'Scheduler', 'zorderz' ), 'field' => 'ms_client_id' ) ),
					'client_secret' => array( 'zsch_ms_delegated_secret' => array( $P, __( 'Scheduler', 'zorderz' ) ) ),
				),
			),
			'ms_calendar_sync' => array(
				'kind'          => 'app',
				'label'         => __( 'Microsoft 365: company calendar sync (Scheduler, app-only)', 'zorderz' ),
				'group'         => 'Microsoft 365',
				'note'          => __( 'Needs an Azure app with the application permission Calendars.ReadWrite and admin consent.', 'zorderz' ),
				'fields'        => array(
					'tenant_id'     => array( __( 'Directory (tenant) ID', 'zorderz' ), false ),
					'client_id'     => array( __( 'Application (client) ID', 'zorderz' ), false ),
					'client_secret' => array( __( 'Client secret', 'zorderz' ), true ),
				),
				'canonical'     => array( 'tenant_id' => 'zdz_core_ms_sync_tenant_id', 'client_id' => 'zdz_core_ms_sync_client_id', 'client_secret' => 'zdz_core_ms_sync_client_secret' ),
				'create_copies' => true,
				'copies'        => array(
					'tenant_id'     => array( 'zsch_graph_config' => array( $P, __( 'Scheduler', 'zorderz' ), 'field' => 'tenant_id' ) ),
					'client_id'     => array( 'zsch_graph_config' => array( $P, __( 'Scheduler', 'zorderz' ), 'field' => 'client_id' ) ),
					'client_secret' => array( 'zsch_graph_secret' => array( $P, __( 'Scheduler', 'zorderz' ) ) ),
				),
			),
			'ms_inbox'         => array(
				'kind'          => 'app',
				'label'         => __( 'Microsoft 365: Inbox (delegated)', 'zorderz' ),
				'group'         => 'Microsoft 365',
				'note'          => __( 'The Azure app the Inbox signs in with (mail read and send).', 'zorderz' ),
				'fields'        => array(
					'tenant_id'     => array( __( 'Directory (tenant) ID', 'zorderz' ), false ),
					'client_id'     => array( __( 'Application (client) ID', 'zorderz' ), false ),
					'client_secret' => array( __( 'Client secret', 'zorderz' ), true ),
				),
				'canonical'     => array( 'tenant_id' => 'zdz_core_ms_inbox_tenant_id', 'client_id' => 'zdz_core_ms_inbox_client_id', 'client_secret' => 'zdz_core_ms_inbox_client_secret' ),
				'create_copies' => true,
				'copies'        => array(
					'tenant_id'     => array( 'zib_config' => array( $P, __( 'Inbox', 'zorderz' ), 'field' => 'tenant_id' ) ),
					'client_id'     => array( 'zib_config' => array( $P, __( 'Inbox', 'zorderz' ), 'field' => 'client_id' ) ),
					'client_secret' => array( 'zib_ms_secret' => array( $P, __( 'Inbox', 'zorderz' ) ) ),
				),
			),
			'ms_vault_mail'    => array(
				'kind'          => 'app',
				'label'         => __( 'Microsoft 365: Knowledge Vault mail-in (app-only)', 'zorderz' ),
				'group'         => 'Microsoft 365',
				'note'          => __( 'The Azure app the Knowledge Vault reads its mail-in box with.', 'zorderz' ),
				'fields'        => array(
					'tenant_id'     => array( __( 'Directory (tenant) ID', 'zorderz' ), false ),
					'client_id'     => array( __( 'Application (client) ID', 'zorderz' ), false ),
					'client_secret' => array( __( 'Client secret', 'zorderz' ), true ),
				),
				'canonical'     => array( 'tenant_id' => 'zdz_core_ms_vault_tenant_id', 'client_id' => 'zdz_core_ms_vault_client_id', 'client_secret' => 'zdz_core_ms_vault_client_secret' ),
				'create_copies' => true,
				'copies'        => array(
					'tenant_id'     => array( 'zkv_mail_config' => array( $P, __( 'Knowledge Vault', 'zorderz' ), 'field' => 'tenant_id' ) ),
					'client_id'     => array( 'zkv_mail_config' => array( $P, __( 'Knowledge Vault', 'zorderz' ), 'field' => 'client_id' ) ),
					'client_secret' => array( 'zkv_mail_secret' => array( $P, __( 'Knowledge Vault', 'zorderz' ) ) ),
				),
			),
			'stripe'           => array(
				'kind'          => 'app',
				'label'         => __( 'Stripe (Invoices)', 'zorderz' ),
				'group'         => 'Stripe',
				'note'          => __( 'The keys Invoices takes card payments with. The webhook signing secret comes from the Stripe dashboard endpoint for this site.', 'zorderz' ),
				'fields'        => array(
					'publishable'       => array( __( 'Publishable key', 'zorderz' ), false ),
					'secret'            => array( __( 'Secret key', 'zorderz' ), true ),
					'webhook_secret'    => array( __( 'Webhook signing secret', 'zorderz' ), true ),
					'connected_account' => array( __( 'Connected account ID', 'zorderz' ), false ),
				),
				'canonical'     => array(
					'publishable'       => 'zdz_core_stripe_publishable',
					'secret'            => 'zdz_core_stripe_secret',
					'webhook_secret'    => 'zdz_core_stripe_webhook_secret',
					'connected_account' => 'zdz_core_stripe_connected_account',
				),
				'create_copies' => true,
				'copies'        => array(
					'publishable'       => array( 'zic_stripe_publishable' => array( $P, __( 'Invoices', 'zorderz' ) ) ),
					'secret'            => array( 'zic_stripe_secret' => array( $P, __( 'Invoices', 'zorderz' ) ) ),
					'webhook_secret'    => array( 'zic_stripe_webhook_secret' => array( $P, __( 'Invoices', 'zorderz' ) ) ),
					'connected_account' => array( 'zic_stripe_connected_account' => array( $P, __( 'Invoices', 'zorderz' ) ) ),
				),
			),
			// One row, written only here; the Security Monitor app reads it with
			// ZDZ_Core_Settings::get_credential( 'wordfence_feed_key' ). Listed only when that app is installed.
			'wordfence'        => array(
				'kind'          => 'app',
				'label'         => __( 'Wordfence vulnerability feed', 'zorderz' ),
				'group'         => 'Wordfence',
				'note'          => __( 'The Wordfence Intelligence API key the Security Monitor app downloads the plugin and theme vulnerability list with. It is sent only to Wordfence, in a request header.', 'zorderz' ),
				'fields'        => array( 'feed_key' => array( __( 'Wordfence Intelligence API key', 'zorderz' ), true ) ),
				'required'      => array( 'feed_key' ),
				'canonical'     => array( 'feed_key' => 'zdz_core_wordfence_feed_key' ),
				'create_copies' => false,
				'copies'        => array(
					'feed_key' => array( 'zdz_core_wordfence_feed_key' => array( $F, __( 'security monitor (server only)', 'zorderz' ) ) ),
				),
			),
		);
		// The vulnerability-feed row only matters when the security monitor app is installed.
		if ( ! defined( 'ZSM_DIR' ) ) {
			unset( $services['wordfence'] );
		}
		return $services;
	}

	/** Is $svc an app-credential service? */
	public static function is_app( string $svc ): bool {
		return 'app' === ( self::services()[ $svc ]['kind'] ?? '' );
	}

	/** Where apps point people: the one screen. */
	public static function page_url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/** Is the single-credential layer on? (The kill switch turns guards and re-encoding off.) */
	public static function active(): bool {
		return (bool) apply_filters( 'zdz_single_credentials', true );
	}

	/**
	 * The value every app should have for each field of $svc right now: Core's own row
	 * when it holds one, else the first copy that does (in services() order).
	 *
	 * @return array<string,string> field => plaintext ('' when nowhere)
	 */
	public static function current_values( string $svc ): array {
		$def = self::services()[ $svc ] ?? null;
		if ( ! $def ) {
			return array();
		}
		$out = array();
		foreach ( $def['canonical'] as $field => $row ) {
			$v = self::deep_plain( (string) self::db_get( $row ) );
			if ( '' === $v ) {
				foreach ( $def['copies'][ $field ] ?? array() as $name => $meta ) {
					$v = self::stored_plain( self::db_get( $name ), $meta );
					if ( '' !== $v ) {
						break;
					}
				}
			}
			$out[ $field ] = $v;
		}
		return $out;
	}

	/* =====================================================================
	 * BOOT
	 * ===================================================================== */

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ), 20 );
		foreach ( array( 'use', 'set', 'undo', 'forget', 'app', 'manage', 'fb_connect', 'retire', 'unretire', 'test' ) as $a ) {
			add_action( 'admin_post_zdz_cred_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
		add_action( 'admin_notices', array( __CLASS__, 'refused_notice' ) );
		add_filter( 'zdz_export_secret_option_names', array( __CLASS__, 'export_secret_names' ) );
		add_filter( 'zdz_export_non_portable_option_patterns', array( __CLASS__, 'export_non_portable' ) );

		// The guards and the billing re-encoding are armed as soon as the theme is set up:
		// before any request-time read or settings save (admin_init, REST, AJAX, cron), and
		// late enough that the service labels may be translated.
		if ( function_exists( 'did_action' ) && did_action( 'after_setup_theme' ) ) {
			self::arm();
		} else {
			add_action( 'after_setup_theme', array( __CLASS__, 'arm' ), 1 );
		}
	}

	/** Register the write guards and the billing secret re-encoding (once; not when switched off). */
	public static function arm(): void {
		static $armed = false;
		if ( $armed || ! self::active() ) {
			return;
		}
		$armed = true;
		self::register_guards();
		self::register_fb_secret_formats();
	}

	/** A data export never carries an Undo backup: it holds the old keys. */
	public static function export_secret_names( $names ): array {
		$names = is_array( $names ) ? $names : array();
		foreach ( array_keys( self::services() ) as $svc ) {
			$names[] = self::BACKUP . $svc;
		}
		return $names;
	}

	/**
	 * The screen's own bookkeeping belongs to one install: imported onto another it would
	 * guard rows that were never written there.
	 */
	public static function export_non_portable( $patterns ): array {
		$patterns   = is_array( $patterns ) ? $patterns : array();
		$patterns[] = '/^zdz_core_cred_(state|backup_|refused_|msg_)/';
		return $patterns;
	}

	/**
	 * Every row a write guard covers right now: [ [ option, array field|'' ], … ] for each
	 * engaged service. Empty when the kill switch is off.
	 *
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function guard_plan(): array {
		if ( ! self::active() ) {
			return array();
		}
		$state = self::state();
		$out   = array();
		foreach ( self::services() as $svc => $def ) {
			if ( empty( $state[ $svc ]['fp'] ) ) {
				continue;
			}
			foreach ( $def['copies'] as $rows ) {
				foreach ( $rows as $name => $meta ) {
					$out[] = array( $name, (string) ( $meta['field'] ?? '' ) );
				}
			}
		}
		return $out;
	}

	/** Refuse writes to every row of an engaged service, except our own. */
	private static function register_guards(): void {
		foreach ( self::guard_plan() as $g ) {
			list( $name, $sub ) = $g;
			if ( '' !== $sub ) {
				add_filter( 'pre_update_option_' . $name, function ( $value, $old ) use ( $name, $sub ) {
					return self::guard_field( $name, $sub, $value, $old );
				}, PHP_INT_MAX, 2 );
				continue;
			}
			add_filter( 'pre_update_option_' . $name, function ( $value, $old ) use ( $name ) {
				return self::guard_value( $name, $value, $old );
			}, PHP_INT_MAX, 2 );
		}
	}

	/**
	 * The guard on a whole row: any other writer's change is refused (the stored value is
	 * kept) and noted for the notice. A screen re-saving the SAME key — an app that
	 * re-encrypts with a new IV on every save — is not a change: kept silently.
	 */
	public static function guard_value( string $name, $value, $old ) {
		if ( $value === $old ) {
			return $value;
		}
		$same = is_scalar( $value ) && is_scalar( $old )
			&& '' !== self::deep_plain( (string) $old )
			&& self::deep_plain( (string) $value ) === self::deep_plain( (string) $old );
		if ( ! $same ) {
			self::note_refused( $name );
		}
		return $old;
	}

	/** The guard on one field of an array option: the rest of the array saves as usual, this field is pinned. */
	public static function guard_field( string $name, string $sub, $value, $old ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$keep = is_array( $old ) && isset( $old[ $sub ] ) ? $old[ $sub ] : '';
		if ( ( $value[ $sub ] ?? '' ) !== $keep ) {
			self::note_refused( $name . '[' . $sub . ']' );
			$value[ $sub ] = $keep;
		}
		return $value;
	}

	/**
	 * The billing client secret: re-encoded on the way out of each billing view into the
	 * format that view's reader decodes (ZDZ_Core_FB_Credentials::views()).
	 *
	 * Runs after ZDZ_Core_FB_Credentials' own view (priority 10). When that view is not
	 * engaged it passes false and this does nothing, so a site without a complete billing
	 * credential behaves exactly as before.
	 */
	private static function register_fb_secret_formats(): void {
		if ( ! class_exists( 'ZDZ_Core_FB_Credentials' ) ) {
			return;
		}
		foreach ( ZDZ_Core_FB_Credentials::views() as $prefix => $format ) {
			add_filter( 'pre_option_' . $prefix . 'fb_client_secret', function ( $pre ) use ( $format ) {
				return self::serve_in_format( $pre, $format );
			}, 20, 1 );
		}
	}

	/** $pre as its reader decodes it: unchanged when it is already that format (or unreadable). */
	public static function serve_in_format( $pre, string $format ) {
		if ( ! is_string( $pre ) || '' === $pre ) {
			return $pre;
		}
		list( $fmt, $plain ) = ZDZ_Core_Crypto::decode( $pre );
		if ( null === $plain || '' === $plain || $fmt === $format ) {
			return $pre;
		}
		return ZDZ_Core_Crypto::encode( (string) $plain, $format );
	}

	/* =====================================================================
	 * RAW ROW ACCESS (no option filters, no sanitize callbacks, no cache)
	 * ===================================================================== */

	/** The stored option_value string, exactly as in the table, or null when the row is absent. */
	private static function db_get( string $name ): ?string {
		global $wpdb;
		$v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );
		return null === $v ? null : (string) $v;
	}

	/** Write $stored (already serialized) to the row, creating it (autoload off) when absent. */
	private static function db_put( string $name, string $stored ): bool {
		global $wpdb;
		if ( null === self::db_get( $name ) ) {
			$ok = $wpdb->insert( $wpdb->options, array( 'option_name' => $name, 'option_value' => $stored, 'autoload' => 'no' ) );
		} else {
			$ok = $wpdb->update( $wpdb->options, array( 'option_value' => $stored ), array( 'option_name' => $name ) );
		}
		self::flush( $name );
		return false !== $ok && self::db_get( $name ) === $stored;
	}

	private static function db_delete( string $name ): bool {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
		self::flush( $name );
		return null === self::db_get( $name );
	}

	/** Drop every cached copy of a row so the next get_option() reads the table. */
	private static function flush( string $name ): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $name, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
	}

	/** An array option's stored string, read as an array (never objects), or null. */
	private static function as_array( ?string $stored ): ?array {
		if ( null === $stored ) {
			return null;
		}
		$a = @unserialize( $stored, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions -- sentinel:allow unserialize with allowed_classes false, arrays only
		return is_array( $a ) ? $a : null;
	}

	/**
	 * The usable value under any number (max 3) of fixed-IV / pair-IV layers. A secret
	 * saved through a screen that encrypts whatever it is given can end up encrypted
	 * twice, and its reader, decrypting once, then sends the provider a ciphertext. Each
	 * layer is accepted only on an exact round trip to printable text
	 * (ZDZ_Core_Crypto::decode), so a real secret is never mistaken for ciphertext.
	 */
	public static function deep_plain( string $s ): string {
		$p = ZDZ_Core_Crypto::plain( $s );
		for ( $i = 0; $i < 2 && '' !== $p; $i++ ) {
			list( $f, $q ) = ZDZ_Core_Crypto::decode( $p );
			if ( ( ZDZ_Core_Crypto::FIXED !== $f && ZDZ_Core_Crypto::PAIR !== $f ) || null === $q || '' === $q ) {
				break;
			}
			$p = (string) $q;
		}
		return $p;
	}

	/**
	 * What an app reading this row with ONE decryption gets. apply() skips and verifies
	 * with this, so a twice-encrypted copy is rewritten, not taken as done.
	 */
	public static function one_layer( ?string $stored, array $meta ): string {
		if ( null === $stored ) {
			return '';
		}
		if ( isset( $meta['field'] ) ) {
			$a = self::as_array( $stored );
			return ( $a && isset( $a[ $meta['field'] ] ) && is_scalar( $a[ $meta['field'] ] ) ) ? ZDZ_Core_Crypto::plain( (string) $a[ $meta['field'] ] ) : '';
		}
		return ZDZ_Core_Crypto::plain( $stored );
	}

	/** The at-rest format of a stored row (or of one field of a serialized array), as decode() names it. */
	private static function stored_format( ?string $stored, array $meta ): string {
		if ( null === $stored ) {
			return 'empty';
		}
		if ( isset( $meta['field'] ) ) {
			$a = self::as_array( $stored );
			$stored = ( $a && isset( $a[ $meta['field'] ] ) && is_scalar( $a[ $meta['field'] ] ) ) ? (string) $a[ $meta['field'] ] : '';
		}
		return ZDZ_Core_Crypto::decode( $stored )[0];
	}

	/** Usable value of a stored row: the string itself, or one field of a serialized array. */
	public static function stored_plain( ?string $stored, array $meta ): string {
		if ( null === $stored ) {
			return '';
		}
		if ( isset( $meta['field'] ) ) {
			$a = self::as_array( $stored );
			return ( $a && isset( $a[ $meta['field'] ] ) && is_scalar( $a[ $meta['field'] ] ) ) ? self::deep_plain( (string) $a[ $meta['field'] ] ) : '';
		}
		return self::deep_plain( $stored );
	}

	/**
	 * Every copy of a service, as stored: [ [ option, field|'', format, length, fingerprint,
	 * read by ], … ] for each row that holds a value. Formats come from
	 * ZDZ_Core_Crypto::decode() on the stored string; length and fingerprint are of the
	 * usable value. Never the value itself.
	 *
	 * @return array<int,array{row:string,field:string,format:string,declared:string,len:int,fp:string,by:string}>
	 */
	public static function copy_inventory( string $svc ): array {
		$def = self::services()[ $svc ] ?? null;
		$out = array();
		if ( ! $def ) {
			return $out;
		}
		foreach ( $def['copies'] as $rows ) {
			foreach ( $rows as $name => $meta ) {
				$stored = self::db_get( $name ); // the row itself, never a view onto another
				$plain  = self::stored_plain( $stored, $meta );
				if ( '' === $plain ) {
					continue;
				}
				$out[] = array(
					'row'      => $name,
					'field'    => (string) ( $meta['field'] ?? '' ),
					'format'   => self::stored_format( $stored, $meta ),
					'declared' => (string) $meta[0],
					'len'      => strlen( $plain ),
					'fp'       => ZDZ_Core_Crypto::fingerprint( $plain ),
					'by'       => (string) $meta[1],
				);
			}
		}
		return $out;
	}

	public static function has_backup( string $svc ): bool {
		return null !== self::as_array( self::db_get( self::BACKUP . $svc ) );
	}

	/* =====================================================================
	 * STATE AND READING
	 * ===================================================================== */

	public static function state(): array {
		$s = get_option( self::STATE, array() );
		return is_array( $s ) ? $s : array();
	}

	public static function engaged( string $svc ): bool {
		$s = self::state();
		return ! empty( $s[ $svc ]['fp'] );
	}

	/** Raw stored string for a copy row (array field read out), or null when absent. */
	private static function raw( string $name, array $meta ): ?string {
		$v = get_option( $name, null );
		if ( isset( $meta['field'] ) ) {
			return ( is_array( $v ) && isset( $v[ $meta['field'] ] ) && is_scalar( $v[ $meta['field'] ] ) ) ? (string) $v[ $meta['field'] ] : null;
		}
		return ( null === $v || false === $v || ! is_scalar( $v ) ) ? null : (string) $v;
	}

	/** Is the app that reads this copy loaded on this request? (No 'app' named: assume yes.) */
	private static function app_loaded( array $meta ): bool {
		return empty( $meta['app'] ) || defined( (string) $meta['app'] );
	}

	/**
	 * Every distinct key stored for a key service.
	 *
	 * @return array<string,array{label:string,len:int,rows:array<string,string>,email:string}>
	 *         keyed by fingerprint, in K1 … order; rows: option name => stored format.
	 *         For the CRM, email is the login stored beside that key (first match).
	 */
	public static function inventory( string $svc ): array {
		$def = self::services()[ $svc ] ?? null;
		if ( ! $def || 'app' === ( $def['kind'] ?? '' ) ) {
			return array();
		}
		$kf  = $def['key_field'] ?? 'api_key';
		$out = array();
		$n   = 0;
		foreach ( $def['copies'][ $kf ] as $name => $meta ) {
			$raw = self::raw( $name, $meta );
			if ( null === $raw || '' === $raw ) {
				continue;
			}
			list( $fmt, $plain ) = ZDZ_Core_Crypto::decode( $raw );
			if ( null === $plain || '' === $plain ) {
				continue;
			}
			$fp = ZDZ_Core_Crypto::fingerprint( $plain );
			if ( ! isset( $out[ $fp ] ) ) {
				$out[ $fp ] = array( 'label' => 'K' . ( ++$n ), 'len' => strlen( $plain ), 'rows' => array(), 'email' => '' );
			}
			$out[ $fp ]['rows'][ $name ] = $fmt;
			if ( '' === $out[ $fp ]['email'] && isset( $def['copies']['email'] ) ) {
				$ename = self::sibling_email_row( $name );
				if ( $ename && isset( $def['copies']['email'][ $ename ] ) ) {
					$out[ $fp ]['email'] = trim( (string) get_option( $ename, '' ) );
				}
			}
		}
		return $out;
	}

	private static function sibling_email_row( string $key_row ): string {
		return ( '_ns_api_key' === substr( $key_row, -11 ) ) ? substr( $key_row, 0, -11 ) . '_ns_email' : '';
	}

	/** The plaintext of the key with fingerprint $fp, read from whichever row holds it. */
	private static function find_plain( string $svc, string $fp ): string {
		$def = self::services()[ $svc ];
		$kf  = $def['key_field'] ?? 'api_key';
		foreach ( $def['copies'][ $kf ] as $name => $meta ) {
			$raw = self::raw( $name, $meta );
			if ( null === $raw ) {
				continue;
			}
			$p = ZDZ_Core_Crypto::plain( $raw );
			if ( '' !== $p && hash_equals( $fp, ZDZ_Core_Crypto::fingerprint( $p ) ) ) {
				return $p;
			}
		}
		return '';
	}

	/* =====================================================================
	 * WRITING
	 * ===================================================================== */

	/**
	 * Make $values (field => plaintext) THE value of a service everywhere.
	 *
	 * Backs every copy row up first (only when no backup exists yet, so Undo always
	 * returns to the state before the FIRST change), writes Core's row and every copy in
	 * its own format, verifies each by reading it back, and records the state. Returns ''
	 * on success, else a reason (and nothing is recorded).
	 */
	public static function apply( string $svc, array $values, int $user_id = 0 ): string {
		$def = self::services()[ $svc ] ?? null;
		if ( ! $def ) {
			return __( 'unknown service', 'zorderz' );
		}
		$is_app   = 'app' === ( $def['kind'] ?? '' );
		$required = $is_app ? ( $def['required'] ?? array() ) : array_keys( $def['canonical'] );
		foreach ( $required as $field ) {
			if ( ! isset( $values[ $field ] ) || '' === trim( (string) $values[ $field ] ) ) {
				/* translators: %s: a field name */
				return sprintf( __( 'missing %s', 'zorderz' ), $field );
			}
		}
		if ( $is_app && '' === implode( '', array_map( 'trim', array_map( 'strval', $values ) ) ) ) {
			return __( 'nothing to save: every field is empty', 'zorderz' );
		}

		// A backup that exists while the service is NOT engaged is what a change that did
		// not finish leaves behind. Building on it would make a later Undo also revert
		// whatever was saved since, so Undo must be pressed first.
		if ( self::has_backup( $svc ) && ! self::engaged( $svc ) ) {
			return __( 'an earlier change did not finish; press Undo first on Zorderz → Credentials', 'zorderz' );
		}

		// 1. Back up (once): the exact stored strings, or null for an absent row.
		if ( ! self::has_backup( $svc ) ) {
			$bak = array();
			foreach ( $def['copies'] as $rows ) {
				foreach ( $rows as $name => $meta ) {
					if ( isset( $meta['field'] ) ) {
						// A setting inside an array option is backed up ON ITS OWN, so Undo puts
						// back only that field. The rest of the array (the app's other settings,
						// or another service's field in the same row) keeps whatever it holds
						// by then.
						$cur = self::db_get( $name );
						$arr = null === $cur ? array() : self::as_array( $cur );
						$has = is_array( $arr ) && array_key_exists( $meta['field'], $arr );
						$bak[ $name . '[' . $meta['field'] . ']' ] = array(
							'row'     => $name,
							'field'   => $meta['field'],
							'existed' => null !== $cur,
							'has'     => $has,
							'v'       => $has ? $arr[ $meta['field'] ] : null,
						);
						continue;
					}
					if ( ! array_key_exists( $name, $bak ) ) {
						$bak[ $name ] = self::db_get( $name );
					}
				}
			}
			foreach ( $def['canonical'] as $name ) { // Core rows a copy list may not name
				if ( ! array_key_exists( $name, $bak ) ) {
					$bak[ $name ] = self::db_get( $name );
				}
			}
			if ( ! self::db_put( self::BACKUP . $svc, serialize( $bak ) ) ) {
				return __( 'could not save the backup; nothing was changed', 'zorderz' );
			}
		}

		// 2. Write every copy that exists (and Core's own rows always), straight to the row,
		//    each in its reader's format, and read it back.
		$failed = array();
		$create = ! empty( $def['create_copies'] );
		foreach ( $def['copies'] as $field => $rows ) {
			$plain = trim( (string) ( $values[ $field ] ?? '' ) );
			if ( $is_app && '' === $plain ) {
				continue; // an app field left empty is not set anywhere (and not blanked)
			}
			// Core's own row for this field is written even when no copy lists it.
			$rows_w = $rows;
			$canon  = $def['canonical'][ $field ] ?? '';
			if ( '' !== $canon && ! isset( $rows_w[ $canon ] ) ) {
				$rows_w = array( $canon => array( ZDZ_Core_Crypto::FIXED, 'Zorderz Core' ) ) + $rows_w;
			}
			foreach ( $rows_w as $name => $meta ) {
				$is_canon = in_array( $name, $def['canonical'], true );
				$cur      = self::db_get( $name );
				// A row that is not there is created only for Core, for an app service that
				// allows it, or for an app with no fallback to Core whose code is loaded.
				$needs_row = $create || ( false === ( $meta['fallback'] ?? null ) && ! empty( $meta['app'] ) && self::app_loaded( $meta ) );
				if ( null === $cur && ! $is_canon && ! $needs_row ) {
					continue;
				}
				if ( isset( $meta['field'] ) ) {
					$arr = null === $cur ? array() : self::as_array( $cur );
					if ( ! is_array( $arr ) ) {
						$failed[] = $name; // never report "saved" past a row we could not write
						continue;
					}
					if ( isset( $arr[ $meta['field'] ] ) && is_scalar( $arr[ $meta['field'] ] ) && ZDZ_Core_Crypto::plain( (string) $arr[ $meta['field'] ] ) === $plain ) {
						continue; // already this value (one layer: what the app itself reads)
					}
					$arr[ $meta['field'] ] = ZDZ_Core_Crypto::encode( $plain, $meta[0] );
					$stored                = serialize( $arr );
				} else {
					if ( null !== $cur && self::one_layer( $cur, $meta ) === $plain && ZDZ_Core_Crypto::decode( $cur )[0] === $meta[0] ) {
						continue; // already this key, in this format
					}
					$stored = ZDZ_Core_Crypto::encode( $plain, $meta[0] );
				}
				if ( ! self::db_put( $name, $stored ) || self::one_layer( self::db_get( $name ), $meta ) !== $plain ) {
					$failed[] = $name;
				}
			}
		}

		if ( $failed ) {
			/* translators: %s: comma-separated option names */
			return sprintf( __( 'these rows did not read back the new value: %s (press Undo)', 'zorderz' ), implode( ', ', array_unique( $failed ) ) );
		}

		$state = self::state();
		$kf    = $def['key_field'] ?? 'api_key';
		if ( $is_app ) {
			$fpsrc = array();
			foreach ( $def['fields'] as $f => $fm ) {
				$fpsrc[] = $f . '=' . trim( (string) ( $values[ $f ] ?? '' ) );
			}
			$fpsrc = implode( "\n", $fpsrc );
		} else {
			$fpsrc = trim( (string) $values[ $kf ] );
		}
		$keep          = $state[ $svc ]['retired'] ?? null; // a new key keeps the copies retired
		$state[ $svc ] = array(
			'fp' => ZDZ_Core_Crypto::fingerprint( $fpsrc ),
			'at' => time(),
			'by' => $user_id,
		);
		if ( null !== $keep ) {
			$state[ $svc ]['retired'] = $keep;
		}
		update_option( self::STATE, $state, false );
		return '';
	}

	/**
	 * Put every backed-up row back exactly, and stop guarding the service.
	 *
	 * Each row is restored straight to the table and compared with the backup byte for
	 * byte. The backup is deleted only when every row matches; otherwise it stays, the
	 * rows that differ are named, and Undo can be pressed again.
	 */
	public static function undo( string $svc ): string {
		$bak = self::as_array( self::db_get( self::BACKUP . $svc ) );
		if ( null === $bak ) {
			return __( 'no backup to restore', 'zorderz' );
		}
		$failed = array();
		foreach ( $bak as $name => $stored ) {
			if ( is_array( $stored ) ) { // one field of an array option
				if ( ! self::undo_field( $stored ) ) {
					$failed[] = $name;
				}
				continue;
			}
			$ok = ( null === $stored ) ? self::db_delete( (string) $name ) : self::db_put( (string) $name, (string) $stored );
			if ( ! $ok || self::db_get( (string) $name ) !== $stored ) {
				$failed[] = $name;
			}
		}
		if ( $failed ) {
			/* translators: %s: comma-separated option names */
			return sprintf( __( 'these rows did not come back exactly: %s (the backup is kept; press Undo again)', 'zorderz' ), implode( ', ', $failed ) );
		}
		// Undo stops managing the key, and every app then reads its OWN copy again, so
		// retired copies must exist first, holding the key Undo just put back.
		if ( self::retirable( $svc ) && self::is_retired( $svc ) ) {
			$e = self::unretire( $svc );
			if ( '' !== $e ) {
				/* translators: %s: the reason */
				return sprintf( __( 'the rows came back, but %s; the backup is kept, press Undo again', 'zorderz' ), $e );
			}
		}
		$state = self::state();
		unset( $state[ $svc ] );
		update_option( self::STATE, $state, false );
		self::db_delete( self::BACKUP . $svc );
		return '';
	}

	/**
	 * Put one field of an array option back as it was (or remove it when it was not
	 * there), leaving every other field of the array as it is now. A row this change
	 * created is deleted again only when nothing else has been stored in it since.
	 */
	private static function undo_field( array $b ): bool {
		$name = (string) ( $b['row'] ?? '' );
		$f    = (string) ( $b['field'] ?? '' );
		if ( '' === $name || '' === $f ) {
			return false;
		}
		$cur = self::db_get( $name );
		$arr = null === $cur ? array() : self::as_array( $cur );
		if ( ! is_array( $arr ) ) {
			// A row that never held an array was never written by apply() (it fails
			// instead), so there is nothing of ours to take back.
			return empty( $b['has'] );
		}
		if ( ! empty( $b['has'] ) ) {
			$arr[ $f ] = $b['v'];
		} else {
			unset( $arr[ $f ] );
		}
		if ( empty( $b['existed'] ) && array() === $arr ) {
			return self::db_delete( $name );
		}
		if ( null === $cur && array() === $arr ) {
			return true;
		}
		$stored = serialize( $arr );
		if ( $stored !== $cur && ! self::db_put( $name, $stored ) ) {
			return false;
		}
		$now = self::as_array( self::db_get( $name ) );
		return is_array( $now ) && ( ! empty( $b['has'] ) ? ( array_key_exists( $f, $now ) && $now[ $f ] === $b['v'] ) : ! array_key_exists( $f, $now ) );
	}

	/**
	 * Drop the backup (Undo is no longer possible).
	 *
	 * Engaged: the administrator has checked every app on the chosen key. Not engaged: an
	 * earlier change did not finish and Undo cannot complete either; the rows stay as they
	 * are now, and a key can be chosen again (otherwise the service could stay stuck).
	 */
	public static function forget_backup( string $svc ): string {
		return self::db_delete( self::BACKUP . $svc ) ? '' : __( 'could not delete the backup', 'zorderz' );
	}

	/* =====================================================================
	 * RETIRE THE HIDDEN COPIES (key services)
	 *
	 * Once a key service is engaged, the copies whose apps fall back to Core's row
	 * are only duplicates of it. Retiring deletes them and records WHICH rows were
	 * retired, never their values: the copies hold the key in use, and the old keys
	 * live only in the Undo backup, which must be gone first. "Bring the copies
	 * back" re-creates each one with the CURRENT key in its app's format. The write
	 * guard keeps refusing any app write to a retired row (update_option on an
	 * absent row is refused as well). A copy whose app reads only its own row is
	 * never retired.
	 * ===================================================================== */

	public static function retirable( string $svc ): bool {
		return in_array( $svc, self::RETIRABLE, true );
	}

	/** @return array<int,array{0:string,1:string}> retired [ row, field|'' ] (empty when not retired) */
	public static function retired_rows( string $svc ): array {
		$st = self::state();
		$r  = $st[ $svc ]['retired']['rows'] ?? array();
		return is_array( $r ) ? array_values( array_filter( $r, 'is_array' ) ) : array();
	}

	public static function is_retired( string $svc ): bool {
		$st = self::state();
		return ! empty( $st[ $svc ]['retired']['at'] );
	}

	/** The copies a retire may delete: not Core's own, and read by an app that falls back to Core. */
	private static function hidden_copies( string $svc ): array {
		$def = self::services()[ $svc ] ?? null;
		$out = array();
		if ( ! $def ) {
			return $out;
		}
		foreach ( $def['copies'] as $field => $rows ) {
			foreach ( $rows as $name => $meta ) {
				if ( in_array( $name, $def['canonical'], true ) || true !== ( $meta['fallback'] ?? false ) ) {
					continue;
				}
				$out[] = array( $name, $meta, $field );
			}
		}
		return $out;
	}

	/** Copies that stay because their app reads only its own row (labels, for the screen). */
	public static function kept_copies( string $svc ): array {
		$def = self::services()[ $svc ] ?? null;
		$out = array();
		if ( ! $def ) {
			return $out;
		}
		foreach ( $def['copies'] as $rows ) {
			foreach ( $rows as $name => $meta ) {
				if ( ! in_array( $name, $def['canonical'], true ) && true !== ( $meta['fallback'] ?? false ) ) {
					$out[ $name ] = (string) $meta[1];
				}
			}
		}
		return $out;
	}

	public static function retire( string $svc, int $user_id = 0 ): string {
		if ( ! self::retirable( $svc ) ) {
			return __( 'only the Ai gateway and CRM copies can be retired', 'zorderz' );
		}
		if ( ! self::engaged( $svc ) ) {
			return __( 'choose the one key first (Use this one)', 'zorderz' );
		}
		if ( self::has_backup( $svc ) ) {
			return __( 'the Undo backup (the old keys) is still kept; press "Every app works: delete the old keys" first', 'zorderz' );
		}
		if ( self::is_retired( $svc ) ) {
			return __( 'already retired', 'zorderz' );
		}
		// Core's own row must hold a usable key, or nothing would be left to read.
		$def = self::services()[ $svc ];
		foreach ( $def['canonical'] as $row ) {
			if ( '' === self::deep_plain( (string) self::db_get( $row ) ) ) {
				/* translators: %s: an option name */
				return sprintf( __( 'the Core row %s is empty; nothing would be left for the apps to read', 'zorderz' ), $row );
			}
		}
		// Record the rows BEFORE deleting, so a request that dies mid-way still leaves
		// "Bring the copies back" able to re-create every one.
		$plan = array();
		foreach ( self::hidden_copies( $svc ) as $c ) {
			if ( null !== self::raw( $c[0], $c[1] ) ) {
				$plan[] = array( $c[0], (string) ( $c[1]['field'] ?? '' ) );
			}
		}
		$state                    = self::state();
		$state[ $svc ]['retired'] = array( 'at' => time(), 'by' => $user_id, 'rows' => $plan );
		update_option( self::STATE, $state, false );

		$done   = array();
		$failed = array();
		foreach ( self::hidden_copies( $svc ) as $c ) {
			list( $name, $meta ) = $c;
			$cur = self::db_get( $name );
			if ( null === $cur ) {
				continue;
			}
			if ( isset( $meta['field'] ) ) {
				$arr = self::as_array( $cur );
				if ( ! is_array( $arr ) || ! array_key_exists( $meta['field'], $arr ) ) {
					continue;
				}
				unset( $arr[ $meta['field'] ] );
				$ok  = self::db_put( $name, serialize( $arr ) );
				$now = self::as_array( self::db_get( $name ) );
				if ( $ok && is_array( $now ) && ! array_key_exists( $meta['field'], $now ) ) {
					$done[] = array( $name, $meta['field'] );
				} else {
					$failed[] = $name . '[' . $meta['field'] . ']';
				}
				continue;
			}
			if ( self::db_delete( $name ) ) {
				$done[] = array( $name, '' );
			} else {
				$failed[] = $name;
			}
		}
		$state                    = self::state();
		$state[ $svc ]['retired'] = array( 'at' => time(), 'by' => $user_id, 'rows' => $done );
		update_option( self::STATE, $state, false );
		if ( $failed ) {
			$state                             = self::state();
			$state[ $svc ]['retired']['rows'] = $plan;
			update_option( self::STATE, $state, false );
			$e = self::unretire( $svc ); // put back what was removed; nothing half-done stays
			return '' === $e
				/* translators: %s: comma-separated option names */
				? sprintf( __( 'these rows could not be retired: %s (every copy was put back)', 'zorderz' ), implode( ', ', $failed ) )
				/* translators: 1: comma-separated option names, 2: the reason */
				: sprintf( __( 'these rows could not be retired: %1$s, and putting the others back failed: %2$s', 'zorderz' ), implode( ', ', $failed ), $e );
		}
		return '';
	}

	/** Re-create every retired copy with the CURRENT key, each in its app's format. */
	public static function unretire( string $svc ): string {
		if ( ! self::retirable( $svc ) ) {
			return __( 'not a key service', 'zorderz' );
		}
		$def = self::services()[ $svc ];
		$cur = array();
		foreach ( $def['canonical'] as $f => $row ) {
			$cur[ $f ] = self::deep_plain( (string) self::db_get( $row ) );
		}
		$metas = array();
		foreach ( self::hidden_copies( $svc ) as $c ) {
			$metas[ $c[0] . '|' . ( $c[1]['field'] ?? '' ) ] = array( $c[1], $c[2] );
		}
		$failed = array();
		foreach ( self::retired_rows( $svc ) as $r ) {
			$name = (string) ( $r[0] ?? '' );
			$fld  = (string) ( $r[1] ?? '' );
			$m    = $metas[ $name . '|' . $fld ] ?? null;
			if ( null === $m || '' === ( $cur[ $m[1] ] ?? '' ) ) {
				$failed[] = $name;
				continue;
			}
			list( $meta, $field ) = $m;
			$plain                = $cur[ $field ];
			if ( '' !== $fld ) {
				$raw = self::db_get( $name );
				$arr = null === $raw ? array() : self::as_array( $raw );
				if ( ! is_array( $arr ) ) {
					$failed[] = $name;
					continue;
				}
				$arr[ $fld ] = ZDZ_Core_Crypto::encode( $plain, $meta[0] );
				$ok          = self::db_put( $name, serialize( $arr ) );
			} else {
				$ok = self::db_put( $name, ZDZ_Core_Crypto::encode( $plain, $meta[0] ) );
			}
			if ( ! $ok || self::one_layer( self::db_get( $name ), $meta ) !== $plain ) {
				$failed[] = $name;
			}
		}
		if ( $failed ) {
			/* translators: %s: comma-separated option names */
			return sprintf( __( 'these copies could not be re-created: %s (press again)', 'zorderz' ), implode( ', ', $failed ) );
		}
		$state = self::state();
		unset( $state[ $svc ]['retired'] );
		update_option( self::STATE, $state, false );
		return '';
	}

	/* =====================================================================
	 * REFUSED-WRITE NOTICE
	 * ===================================================================== */

	private static function note_refused( string $what ): void {
		$uid = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $uid <= 0 || ! function_exists( 'get_transient' ) ) {
			return;
		}
		$k    = self::REFUSED . $uid;
		$list = get_transient( $k );
		$list = is_array( $list ) ? $list : array();
		if ( ! in_array( $what, $list, true ) ) {
			$list[] = $what;
			set_transient( $k, $list, 120 );
		}
	}

	public static function refused_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$k    = self::REFUSED . get_current_user_id();
		$list = get_transient( $k );
		if ( ! is_array( $list ) || ! $list ) {
			return;
		}
		delete_transient( $k );
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Key not changed here.', 'zorderz' ) . '</strong> '
			. esc_html(
				sprintf(
					/* translators: %s: comma-separated option names */
					__( '%s is managed on Zorderz → Credentials, so this screen cannot change it. Change it there and every app gets the new key.', 'zorderz' ),
					implode( ', ', array_map( 'strval', $list ) )
				)
			)
			. ' <a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Open Credentials', 'zorderz' ) . '</a></p></div>';
	}

	/* =====================================================================
	 * SCREEN ACTIONS
	 * ===================================================================== */

	public static function add_page(): void {
		add_submenu_page( 'zdz-core-settings', __( 'Credentials', 'zorderz' ), __( 'Credentials', 'zorderz' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	/** Result of an action: kept server-side for the next page view (no free text in the URL). */
	private static function back( string $msg, bool $ok ): void {
		set_transient( self::MSG . (int) get_current_user_id(), array( $msg, $ok ), 120 );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'zdz_cred_done' => $ok ? '1' : '0' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function gate( string $action ): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'zorderz' ), 403 );
		}
		check_admin_referer( $action );
		$svc = sanitize_key( (string) wp_unslash( $_POST['svc'] ?? '' ) );
		if ( ! isset( self::services()[ $svc ] ) ) {
			self::back( __( 'Unknown service.', 'zorderz' ), false );
		}
		return $svc;
	}

	private static function label( string $svc ): string {
		return (string) ( self::services()[ $svc ]['label'] ?? $svc );
	}

	public static function handle_use(): void {
		$svc = self::gate( 'zdz_cred_use' );
		if ( self::is_app( $svc ) ) {
			self::back( __( 'Unknown service.', 'zorderz' ), false );
		}
		$fp  = (string) preg_replace( '/[^0-9a-f]/', '', sanitize_key( (string) wp_unslash( $_POST['fp'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by check_admin_referer() in self::gate()
		$inv = self::inventory( $svc );
		if ( 8 !== strlen( $fp ) || ! isset( $inv[ $fp ] ) ) {
			self::back( __( 'That key is no longer stored; reload and try again.', 'zorderz' ), false );
		}
		$values = array( ( self::services()[ $svc ]['key_field'] ?? 'api_key' ) => self::find_plain( $svc, $fp ) );
		if ( isset( self::services()[ $svc ]['canonical']['email'] ) ) {
			$values['email'] = $inv[ $fp ]['email'];
			if ( '' === $values['email'] ) {
				self::back( __( 'No login email is stored beside that key. Use "Use a new key" and type the login.', 'zorderz' ), false );
			}
		}
		$err = self::apply( $svc, $values, (int) get_current_user_id() );
		self::back(
			'' === $err
				/* translators: 1: key label (K1), 2: fingerprint, 3: service label */
				? sprintf( __( '%1$s (%2$s) is now the %3$s key for every app.', 'zorderz' ), $inv[ $fp ]['label'], $fp, self::label( $svc ) )
				/* translators: %s: the reason */
				: sprintf( __( 'Not applied: %s', 'zorderz' ), $err ),
			'' === $err
		);
	}

	public static function handle_set(): void {
		$svc = self::gate( 'zdz_cred_set' );
		if ( self::is_app( $svc ) ) {
			self::back( __( 'Unknown service.', 'zorderz' ), false );
		}
		$values = array( 'api_key' => trim( (string) wp_unslash( $_POST['api_key'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked by check_admin_referer() in self::gate(); a secret is kept exactly as typed, refused below unless it is one token of 16+ characters, and never printed
		if ( strlen( $values['api_key'] ) < 16 || preg_match( '/\s/', $values['api_key'] ) ) {
			self::back( __( 'That key looks incomplete (too short or contains spaces). Nothing was changed.', 'zorderz' ), false );
		}
		if ( isset( self::services()[ $svc ]['canonical']['email'] ) ) {
			$values['email'] = sanitize_email( (string) wp_unslash( $_POST['email'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by check_admin_referer() in self::gate()
			if ( ! is_email( $values['email'] ) ) {
				self::back( __( 'Please type the login email. Nothing was changed.', 'zorderz' ), false );
			}
		}
		$err = self::apply( $svc, $values, (int) get_current_user_id() );
		self::back(
			'' === $err
				/* translators: 1: service label, 2: fingerprint */
				? sprintf( __( 'New %1$s key (%2$s) saved for every app.', 'zorderz' ), self::label( $svc ), ZDZ_Core_Crypto::fingerprint( $values['api_key'] ) )
				/* translators: %s: the reason */
				: sprintf( __( 'Not applied: %s', 'zorderz' ), $err ),
			'' === $err
		);
	}

	public static function handle_undo(): void {
		$svc = self::gate( 'zdz_cred_undo' );
		$err = self::undo( $svc );
		self::back(
			'' === $err
				/* translators: %s: service label */
				? sprintf( __( 'Every %s row is back exactly as it was.', 'zorderz' ), self::label( $svc ) )
				/* translators: %s: the reason */
				: sprintf( __( 'Not undone: %s', 'zorderz' ), $err ),
			'' === $err
		);
	}

	public static function handle_forget(): void {
		$svc = self::gate( 'zdz_cred_forget' );
		$err = self::forget_backup( $svc );
		self::back(
			'' === $err
				/* translators: %s: service label */
				? sprintf( __( 'The backup of the old %s values was deleted. Undo is no longer possible.', 'zorderz' ), self::label( $svc ) )
				/* translators: %s: the reason */
				: sprintf( __( 'Not deleted: %s', 'zorderz' ), $err ),
			'' === $err
		);
	}

	public static function handle_retire(): void {
		$svc = self::gate( 'zdz_cred_retire' );
		$err = self::retire( $svc, (int) get_current_user_id() );
		self::back(
			'' === $err
				/* translators: 1: service label, 2: number of rows */
				? sprintf( __( 'The hidden %1$s copies were retired (%2$d rows). Those apps read the one key from Zorderz Core.', 'zorderz' ), self::label( $svc ), count( self::retired_rows( $svc ) ) )
				/* translators: %s: the reason */
				: sprintf( __( 'Not retired: %s', 'zorderz' ), $err ),
			'' === $err
		);
	}

	public static function handle_unretire(): void {
		$svc = self::gate( 'zdz_cred_unretire' );
		// With an Undo backup taken while the copies were retired, Undo is the way back
		// (it re-creates them); bringing them back first would let Undo delete them.
		$err = self::has_backup( $svc )
			? __( 'press Undo (or "Every app works: delete the old keys") first; Undo also brings the copies back', 'zorderz' )
			: self::unretire( $svc );
		self::back(
			'' === $err
				/* translators: %s: service label */
				? sprintf( __( 'Every %s copy was re-created with the current key.', 'zorderz' ), self::label( $svc ) )
				/* translators: %s: the reason */
				: sprintf( __( 'Not brought back: %s', 'zorderz' ), $err ),
			'' === $err
		);
	}

	/**
	 * Save an app's credentials for every app. A blank box keeps the current value
	 * (secrets are never shown, so blank must not mean "erase").
	 */
	public static function handle_app(): void {
		$svc = self::gate( 'zdz_cred_app' );
		if ( ! self::is_app( $svc ) ) {
			self::back( __( 'Unknown service.', 'zorderz' ), false );
		}
		$def     = self::services()[ $svc ];
		$vals    = self::current_values( $svc );
		$posted  = ( isset( $_POST['f'] ) && is_array( $_POST['f'] ) ) ? wp_unslash( $_POST['f'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- each field is validated below by field_value_ok(); nonce checked by check_admin_referer() in self::gate()
		$changed = 0;
		foreach ( $def['fields'] as $f => $fm ) {
			$v = trim( (string) ( $posted[ $f ] ?? '' ) );
			if ( '' === $v || $v === ( $vals[ $f ] ?? '' ) ) {
				continue;
			}
			if ( ! self::field_value_ok( $fm, $v ) ) {
				/* translators: %s: field label */
				self::back( sprintf( __( '%s looks wrong (spaces or unusual characters). Nothing was changed.', 'zorderz' ), $fm[0] ), false );
			}
			$vals[ $f ] = $v;
			$changed++;
		}
		if ( 0 === $changed && self::engaged( $svc ) ) {
			self::back( __( 'Nothing changed.', 'zorderz' ), true );
		}
		// A service with a test keeps a key only when its own provider says it is the right one.
		if ( ! empty( $def['test'] ) && $changed > 0 ) {
			$t = self::run_test( $svc, $vals );
			if ( ! $t['ok'] ) {
				/* translators: %s: what the provider answered */
				self::back( sprintf( __( 'Not saved: %s Nothing was kept.', 'zorderz' ), $t['says'] ), false );
			}
		}
		// The first save also adopts every field NOT typed; a copy holding a different
		// value there needs the same explicit "switch them" as Manage here.
		if ( ! self::engaged( $svc ) && empty( $_POST['switch_different'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by check_admin_referer() in self::gate()
			$typed = array();
			foreach ( $def['fields'] as $f => $fm ) {
				if ( '' !== trim( (string) ( $posted[ $f ] ?? '' ) ) ) {
					$typed[] = $f;
				}
			}
			$diff = self::differing( $svc, $typed );
			if ( $diff ) {
				/* translators: %s: comma-separated app names */
				self::back( sprintf( __( 'Not saved: %s hold a different value in a box left blank. Type that value too, or tick "switch them".', 'zorderz' ), implode( ', ', $diff ) ), false );
			}
		}
		$err = self::apply( $svc, $vals, (int) get_current_user_id() );
		self::back(
			'' === $err
				/* translators: %s: service label */
				? sprintf( __( '%s saved for every app.', 'zorderz' ), $def['label'] )
				/* translators: %s: the reason */
				: sprintf( __( 'Not saved: %s', 'zorderz' ), $err ),
			'' === $err
		);
	}

	/** A typed credential field: one printable token, at most 500 characters. */
	public static function valid_field_value( string $v ): bool {
		return strlen( $v ) <= 500 && 1 === preg_match( '/^[\x21-\x7E]+$/', $v );
	}

	/**
	 * A typed value for one field of a service. An entry an app adds may give a field its own check ('valid' =>
	 * callable( $value ): bool, e.g. a JSON object with spaces in it); it replaces the one-token rule but stays bounded:
	 * at most 2,000 characters and no control characters. Every other field: valid_field_value().
	 */
	public static function field_value_ok( array $fm, string $v ): bool {
		if ( isset( $fm['valid'] ) && is_callable( $fm['valid'] ) ) {
			return strlen( $v ) <= 2000 && ! preg_match( '/[\x00-\x1F\x7F]/', $v ) && (bool) call_user_func( $fm['valid'], $v );
		}
		return self::valid_field_value( $v );
	}

	/** "Manage here": adopt what the apps hold now, so this screen becomes the one place. */
	public static function handle_manage(): void {
		$svc = self::gate( 'zdz_cred_manage' );
		if ( ! self::is_app( $svc ) ) {
			self::back( __( 'Unknown service.', 'zorderz' ), false );
		}
		if ( ! empty( self::services()[ $svc ]['test'] ) ) {
			$t = self::run_test( $svc, self::current_values( $svc ) );
			if ( ! $t['ok'] ) {
				/* translators: %s: what the provider answered */
				self::back( sprintf( __( 'Not managed: %s Type the key under "Set values" instead.', 'zorderz' ), $t['says'] ), false );
			}
		}
		// An app holding a DIFFERENT value would be switched to the value shown (an app on
		// its own registration, whose tokens belong to that registration). That needs the
		// administrator's explicit say-so.
		$diff = self::differing( $svc );
		if ( $diff && empty( $_POST['switch_different'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by check_admin_referer() in self::gate()
			/* translators: %s: comma-separated app names */
			self::back( sprintf( __( 'Not managed: %s hold a different value. Tick "switch them" to move them to the value shown, or type the values under "Or set new values".', 'zorderz' ), implode( ', ', $diff ) ), false );
		}
		$err = self::apply( $svc, self::current_values( $svc ), (int) get_current_user_id() );
		if ( '' !== $err ) {
			/* translators: %s: the reason */
			self::back( sprintf( __( 'Not managed: %s', 'zorderz' ), $err ), false );
		}
		self::back(
			$diff
				/* translators: 1: service label, 2: comma-separated app names */
				? sprintf( __( '%1$s is now managed here. %2$s were switched to it.', 'zorderz' ), self::label( $svc ), implode( ', ', $diff ) )
				/* translators: %s: service label */
				: sprintf( __( '%s is now managed here. Every app keeps working with the same values.', 'zorderz' ), self::label( $svc ) ),
			true
		);
	}

	/**
	 * The apps whose stored copy holds a value that differs from current_values() (empty
	 * copies do not count). Labels only, never values.
	 *
	 * @return string[]
	 */
	public static function differing( string $svc, array $skip = array() ): array {
		$def = self::services()[ $svc ] ?? null;
		if ( ! $def ) {
			return array();
		}
		$cur = self::current_values( $svc );
		$out = array();
		foreach ( $def['copies'] as $f => $rows ) {
			if ( in_array( $f, $skip, true ) ) {
				continue; // a value just typed is meant to replace every copy
			}
			foreach ( $rows as $name => $meta ) {
				$v = self::stored_plain( self::db_get( $name ), $meta );
				if ( '' !== $v && $v !== ( $cur[ $f ] ?? '' ) ) {
					$out[ $meta[1] ] = $meta[1];
				}
			}
		}
		return array_values( $out );
	}

	/**
	 * The one "Connect FreshBooks" link for the billing app, through admin-post so the page
	 * never carries the client id. The URL comes from the app that owns the sign-in and its
	 * callback (filter zdz_billing_connect_url: given the managed client id, return the
	 * provider's authorize URL with that app's signed state). '' when no app offers one.
	 */
	public static function fb_connect_url(): string {
		$v = self::current_values( 'freshbooks' );
		if ( '' === ( $v['client_id'] ?? '' ) || '' === ( $v['client_secret'] ?? '' ) ) {
			return '';
		}
		$url = (string) apply_filters( 'zdz_billing_connect_url', '', (string) $v['client_id'] );
		return ( '' !== $url && 0 === strpos( $url, 'https://' ) ) ? $url : '';
	}

	public static function handle_fb_connect(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'zorderz' ), 403 );
		}
		check_admin_referer( 'zdz_cred_fb_connect' );
		$url = self::engaged( 'freshbooks' ) ? self::fb_connect_url() : '';
		if ( '' === $url ) {
			self::back( __( 'Connect needs the billing app managed here, its Client ID and secret, and an app that offers the sign-in.', 'zorderz' ), false );
		}
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect -- the provider's own host, https only
		exit;
	}

	/* =====================================================================
	 * TEST KEY (Anthropic) and the server-side getter
	 * ===================================================================== */

	/** The Anthropic workspace a key must belong to, or '' to accept any working key. */
	public static function anthropic_workspace( string $typed = '' ): string {
		$stored = '' !== $typed ? $typed : self::deep_plain( (string) self::db_get( 'zdz_core_anthropic_workspace_id' ) );
		return trim( (string) apply_filters( 'zdz_anthropic_workspace_id', $stored ) );
	}

	/**
	 * Does $key work, and (when a workspace is set) does it belong to that workspace? One
	 * GET to Anthropic's Models API (free), which answers every call with an
	 * anthropic-workspace-id header. ['ok' => bool, 'why' => ok|empty|rejected|
	 * other_workspace|no_workspace|unreachable|http, 'says' => a sentence]. The key goes
	 * only into that request's x-api-key header: it is never logged, never stored by this
	 * method and never part of the answer.
	 */
	public static function test_anthropic( string $key, string $workspace = '' ): array {
		$key = trim( $key );
		if ( '' === $key ) {
			return array( 'ok' => false, 'why' => 'empty', 'says' => __( 'There is no key to test.', 'zorderz' ) );
		}
		$want = self::anthropic_workspace( $workspace );
		$r    = wp_remote_get( self::ANTHROPIC_MODELS, array(
			'timeout'     => 15,
			'redirection' => 0,
			'headers'     => array( 'x-api-key' => $key, 'anthropic-version' => '2023-06-01' ),
		) );
		unset( $key ); // nothing below needs it
		return self::read_anthropic_answer( $r, $want );
	}

	/** What Anthropic's answer says about the key (pure: no request). */
	public static function read_anthropic_answer( $r, string $want ): array {
		if ( is_wp_error( $r ) ) {
			return array( 'ok' => false, 'why' => 'unreachable', 'says' => __( 'Anthropic could not be reached, so the key could not be checked.', 'zorderz' ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		if ( 401 === $code || 403 === $code ) {
			return array( 'ok' => false, 'why' => 'rejected', 'says' => __( 'Anthropic refused this key (it is wrong, or it was revoked).', 'zorderz' ) );
		}
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status */
			return array( 'ok' => false, 'why' => 'http', 'says' => sprintf( __( 'Anthropic answered %d, so the key could not be checked.', 'zorderz' ), $code ) );
		}
		if ( '' === $want ) {
			return array( 'ok' => true, 'why' => 'ok', 'says' => __( 'The key works.', 'zorderz' ) );
		}
		$ws = trim( (string) wp_remote_retrieve_header( $r, 'anthropic-workspace-id' ) );
		if ( '' === $ws ) {
			return array( 'ok' => false, 'why' => 'no_workspace', 'says' => __( 'Anthropic did not say which workspace this key belongs to.', 'zorderz' ) );
		}
		if ( ! hash_equals( $want, $ws ) ) {
			return array( 'ok' => false, 'why' => 'other_workspace', 'says' => __( 'This key is from a different workspace than the one set here.', 'zorderz' ) );
		}
		return array( 'ok' => true, 'why' => 'ok', 'says' => __( 'The key works and belongs to the workspace set here.', 'zorderz' ) );
	}

	/** The test a service names ('test' => 'anthropic'), on the values given. */
	private static function run_test( string $svc, array $vals ): array {
		$def = self::services()[ $svc ] ?? array();
		if ( 'anthropic' === ( $def['test'] ?? '' ) ) {
			return self::test_anthropic( (string) ( $vals['api_key'] ?? '' ), (string) ( $vals['workspace_id'] ?? '' ) );
		}
		return array( 'ok' => true, 'why' => 'ok', 'says' => '' );
	}

	/** "Test key": the stored key, checked again. Says what the provider answered; changes nothing. */
	public static function handle_test(): void {
		$svc = self::gate( 'zdz_cred_test' );
		$def = self::services()[ $svc ];
		if ( empty( $def['test'] ) ) {
			self::back( __( 'Unknown service.', 'zorderz' ), false );
		}
		$cur = self::current_values( $svc );
		if ( '' === (string) ( $cur['api_key'] ?? '' ) ) {
			/* translators: %s: service label */
			self::back( sprintf( __( 'No %s key is stored yet.', 'zorderz' ), $def['label'] ), false );
		}
		$t = self::run_test( $svc, $cur );
		self::back( $t['says'] . ' (' . ZDZ_Core_Crypto::fingerprint( (string) $cur['api_key'] ) . ')', $t['ok'] );
	}

	/**
	 * One stored credential, in plain text, for SERVER code only. $field defaults to the
	 * service's key field. '' when nothing is stored. Never hand the result to a page, a
	 * REST answer, a log or a model.
	 */
	public static function get( string $svc, string $field = '' ): string {
		$def = self::services()[ $svc ] ?? null;
		if ( ! $def ) {
			return '';
		}
		if ( '' === $field ) {
			$field = (string) ( $def['key_field'] ?? ( isset( $def['canonical']['api_key'] ) ? 'api_key' : array_key_first( $def['canonical'] ) ) );
		}
		return (string) ( self::current_values( $svc )[ $field ] ?? '' );
	}

	/* =====================================================================
	 * SCREEN
	 * ===================================================================== */

	/** Display form of a non-secret value: enough to recognise it, not the whole thing. */
	private static function show_id( string $v ): string {
		return '' === $v ? '—' : ( strlen( $v ) > 12 ? substr( $v, 0, 8 ) . '…' : substr( $v, 0, max( 1, (int) floor( strlen( $v ) / 2 ) ) ) . '…' );
	}

	private static function mask_email( string $e ): string {
		$at = strrpos( $e, '@' );
		return '' === $e ? '—' : ( false === $at ? $e[0] . '…' : $e[0] . '…' . substr( $e, $at ) );
	}

	private static function when( int $ts ): string {
		return function_exists( 'wp_date' ) ? (string) wp_date( 'j M Y, H:i', $ts ) : gmdate( 'j M Y H:i', $ts );
	}

	/** The opening of one action form (admin-post, nonce, action, service). */
	private static function form_open( string $action, string $svc, string $style = '' ): string {
		$h  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '' ) . ' autocomplete="off">';
		$h .= wp_nonce_field( $action, '_wpnonce', true, false );
		$h .= '<input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="svc" value="' . esc_attr( $svc ) . '">';
		return $h;
	}

	private static function confirm_button( string $text, string $confirm, string $class = 'button-link' ): string {
		return '<button class="' . esc_attr( $class ) . '" onclick="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')">' . esc_html( $text ) . '</button>';
	}

	/** The Expires line under a service heading (a date only, never a key). */
	private static function expiry_line( string $svc ): void {
		if ( class_exists( 'ZDZ_Cred_Expiry' ) ) {
			echo ZDZ_Cred_Expiry::line( $svc ); // phpcs:ignore WordPress.Security.EscapeOutput -- line() escapes its own date text
		}
	}

	private static function render_backup_forms( string $svc, bool $on ): void {
		if ( ! self::has_backup( $svc ) ) {
			return;
		}
		if ( ! $on ) {
			echo '<p><strong>' . esc_html__( 'An earlier change did not finish.', 'zorderz' ) . '</strong> ' . esc_html__( 'Press Undo to put every row back before choosing again.', 'zorderz' ) . '</p>';
		}
		echo self::form_open( 'zdz_cred_undo', $svc, 'display:inline-block;margin-right:16px' ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
		echo self::confirm_button( __( 'Undo: put every app back on its own copy', 'zorderz' ), __( 'Put every row back exactly as it was before the first change?', 'zorderz' ) ) . '</form>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::form_open( 'zdz_cred_forget', $svc, 'display:inline-block' ); // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $on ) {
			$button = self::is_app( $svc )
				? self::confirm_button( __( 'Every app works: delete the backup', 'zorderz' ), __( 'Every app works with these values? Delete the backup (Undo will no longer be possible).', 'zorderz' ) )
				: self::confirm_button( __( 'Every app works: delete the old keys', 'zorderz' ), __( 'Every app works on this key? Delete the backup of the old keys (Undo will no longer be possible).', 'zorderz' ) );
			echo $button; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- confirm_button() escapes its label and its confirm text
		} else {
			echo self::confirm_button( __( 'Undo keeps failing: discard the backup', 'zorderz' ), __( 'Only if Undo keeps failing: keep every row as it is now and discard the backup. Undo will no longer be possible. Continue?', 'zorderz' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</form>';
	}

	/** Retire / bring back the hidden copies, and what to revoke. */
	private static function render_retire( string $svc ): void {
		if ( ! self::retirable( $svc ) ) {
			return;
		}
		echo '<div style="margin:10px 0 4px;padding:8px 12px;border-left:4px solid #72aee6;background:#f6f7f7;max-width:960px">';
		$kept = self::kept_copies( $svc );
		if ( $kept ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: %s: comma-separated app names */
					__( 'Kept and guarded: %s read only their own copy, so it is never retired.', 'zorderz' ),
					implode( ', ', array_unique( array_values( $kept ) ) )
				)
			) . '</p>';
		}
		if ( self::is_retired( $svc ) ) {
			$st    = self::state();
			$at    = (int) ( $st[ $svc ]['retired']['at'] ?? 0 );
			$names = array();
			foreach ( self::retired_rows( $svc ) as $r ) {
				$names[] = $r[0] . ( '' !== (string) ( $r[1] ?? '' ) ? '[' . $r[1] . ']' : '' );
			}
			echo '<p><strong>' . esc_html__( 'Hidden copies retired', 'zorderz' ) . '</strong> ' . esc_html( self::when( $at ) ) . ': ' . esc_html( implode( ', ', $names ) ) . '.</p>';
			echo '<p class="description">' . esc_html__( 'Now revoke, at the provider, every other key this site used to hold; keep only the one in use here.', 'zorderz' ) . '</p>';
			if ( self::has_backup( $svc ) ) {
				echo '</div>';
				return; // Undo (above) is the way back while a backup exists
			}
			echo self::form_open( 'zdz_cred_unretire', $svc ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo self::confirm_button( __( 'Bring the copies back', 'zorderz' ), __( 'Re-create every hidden copy with the current key?', 'zorderz' ) ) . '</form>'; // phpcs:ignore WordPress.Security.EscapeOutput
		} elseif ( self::has_backup( $svc ) ) {
			echo '<p class="description">' . esc_html__( 'Next step: once every app works on this key, press "Every app works: delete the old keys"; then the hidden copies can be retired here.', 'zorderz' ) . '</p>';
		} else {
			$n = 0;
			foreach ( self::hidden_copies( $svc ) as $c ) {
				if ( null !== self::raw( $c[0], $c[1] ) ) {
					$n++;
				}
			}
			if ( 0 === $n ) {
				echo '<p class="description">' . esc_html__( 'No copy is left that can be retired.', 'zorderz' ) . '</p></div>';
				return;
			}
			echo self::form_open( 'zdz_cred_retire', $svc ); // phpcs:ignore WordPress.Security.EscapeOutput
			/* translators: %d: number of rows */
			echo '<p>' . self::confirm_button( __( 'Retire the hidden copies', 'zorderz' ), sprintf( __( 'Delete the %d hidden copies of this key? Their apps already fall back to the one key in Zorderz Core. You can bring them back here at any time.', 'zorderz' ), $n ), 'button' ) // phpcs:ignore WordPress.Security.EscapeOutput
				/* translators: %d: number of rows */
				. ' <span class="description">' . esc_html( sprintf( __( '%d app rows still hold a copy of this key that they would not miss.', 'zorderz' ), $n ) ) . '</span></p></form>';
		}
		echo '</div>';
	}

	/** One app-credential entry on the screen. Secrets: fingerprint + length only. */
	private static function render_app( string $svc, array $def, array $state ): void {
		$on  = ! empty( $state[ $svc ]['fp'] );
		$cur = self::current_values( $svc );
		echo '<h3 style="margin-top:22px">' . esc_html( $def['label'] ) . '</h3>';
		echo '<p class="description" style="max-width:900px">' . esc_html( $def['note'] ?? '' ) . '</p>';
		self::expiry_line( $svc );
		if ( $on ) {
			echo '<p><span class="dashicons dashicons-yes-alt" style="color:#008a20"></span> <strong>' . esc_html__( 'Managed here', 'zorderz' ) . '</strong> '
				/* translators: %s: date and time */
				. esc_html( sprintf( __( 'since %s. Other screens can no longer change these values; a change made there is refused with a notice.', 'zorderz' ), self::when( (int) $state[ $svc ]['at'] ) ) ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Not managed here yet: each app still keeps these on its own settings screen.', 'zorderz' ) . '</p>';
		}
		echo '<table class="widefat striped" style="max-width:980px"><thead><tr><th>' . esc_html__( 'Field', 'zorderz' ) . '</th><th>' . esc_html__( 'Value', 'zorderz' ) . '</th><th>' . esc_html__( 'Stored in (format): app copy matches?', 'zorderz' ) . '</th></tr></thead><tbody>';
		foreach ( $def['fields'] as $f => $fm ) {
			$v    = (string) ( $cur[ $f ] ?? '' );
			$show = '' === $v
				? '<em>' . esc_html__( 'not set', 'zorderz' ) . '</em>'
				: ( $fm[1]
					/* translators: %d: number of characters */
					? '<code>' . esc_html( ZDZ_Core_Crypto::fingerprint( $v ) ) . '</code> <small>(' . esc_html( sprintf( __( '%d characters', 'zorderz' ), strlen( $v ) ) ) . ')</small>'
					: '<code>' . esc_html( self::show_id( $v ) ) . '</code>' );
			$where = array();
			foreach ( $def['copies'][ $f ] ?? array() as $name => $meta ) {
				$stored  = self::db_get( $name );
				$cp      = self::stored_plain( $stored, $meta );
				$fmt     = '' === $cp ? '' : self::stored_format( $stored, $meta );
				$lbl     = '<code>' . esc_html( $name . ( isset( $meta['field'] ) ? '[' . $meta['field'] . ']' : '' ) ) . '</code>' . ( '' !== $fmt ? ' (' . esc_html( $fmt ) . ')' : '' ) . ' — ' . esc_html( $meta[1] );
				$where[] = $lbl . ': ' . ( '' === $cp ? esc_html__( 'empty', 'zorderz' ) : ( $cp === $v ? esc_html__( 'same', 'zorderz' ) : '<strong>' . esc_html__( 'different', 'zorderz' ) . '</strong>' ) );
			}
			echo '<tr><td>' . esc_html( $fm[0] ) . '</td><td>' . $show . '</td><td>' . implode( '<br>', $where ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- every part escaped above
		}
		echo '</tbody></table>';

		$any = '' !== implode( '', $cur );
		if ( ! $on && $any ) {
			echo self::form_open( 'zdz_cred_manage', $svc, 'margin:10px 0' ); // phpcs:ignore WordPress.Security.EscapeOutput
			$diff = self::differing( $svc );
			if ( $diff ) {
				echo '<p><label><input type="checkbox" name="switch_different" value="1"> <strong>' . esc_html( implode( ', ', $diff ) ) . '</strong> '
					. esc_html__( 'hold a different value (marked "different" above): switch them to the value shown. An app on its own app registration will need reconnecting.', 'zorderz' ) . '</label></p>';
			}
			echo self::confirm_button( __( 'Manage here', 'zorderz' ), __( 'Manage these values here from now on? Every app uses the values shown; the current rows are backed up first and Undo puts them back.', 'zorderz' ), 'button button-primary' ) // phpcs:ignore WordPress.Security.EscapeOutput
				. ' <span class="description">' . esc_html__( 'Keeps today\'s values; from then on change them only on this screen.', 'zorderz' ) . '</span></form>';
		}

		echo '<details style="margin:8px 0 4px"><summary>' . esc_html( $on ? __( 'Change', 'zorderz' ) : ( $any ? __( 'Or set new values', 'zorderz' ) : __( 'Set values', 'zorderz' ) ) ) . '</summary>';
		echo self::form_open( 'zdz_cred_app', $svc, 'margin-top:8px' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<table class="form-table" style="max-width:760px">';
		foreach ( $def['fields'] as $f => $fm ) {
			$v = (string) ( $cur[ $f ] ?? '' );
			echo '<tr><th scope="row">' . esc_html( $fm[0] ) . '</th><td>';
			// Nothing stored is printed in full, not even an ID: every box starts empty, a
			// blank box keeps the value, and the placeholder says what is there.
			if ( $fm[1] ) {
				echo '<input type="password" name="f[' . esc_attr( $f ) . ']" value="" class="regular-text" autocomplete="new-password" placeholder="' . esc_attr( '' === $v ? __( 'not set', 'zorderz' ) : __( 'unchanged: type to replace', 'zorderz' ) ) . '">';
			} else {
				/* translators: %s: the start of the stored value */
				echo '<input type="text" name="f[' . esc_attr( $f ) . ']" value="" class="regular-text" autocomplete="off" placeholder="' . esc_attr( '' === $v ? __( 'not set', 'zorderz' ) : sprintf( __( '%s: type to replace', 'zorderz' ), self::show_id( $v ) ) ) . '">';
			}
			echo '</td></tr>';
		}
		$diff_set = $on ? array() : self::differing( $svc );
		if ( $diff_set ) {
			echo '<tr><th scope="row"></th><td><label><input type="checkbox" name="switch_different" value="1"> <strong>' . esc_html( implode( ', ', $diff_set ) ) . '</strong> '
				. esc_html__( 'hold a different value: for boxes left blank, switch them to the value shown.', 'zorderz' ) . '</label></td></tr>';
		}
		echo '</table><p class="description">' . esc_html__( 'A blank box keeps the current value. Saving writes every app\'s copy in that app\'s own format; the rows are backed up first (Undo).', 'zorderz' ) . '</p>';
		echo '<button class="button button-primary">' . esc_html__( 'Save for every app', 'zorderz' ) . '</button></form></details>';

		if ( ! empty( $def['test'] ) && '' !== (string) ( $cur['api_key'] ?? '' ) ) {
			echo self::form_open( 'zdz_cred_test', $svc, 'margin:10px 0' ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<button class="button">' . esc_html__( 'Test key', 'zorderz' ) . '</button> <span class="description">'
				. esc_html__( 'One free call to Anthropic\'s Models API: does the key work, and (when a workspace ID is set) is it from that workspace?', 'zorderz' ) . '</span></form>';
		}
		if ( 'freshbooks' === $svc ) {
			// Only once the app is managed here (the sign-in then exchanges the code with
			// the same id), and through admin-post so the page never carries the client id.
			$url = ( $on && '' !== self::fb_connect_url() )
				? add_query_arg( array( 'action' => 'zdz_cred_fb_connect', '_wpnonce' => wp_create_nonce( 'zdz_cred_fb_connect' ) ), admin_url( 'admin-post.php' ) )
				: '';
			echo '<p style="margin:10px 0">' . ( '' !== $url
				? '<a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Connect / reconnect FreshBooks', 'zorderz' ) . '</a> <span class="description">' . esc_html__( 'One sign-in for every app; the token service picks up the new connection.', 'zorderz' ) . '</span>'
				: '<span class="description">' . esc_html__( 'Connect FreshBooks from the app that holds the billing sign-in; the token service picks up the new connection.', 'zorderz' ) . '</span>' ) . '</p>';
		}
		// A registered service draws its own controls here (a sign-in, a test):
		// action zdz_core_credentials_service_after( $svc, $on ). It prints no secret.
		do_action( 'zdz_core_credentials_service_after', $svc, $on );
		self::render_backup_forms( $svc, $on );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$state = self::state();
		echo '<div class="wrap"><h1>' . esc_html__( 'Credentials', 'zorderz' ) . '</h1>';
		echo '<p style="max-width:900px">' . esc_html__( 'One key per service. Keys are never shown here: each is identified by a fingerprint (the first 8 characters of a one-way hash). The same fingerprint means the same key.', 'zorderz' ) . '</p>';
		$note = get_transient( self::MSG . (int) get_current_user_id() );
		if ( is_array( $note ) && 2 === count( $note ) ) {
			delete_transient( self::MSG . (int) get_current_user_id() );
			echo '<div class="notice ' . ( $note[1] ? 'notice-success' : 'notice-error' ) . '"><p>' . esc_html( (string) $note[0] ) . '</p></div>';
		}
		if ( ! self::active() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'The single-credential layer is switched off on this site (zdz_single_credentials): nothing here guards the copies or re-encodes the billing secret.', 'zorderz' ) . '</p></div>';
		}

		foreach ( self::services() as $svc => $def ) {
			if ( 'app' === ( $def['kind'] ?? '' ) ) {
				continue; // rendered below, by group
			}
			$inv = self::inventory( $svc );
			$on  = ! empty( $state[ $svc ]['fp'] );
			echo '<h2>' . esc_html( $def['label'] ) . '</h2>';
			self::expiry_line( $svc );
			if ( $on ) {
				$fp = (string) $state[ $svc ]['fp'];
				echo '<p><span class="dashicons dashicons-yes-alt" style="color:#008a20"></span> <strong>' . esc_html__( 'One key for every app:', 'zorderz' ) . '</strong> <code>' . esc_html( $fp ) . '</code>'
					. ( isset( $def['canonical']['email'] ) && isset( $inv[ $fp ] ) ? ' ' . esc_html( sprintf( /* translators: %s: masked login */ __( 'as %s', 'zorderz' ), self::mask_email( $inv[ $fp ]['email'] ) ) ) : '' )
					/* translators: %s: date and time */
					. ' ' . esc_html( sprintf( __( 'since %s. Other screens can no longer change it.', 'zorderz' ), self::when( (int) $state[ $svc ]['at'] ) ) ) . '</p>';
			} else {
				echo '<p>' . ( count( $inv ) > 1
					/* translators: %d: number of keys */
					? '<strong>' . esc_html( sprintf( __( '%d different keys are stored.', 'zorderz' ), count( $inv ) ) ) . '</strong> ' . esc_html__( 'Each app reads its own copy first, so they do not all use the same one.', 'zorderz' ) . ' '
					: '' ) . esc_html__( 'Press "Use this one" next to the key every app should use.', 'zorderz' ) . '</p>';
			}

			echo '<table class="widefat striped" style="max-width:980px"><thead><tr><th>' . esc_html__( 'Key', 'zorderz' ) . '</th><th>' . esc_html__( 'Stored in (format): read by', 'zorderz' ) . '</th><th></th></tr></thead><tbody>';
			if ( ! $inv ) {
				echo '<tr><td colspan="3">' . esc_html__( 'No key stored.', 'zorderz' ) . '</td></tr>';
			}
			$copies = $def['copies'][ $def['key_field'] ?? 'api_key' ];
			foreach ( $inv as $fp => $k ) {
				$where = array();
				foreach ( $k['rows'] as $name => $fmt ) {
					$where[] = '<code>' . esc_html( $name ) . '</code> (' . esc_html( $fmt ) . ') — ' . esc_html( $copies[ $name ][1] ?? '' );
				}
				/* translators: %d: number of characters */
				echo '<tr><td><strong>' . esc_html( $k['label'] ) . '</strong> <code>' . esc_html( $fp ) . '</code><br><small>' . esc_html( sprintf( __( '%d characters', 'zorderz' ), (int) $k['len'] ) )
					. ( isset( $def['canonical']['email'] ) ? '<br>' . esc_html( sprintf( /* translators: %s: masked login */ __( 'login %s', 'zorderz' ), self::mask_email( $k['email'] ) ) ) : '' ) . '</small></td><td>' . implode( '<br>', $where ) . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- every part escaped
				if ( ! $on ) {
					echo self::form_open( 'zdz_cred_use', $svc ) . '<input type="hidden" name="fp" value="' . esc_attr( $fp ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput
					/* translators: %s: key label and fingerprint */
					echo self::confirm_button( __( 'Use this one', 'zorderz' ), sprintf( __( 'Every app will use %s. The current rows are backed up first and Undo puts them back. Continue?', 'zorderz' ), $k['label'] . ' ' . $fp ), 'button button-primary' ) . '</form>'; // phpcs:ignore WordPress.Security.EscapeOutput
				} elseif ( $fp === $state[ $svc ]['fp'] ) {
					echo '<strong>' . esc_html__( 'In use', 'zorderz' ) . '</strong>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';

			// New key (write-only).
			echo self::form_open( 'zdz_cred_set', $svc, 'margin:10px 0' ); // phpcs:ignore WordPress.Security.EscapeOutput
			if ( isset( $def['canonical']['email'] ) ) {
				echo '<input type="email" name="email" placeholder="' . esc_attr__( 'Login email', 'zorderz' ) . '" class="regular-text" autocomplete="off"> ';
			}
			echo '<input type="password" name="api_key" placeholder="' . esc_attr( $on ? __( 'Replace with a new key', 'zorderz' ) : __( 'Or paste a new key', 'zorderz' ) ) . '" class="regular-text" autocomplete="new-password"> ';
			echo '<button class="button">' . esc_html( $on ? __( 'Replace for every app', 'zorderz' ) : __( 'Use a new key', 'zorderz' ) ) . '</button></form>';

			self::render_backup_forms( $svc, $on );
			if ( $on ) {
				self::render_retire( $svc );
			}
		}

		// App credentials, by group.
		$group = '';
		foreach ( self::services() as $svc => $def ) {
			if ( 'app' !== ( $def['kind'] ?? '' ) ) {
				continue;
			}
			if ( $group !== $def['group'] ) {
				$group = $def['group'];
				echo '<h2 style="margin-top:34px">' . esc_html( $group ) . '</h2>';
			}
			self::render_app( $svc, $def, $state );
		}
		if ( class_exists( 'ZDZ_Cred_Expiry' ) ) {
			ZDZ_Cred_Expiry::render_form();
		}
		echo '</div>';
	}
}
