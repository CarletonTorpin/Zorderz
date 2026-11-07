<?php
/**
 * Module: Zorderz - Knowledge
 * Description: Company-wide document repository with AI indexing. Upload documents;
 *   an assistant extracts structured knowledge that is searchable across the
 *   platform. Optional per-document visibility, party-siloed transcripts, and a
 *   runtime pricing-authority context feed for the platform assistant. Ships
 *   EMPTY: no company facts, no product corpus, no seeded categories.
 * Version:     1.8.0
 * Author:      Zorderz
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zorderz
 * Requires PHP: 8.0
 *
 * This is a bundled app module (loaded by zorderz-apps.php), not a standalone
 * plugin. It registers with the theme through the `zdz_register_apps` filter on
 * after_setup_theme and declines cleanly when the theme is absent. Identity
 * (business name / industry / login route), AI credentials and model come from
 * the theme's Core services (ZDZ_Business_Profile, ZDZ_Core_Settings,
 * ZDZ_Core_Poe) — nothing about any one business is hardcoded.
 *
 * SECURITY POSTURE (uploads): the AUTHENTICATED /vault/{slug} route is the
 * PRIMARY access control — it enforces login + the app-access grant + the
 * per-document visibility ACL before streaming a byte. The physical store also
 * carries a deny-all .htaccess (Apache) and web.config (IIS) as defence-in-depth,
 * but .htaccess is inert on nginx and is never the guarantee;
 * zkv_vault_protection_report() raises a loud admin health warning when the web
 * server cannot honour the file-level rule. Activation writes SCHEMA ONLY —
 * no categories, facts or product keywords are seeded, and upgrades never insert
 * business rows.
 *
 *
 *   PRIVATE DOCUMENTS (ZKV_Private, column is_private) — a switch per document, off by
 *     default. A Private document is never sent to any Ai service (no title suggestion, no
 *     index, no page images, never another upload's category context, never chat); its file
 *     is stored encrypted (libsodium secretstream) with no plain copy (no media attachment,
 *     no image sizes, no extracted text); it is found by its title and the Context the person
 *     typed. One data key, wrapped by keys derived from the site's own secret keys in
 *     wp-config.php (or ZKV_PRIVATE_KEK); a recovery code, shown only to administrators and
 *     logged on every showing, unlocks it if those keys are ever reset. Fails closed.
 *   OUTSIDE LINKS (ZKV_Ext_Links, table zkv_ext_links) — /vault/l/<token>, no sign-in, for
 *     a fixed number of days (14 or 30 by default, filter zkv_ext_link_days); only the
 *     token's hash is stored; every request re-checks the link and the maker's right to share.
 *   ONE ACCESS CHECK FOR OTHER APPS (ZKV_Files) — the zdz_vault_find_for_user,
 *     zdz_vault_file_for_user, zdz_vault_file_bytes and zdz_vault_outside_link filters.
 *   UNGUESSABLE FILE NAMES (ZKV_File_Names) — every stored file is 32 random hex characters
 *     plus its extension; files from earlier versions are renamed in place in small batches,
 *     each rename recorded first so nothing loses its file.
 *   VIEWER (ZKV_Viewer) — /vault/{slug} is a readable page (PDFs drawn page by page with the
 *     bundled pdf.js, images and video inline, other files as their extracted text); the file
 *     itself is ?raw=1 (&dl=1 downloads). The page sends a Content-Security-Policy with a
 *     per-request nonce. A signed-out visit remembers the document (ZDZ_Login_Next).
 *   A person's title and category are theirs (title_locked / category_locked): the index
 *     no longer replaces them; Rename and change category in the detail view.
 *   pdftotext runs from one helper (fixed paths first, no shell lookup).
 *   The Ai gateway key reads the platform credential first; while it, or the mail-in
 *     Microsoft 365 app, is managed on Zorderz → Credentials, the settings screens show a
 *     note instead of the fields. The model comes from the Model Registry (slot `knowledge`,
 *     roster `zdz_model_choices`): no model name is written into this app.
 *
 * v1.7.1 (fresh-install schema + chat wiring):
 *   SCHEMA SELF-HEAL — the zkv_documents CREATE TABLE now declares
 *     is_pricing_authority and transcript_status (plus their indexes) directly, so
 *     dbDelta adds them on every activation/upgrade. Before this they existed ONLY
 *     in the version-gated migration, which a fresh install at the current version
 *     (or an upgrade whose request died after the version bump but before the ALTERs)
 *     skips — leaving every document insert (upload, chat-upload, paste, email-in)
 *     failing with "Failed to create document record." The migration block is kept
 *     as-is for older installs and simply no-ops once the columns exist.
 *   CHAT BRIDGE — the analytics/Chat assistant reads its per-turn data context from
 *     the neutral `zdz_analytics_data_context` filter (ships empty). This app now
 *     hooks that filter and feeds it the ACL-scoped ZKV_ZANA_Bridge inventory +
 *     matched content, so an indexed, permitted vault document is answerable in chat.
 *
 * v1.6.0: identifiers under the zkv/ZKV prefix; REST under the single
 *   ZDZ_REST_NS namespace; proprietary licence → GPL-2.0-or-later; product-name
 *   literals → the settings-driven `zkv_product_keywords` list (density-scoring
 *   mechanics unchanged); indexer/classifier prompts assembled at runtime from
 *   ZDZ_Business_Profile with no typed company/product; route-level auth made
 *   the documented primary gate + a health warning for .htaccess-only hosts;
 *   login route read from settings. Prior behaviour preserved.
 *
 * v1.5.2 (FIELD-REVIEW FIXES — KV1/KV2/KV3):
 *   KV1 SCOPED TRANSCRIPT ACCESS — a named party or the holder of an active
 *     whole-document share can now OPEN a transcript shared with them even if
 *     they lack the coarse `knowledge-vault` app grant (the unlisted-recipient bug: sharing
 *     used to be a silent failure — the card called the recipient a party but the serve
 *     gate 404'd him, and share-create refused him outright). The bypass is
 *     limited to transcript docs the ACL already admits and grants NO reach into
 *     the general vault (normal docs still require the app grant; dashboard and
 *     search stay coarse-gated). New coarse-grant-free "/vault/shared" landing
 *     page lists every transcript/excerpt shared with a user.
 *   KV2 EXPLICIT-BY-DEFAULT SHARING — an opt-in private transcript no longer
 *     auto-grants its detected parties. Detection now stages them as SUGGESTIONS
 *     (status 'pending_confirm') and the uploader must confirm who may see it
 *     before anyone is granted. Nothing is shared until the uploader confirms.
 *   KV3 PRICING-DOC GATING — a document becomes a pricing authority ONLY when it
 *     is in a designated pricing folder (category 'pricing-documents', filter
 *     `zkv_pricing_category_slugs`) AND explicitly enabled. Uploads never
 *     auto-flag pricing. Admin review/clear endpoints added for existing entries.
 *   No change to the ZKV_ACL predicate (the 0%-cross-over core) or the schema
 *     shape; transcript_status gains the 'pending_confirm' lifecycle value.
 *
 * v1.5.1 (MODEL MODERNIZATION): the indexer was offering retired/old LLM handles.
 *   The AI Model picker (both settings screens) moves Gemini-2.5-Flash → Gemini-3.6-
 *   Flash and Claude-Opus-4.6 → Claude-Opus-4.8 (Gemini-3.1-Pro + Claude-Sonnet-4.6
 *   unchanged). ZKV_Dashboard::poe_query() gains an alias map so a STORED
 *   zkv_ai_model carrying an old handle (Gemini-2.5-/3-/3.5-Flash, Gemini-3-Pro,
 *   Opus-4.6/4.7, Sonnet-4.5, GPT-5.2) falls forward to a live model without
 *   re-saving settings. poe_query sends no reasoning params, so no thinking_budget/
 *   family concern here. Indexing/schema behavior otherwise unchanged from:
 *
 * v1.5.0 (PRIVATE TRANSCRIPTS — party-siloed documents, 0% cross-over):
 *   A transcript document is readable ONLY by the WP users who are its named
 *   speaking parties — everywhere at once: vault list/search, /vault/{slug},
 *   preview chips, AND the assistant's retrieval context. Admins/owners who are
 *   not parties see nothing (silent 404 / absent — a person reads only their own; fail closed).
 *
 *   Mechanics (see ZKV_ACL):
 *   - visibility = 'transcript_private' + wp_zkv_doc_parties ACL keyed on WP
 *     user IDs. One authoritative predicate, two modes: sql_where_chat()
 *     (party-only — the Analytics bridge uses this) and sql_where_view() (party OR
 *     active whole-doc share — REST/dashboard/serve use this). Placeholder-free
 *     fragments (hard-cast ints) so they compose with the existing prepared AND
 *     unprepared query styles without double-prepare breakage.
 *   - Every legacy query filters visibility='all_employees', so a transcript
 *     is invisible-by-default to any path not explicitly ACL-routed
 *     (fails toward hiding, never exposing).
 *   - Detection is OPT-IN: the uploader's "Private transcript" choice (or an
 *     email subject [transcript] tag) is the only auto-privatize trigger. AI
 *     detection only files a suggestion in the admin queue; the doc stays a
 *     normal visible document until an admin confirms — a mis-detect can never
 *     silently vanish a normal document from its own uploader.
 *   - Zero resolved parties → transcript_status='latent': retained, dark,
 *     listed in the admin queue (metadata + speaker labels + ±1 line context
 *     only — never the body) until an admin binds the confirmed real person.
 *   - Party-initiated sharing: view-only, no re-share, optional expiry,
 *     revocable. Whole-doc shares unlock the normal view surfaces; excerpt
 *     shares are MATERIALIZED (only the shared lines are stored/served from
 *     wp_zkv_doc_shares.excerpt_text — redacted text is byte-absent, never
 *     CSS-masked). Shares never reach chat (chat predicate is party-only).
 *   - Line rendition (wp_zkv_transcript_lines) stored at transcript ingest:
 *     the stable, non-overlapping coordinate system excerpt selection and the
 *     admin queue's ±1-line context read from (chunks overlap; lines don't).
 *   - Closes a pre-existing leak in the same pass: ZKV_ZANA_Bridge previously
 *     applied NO visibility filter, so admin_only chunk text could reach any
 *     user's the assistant context. All bridge queries are now ACL-scoped, and the
 *     shared inventory transient is tier-keyed (admin/staff) + transcript-free.
 *
 * v1.4.1 (shared-mailbox coordination for the messaging DM-reply bridge):
 *   The App@ mailbox poller now (a) selects toRecipients/ccRecipients so a
 *   consumer can see the delivery address, and (b) hands a DM-reply email off to
 *   Zorderz Internal Messaging (ZIM_Email_Reply) BEFORE turning it into a vault
 *   document. A message addressed to app+dm-<token>@… (or carrying an in-body DM
 *   token) is posted back into the DM and filed as "Vault Processed"; an
 *   unroutable one is filed under "Messaging Failed". This keeps the vault as the
 *   single reader of the shared mailbox (no two-poller race). No behaviour change
 *   for ordinary forwarded-document mail; no DB migration.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safe response headers for serving a stored knowledge file (shared by both file-serve routes,
 * so the allowlist cannot drift between two copies). Defense in depth so a future upload
 * allowlist slip cannot become stored XSS: send X-Content-Type-Options: nosniff always, and
 * Content-Disposition: inline ONLY for a small allowlist of types safe to render in the site's
 * own origin (raster images, PDF, plain text). Everything else, including any scriptable type
 * (SVG, HTML, XML), is sent as an attachment so the browser downloads it instead of executing
 * it in-origin. This is a no-op for the file types the vault serves today; it only changes
 * behavior if a scriptable type is ever allowed into the upload allowlist.
 */
if ( ! function_exists( 'zkv_safe_disposition' ) ) {
	function zkv_safe_disposition( string $mime ): string {
		$inline_ok = array(
			'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp', 'image/tiff',
			'application/pdf', 'text/plain',
		);
		return in_array( strtolower( trim( $mime ) ), $inline_ok, true ) ? 'inline' : 'attachment';
	}
}
if ( ! function_exists( 'zkv_serve_file_headers' ) ) {
	function zkv_serve_file_headers( string $mime, string $name ): void {
		header( 'Content-Type: ' . $mime );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: ' . zkv_safe_disposition( $mime ) . '; filename="' . sanitize_file_name( $name ) . '"' );
	}
}

define( 'ZKV_VERSION', '1.8.0' );
define( 'ZKV_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZKV_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ZKV_NONCE', 'zkv_nonce' );
define( 'ZKV_MAX_UPLOAD_BYTES', 50 * 1024 * 1024 );

// Secure vault storage directory. Files are served ONLY through the
// authenticated /vault/{slug} route (the primary gate). A deny-all .htaccess /
// web.config are written here as defence-in-depth, but the route auth — not the
// web-server file rule — is the guarantee (see zkv_vault_protection_report()).
$zkv_upload_dir = wp_upload_dir();
// Files live in uploads/zkv-vault (so a host's uploads backup includes them) under random 32-hex
// names (ZKV_File_Names), so a file can only be fetched by someone who already holds its name.
// ZKV_PRIVATE_DIR in wp-config.php can move the folder elsewhere. A web-server rule denying
// /wp-content/uploads/zkv-vault/ is still recommended on hosts that serve static files directly.
require_once ZKV_PLUGIN_DIR . 'includes/class-zkv-file-names.php';
define( 'ZKV_VAULT_DIR', ZKV_File_Names::vault_dir() );
ZKV_File_Names::boot();
define( 'ZKV_VAULT_URL', $zkv_upload_dir['baseurl'] . '/zkv-vault' ); // Reference only, NOT for serving

/**
 * Resolve the site's login URL from settings, never a hardcoded slug.
 *
 * Order: an explicit `zkv_login_slug` option / `zkv_login_url` filter, else WP
 * core's wp_login_url() — which the Zorderz theme already filters to the tenant's
 * configured login page. This is why no route here types '/login/'.
 *
 * @param string $redirect Optional post-login redirect target.
 * @return string
 */
function zkv_login_url( $redirect = '' ) {
	$slug = trim( (string) get_option( 'zkv_login_slug', '' ) );
	if ( '' !== $slug ) {
		$url = home_url( '/' . trim( $slug, '/' ) . '/' );
		if ( '' !== $redirect ) {
			$url = add_query_arg( 'redirect_to', rawurlencode( $redirect ), $url );
		}
	} else {
		$url = wp_login_url( $redirect );
	}
	/**
	 * Filter the vault's login URL. Lets the platform (or an Item/Identity pack)
	 * override the login route without this module knowing the slug.
	 */
	return (string) apply_filters( 'zkv_login_url', $url, $redirect );
}

/**
 * The product / brand keyword list used to (a) recognise a pricing-lookup query
 * and (b) rescue product-line pricing tables from dense PDF grids.
 *
 * CORE SHIPS ONLY GENERIC, TRADE-NEUTRAL PRICING CUES. A business's own
 * product/brand tokens arrive as its data via the
 * `zkv_product_keywords` option, or from the future Item Engine through the
 * `zkv_product_keywords` filter — a documented seam with a graceful empty
 * default. The density-scoring mechanics that consume this list are unchanged.
 *
 * @return string[] lowercase keywords (generic base + tenant/custom tokens)
 */
function zkv_pricing_keywords() {
	$base = array( 'price', 'pricing', 'cost', 'charge', 'how much', 'msrp', 'retail', 'quote', 'rate', 'fee', 'product book' );
	return array_values( array_unique( array_merge( $base, zkv_product_keywords() ) ) );
}

/**
 * The tenant's product / brand tokens only (no generic pricing words). These
 * drive the product-line coverage boost in the density scorer and OCR-spaced
 * matching. Core default is EMPTY — bind the Item Engine here when it lands.
 *
 * @return string[] lowercase tokens
 */
function zkv_product_keywords() {
	$custom = get_option( 'zkv_product_keywords', array() );
	if ( ! is_array( $custom ) ) {
		$custom = array_filter( array_map( 'trim', explode( ',', (string) $custom ) ) );
	}
	$custom = apply_filters( 'zkv_product_keywords', $custom );
	$custom = is_array( $custom ) ? array_map( 'strtolower', array_map( 'strval', $custom ) ) : array();
	return array_values( array_unique( array_filter( $custom ) ) );
}

/**
 * A one-line business descriptor for AI prompts, assembled at runtime from the
 * Business Profile — never a typed company/industry/place. Used by the indexer
 * and classifier so a prompt reads e.g. "You are a document indexer for
 * {name}, a {industry} business." with no company baked into the code. Falls
 * back to a neutral phrase when the profile is empty (the shipped default).
 *
 * @return string e.g. "Acme Co, a field-service business" or "the business"
 */
function zkv_business_descriptor() {
	if ( ! class_exists( 'ZDZ_Business_Profile' ) ) {
		return 'the business';
	}
	$name     = trim( (string) ZDZ_Business_Profile::name() );
	$industry = trim( (string) ZDZ_Business_Profile::get( 'identity.industry', '' ) );
	if ( '' === $name ) {
		return $industry !== '' ? 'a ' . $industry . ' business' : 'the business';
	}
	return $industry !== '' ? $name . ', a ' . $industry . ' business' : $name;
}

/**
 * Does the given user have access to the vault?
 * Dual capability check — matches the platform app-access pattern.
 */
function zkv_user_has_access( $user_id = null ) {
	$uid = ( $user_id && (int) $user_id > 0 ) ? (int) $user_id : get_current_user_id();
	if ( ! $uid ) { return false; }
	// Gate on REAL app-access to 'knowledge-vault' rather than the blanket
	// zdz_access_app cap (which every role, including the shared kiosk, holds).
	// Delegates to the theme's canonical helper when present; legacy dual-cap
	// fallback on an older theme.
	if ( is_callable( array( 'ZDZ_Plugin_API', 'user_can_access_app' ) ) ) {
		return ZDZ_Plugin_API::user_can_access_app( $uid, 'knowledge-vault' );
	}
	return user_can( $uid, 'manage_options' ) || user_can( $uid, 'zdz_access_app' );
}

/**
 * Generate a pretty, authenticated URL for a vault document.
 * Format: {home_url}/vault/{slug} — derived from site config, never a hardcoded
 * host. Protected by the theme's template_redirect login gate + the serve ACL.
 *
 * @param int    $document_id
 * @param string $slug Optional — if known, avoids a DB lookup.
 * @return string Pretty vault URL
 */
function zkv_secure_url( $document_id, $slug = '' ) {
	if ( empty( $slug ) ) {
		global $wpdb;
		$slug = $wpdb->get_var( $wpdb->prepare(
			"SELECT slug FROM {$wpdb->prefix}zkv_documents WHERE id = %d",
			(int) $document_id
		) );
	}
	if ( empty( $slug ) ) {
		$slug = 'doc-' . (int) $document_id;
	}
	return home_url( '/vault/' . $slug );
}

/**
 * Generate a unique slug for a vault document.
 * Uses the AI-generated title, falls back to filename.
 * Appends doc ID if there's a collision.
 */
function zkv_generate_slug( $title, $document_id ) {
	$slug = sanitize_title( $title );
	if ( empty( $slug ) ) {
		$slug = 'document-' . $document_id;
	}
	// Truncate to reasonable length.
	if ( strlen( $slug ) > 80 ) {
		$slug = substr( $slug, 0, 80 );
		$slug = preg_replace( '/-[^-]*$/', '', $slug ); // Clean partial word
	}
	// Check uniqueness.
	global $wpdb;
	$exists = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$wpdb->prefix}zkv_documents WHERE slug = %s AND id != %d",
		$slug, (int) $document_id
	) );
	if ( $exists ) {
		$slug .= '-' . $document_id;
	}
	return $slug;
}

// ── Activation ─────────────────────────────────────────────────────
register_activation_hook( __FILE__, 'zkv_activate' );

function zkv_activate() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset = $wpdb->get_charset_collate();

	$t_docs = $wpdb->prefix . 'zkv_documents';
	dbDelta( "CREATE TABLE {$t_docs} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
		uploaded_by bigint(20) unsigned NOT NULL,
		slug varchar(200) NOT NULL DEFAULT '',
		title varchar(500) NOT NULL,
		original_name varchar(255) NOT NULL,
		mime_type varchar(100) NOT NULL,
		file_size bigint(20) unsigned NOT NULL DEFAULT 0,
		file_url varchar(2048) NOT NULL DEFAULT '',
		file_hash varchar(64) DEFAULT NULL,
		source_type varchar(20) NOT NULL DEFAULT 'upload',
		description text DEFAULT NULL,
		user_context text DEFAULT NULL,
		category_id bigint(20) unsigned DEFAULT NULL,
		status varchar(20) NOT NULL DEFAULT 'pending',
		processing_error text DEFAULT NULL,
		visibility varchar(100) NOT NULL DEFAULT 'all_employees',
		is_private tinyint(1) NOT NULL DEFAULT 0,
		title_locked tinyint(1) NOT NULL DEFAULT 0,
		category_locked tinyint(1) NOT NULL DEFAULT 0,
		is_pricing_authority tinyint(1) NOT NULL DEFAULT 0,
		transcript_status varchar(24) NOT NULL DEFAULT '',
		version int unsigned NOT NULL DEFAULT 1,
		retry_count int unsigned NOT NULL DEFAULT 0,
		indexed_at datetime DEFAULT NULL,
		created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY idx_status (status),
		KEY idx_uploaded_by (uploaded_by),
		KEY idx_category (category_id),
		KEY idx_created (created_at),
		KEY idx_slug (slug),
		KEY idx_private (is_private),
		KEY idx_visibility (visibility),
		KEY idx_pricing_authority (is_pricing_authority),
		KEY idx_transcript_status (transcript_status)
	) {$charset};" );

	$t_idx = $wpdb->prefix . 'zkv_index';
	dbDelta( "CREATE TABLE {$t_idx} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		document_id bigint(20) unsigned NOT NULL,
		version int unsigned NOT NULL DEFAULT 1,
		is_current tinyint(1) NOT NULL DEFAULT 1,
		summary_json longtext NOT NULL,
		synopsis text NOT NULL,
		key_entities text NOT NULL,
		key_facts text NOT NULL,
		document_type varchar(50) NOT NULL DEFAULT 'general',
		tags varchar(500) DEFAULT NULL,
		search_text text NOT NULL,
		tokens_used int unsigned DEFAULT 0,
		model_used varchar(50) DEFAULT NULL,
		created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY idx_document (document_id),
		KEY idx_current (is_current),
		KEY idx_doc_type (document_type)
	) {$charset};" );

	// Add FULLTEXT separately — dbDelta doesn't handle it.
	$wpdb->suppress_errors( true );
	$wpdb->query( "ALTER TABLE {$t_idx} ADD FULLTEXT idx_search (search_text)" );
	$wpdb->suppress_errors( false );

	// v1.3.0: Content chunks table — stores raw extracted text in searchable
	// pieces so FULLTEXT search can hit actual document content, not just
	// AI-generated summaries. Critical for specific lookups like pricing.
	$t_chunks = $wpdb->prefix . 'zkv_chunks';
	dbDelta( "CREATE TABLE {$t_chunks} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		document_id bigint(20) unsigned NOT NULL,
		chunk_index int unsigned NOT NULL DEFAULT 0,
		chunk_text mediumtext NOT NULL,
		search_text mediumtext NOT NULL,
		created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY idx_document (document_id),
		KEY idx_chunk_order (document_id, chunk_index)
	) {$charset};" );

	$wpdb->suppress_errors( true );
	$wpdb->query( "ALTER TABLE {$t_chunks} ADD FULLTEXT idx_chunk_search (search_text)" );
	$wpdb->suppress_errors( false );

	$t_cats = $wpdb->prefix . 'zkv_categories';
	dbDelta( "CREATE TABLE {$t_cats} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		slug varchar(100) NOT NULL,
		label varchar(200) NOT NULL,
		description text DEFAULT NULL,
		sort_order int NOT NULL DEFAULT 100,
		created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		UNIQUE KEY idx_slug (slug)
	) {$charset};" );

	// ── v1.5.0: Private-transcript tables ─────────────────────────
	// wp_zkv_doc_parties — THE transcript ACL. One row per (document, party).
	// Party identity is a WP user ID — never a name string. The access check is
	// "does a row exist for (doc, uid)?" — nothing else. speaker_label and
	// match_method are provenance/UI only and are never consulted by the gate.
	$t_parties = $wpdb->prefix . 'zkv_doc_parties';
	dbDelta( "CREATE TABLE {$t_parties} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		document_id bigint(20) unsigned NOT NULL,
		user_id bigint(20) unsigned NOT NULL,
		speaker_label varchar(190) NOT NULL DEFAULT '',
		match_method varchar(24) NOT NULL DEFAULT '',
		created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		UNIQUE KEY uniq_doc_user (document_id, user_id),
		KEY idx_user (user_id),
		KEY idx_document (document_id)
	) {$charset};" );

	// wp_zkv_doc_shares — party-initiated, view-only grants. Two kinds:
	// scope='whole' (recipient may open/read the full doc through the normal
	// view surfaces) and scope='excerpt' (recipient is served ONLY the
	// materialized excerpt_text stored on this row — the original file/chunks
	// are never fetched into their request; redacted text is byte-absent).
	// "Active" everywhere = revoked_at IS NULL AND (expires_at IS NULL OR
	// expires_at > now). Re-checked on every read — nothing outlives a revoke.
	// Shares are NEVER consulted by the chat predicate (a share lends a view,
	// not a chat seat) and a recipient is not a party, so re-share is
	// structurally impossible (only parties may create shares).
	$t_shares = $wpdb->prefix . 'zkv_doc_shares';
	dbDelta( "CREATE TABLE {$t_shares} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		document_id bigint(20) unsigned NOT NULL,
		shared_by bigint(20) unsigned NOT NULL,
		shared_with bigint(20) unsigned NOT NULL,
		scope varchar(12) NOT NULL DEFAULT 'whole',
		excerpt_mode varchar(10) NOT NULL DEFAULT '',
		excerpt_text mediumtext,
		span_map longtext,
		expires_at datetime DEFAULT NULL,
		revoked_at datetime DEFAULT NULL,
		created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY idx_shared_with (shared_with),
		KEY idx_document (document_id),
		KEY idx_live (shared_with, document_id)
	) {$charset};" );
	// NOTE (P3): excerpt_text has NO FULLTEXT index and never feeds
	// search/context/chat — an excerpt is render-only to its recipient.

	// wp_zkv_transcript_lines — the normalized line rendition of a transcript,
	// materialized once at transcript ingest. This is the stable coordinate
	// system for excerpt selection (chunks overlap at 2000/200 and are NOT
	// selectable units) and the ±1-line context source for the admin queue.
	// Deliberately NO FULLTEXT index — lines are never searchable; all search
	// runs against the ACL-gated index/chunk tables.
	$t_lines = $wpdb->prefix . 'zkv_transcript_lines';
	dbDelta( "CREATE TABLE {$t_lines} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		document_id bigint(20) unsigned NOT NULL,
		line_no int unsigned NOT NULL DEFAULT 0,
		speaker varchar(190) NOT NULL DEFAULT '',
		line_text text,
		created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		UNIQUE KEY uniq_doc_line (document_id, line_no),
		KEY idx_document (document_id)
	) {$charset};" );

	// Outside links (ZKV_Ext_Links) - token hash only.
	if ( class_exists( 'ZKV_Ext_Links' ) || file_exists( ZKV_PLUGIN_DIR . 'includes/class-zkv-ext-links.php' ) ) {
		require_once ZKV_PLUGIN_DIR . 'includes/class-zkv-ext-links.php';
		dbDelta( ZKV_Ext_Links::schema( $charset ) );
	}

	$t_log = $wpdb->prefix . 'zkv_access_log';
	dbDelta( "CREATE TABLE {$t_log} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint(20) unsigned NOT NULL,
		action varchar(30) NOT NULL,
		document_id bigint(20) unsigned DEFAULT NULL,
		search_query varchar(500) DEFAULT NULL,
		results_count int unsigned DEFAULT NULL,
		context varchar(50) DEFAULT NULL,
		created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY idx_user (user_id),
		KEY idx_created (created_at)
	) {$charset};" );

	// Categories: Core ships EMPTY (defaults/categories.json is []). seed_defaults()
	// runs once on first activation and inserts whatever that file holds — for the
	// public distribution, nothing. A tenant creates its own categories, or an
	// Identity/knowledge pack imports them under consent. No trade-shaped defaults,
	// and NO category is ever re-inserted on upgrade (see zkv_maybe_upgrade).
	require_once ZKV_PLUGIN_DIR . 'includes/class-zkv-categories.php';
	$cat_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}zkv_categories" );
	if ( $cat_count === 0 ) {
		ZKV_Categories::seed_defaults();
	}

	// ── Create secure vault storage directory ──
	// The authenticated /vault/{slug} route is the primary access control. This
	// writes deny-all web-server rules (.htaccess / web.config) as defence-in-depth.
	zkv_create_secure_vault_dir();

	// Flag for rewrite rules flush on next init (can't flush during activation).
	set_transient( 'zkv_flush_rewrite', '1', 60 );

	update_option( 'zkv_db_version', ZKV_VERSION );
}

/**
 * Create the vault storage directory and write DEFENCE-IN-DEPTH web-server rules.
 *
 * The PRIMARY control is the authenticated /vault/{slug} route (login + app
 * grant + per-document ACL). These files add a second layer for servers that
 * honour them:
 *   .htaccess   — Apache denies all direct HTTP requests.
 *   web.config  — IIS denies all direct HTTP requests.
 *   index.php   — prevents directory listing on misconfigured servers.
 *   index.html  — prevents listing on servers that ignore index.php.
 *
 * IMPORTANT: nginx (and other non-Apache servers) IGNORE .htaccess, so these
 * files are NOT the guarantee — the route auth is. zkv_vault_protection_report()
 * surfaces a loud admin warning when the server cannot honour a file-level rule.
 * Runs on every activation/upgrade to self-heal if files are deleted.
 */
function zkv_create_secure_vault_dir() {
	$dir = ZKV_VAULT_DIR;

	if ( ! file_exists( $dir ) ) {
		wp_mkdir_p( $dir );
	}

	// Apache — deny ALL direct access.
	$htaccess_content = "# Zorderz Knowledge — DENY ALL DIRECT ACCESS\n"
		. "# Files are served through the authenticated route only.\n"
		. "# DO NOT REMOVE THIS FILE.\n\n"
		. "Order deny,allow\nDeny from all\n\n"
		. "# Also block via Apache 2.4+ syntax\n"
		. "<IfModule mod_authz_core.c>\n"
		. "  Require all denied\n"
		. "</IfModule>\n";
	file_put_contents( $dir . '/.htaccess', $htaccess_content );

	// IIS — deny ALL direct access.
	$webconfig_content = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
		. "<configuration>\n  <system.webServer>\n    <authorization>\n"
		. "      <deny users=\"*\" />\n    </authorization>\n"
		. "  </system.webServer>\n</configuration>\n";
	file_put_contents( $dir . '/web.config', $webconfig_content );

	// Prevent directory listing.
	$index_php_path = $dir . '/index.php';
	if ( ! file_exists( $index_php_path ) ) {
		file_put_contents( $index_php_path, "<?php\n// Silence is golden.\n" );
	}
	$index_html_path = $dir . '/index.html';
	if ( ! file_exists( $index_html_path ) ) {
		file_put_contents( $index_html_path, '' );
	}
}

/**
 * Report on the vault's layered protection so a misconfiguration is loud, not
 * silent. Route auth is always the primary control; this checks whether the
 * web server can ALSO honour the file-level deny rule.
 *
 * @return array{route_auth:bool,htaccess_present:bool,file_rule_effective:bool,server:string,warn:bool,message:string}
 */
function zkv_vault_protection_report() {
	$server   = isset( $_SERVER['SERVER_SOFTWARE'] ) ? (string) $_SERVER['SERVER_SOFTWARE'] : '';
	$is_apache = ( stripos( $server, 'apache' ) !== false ) || ( stripos( $server, 'litespeed' ) !== false );
	$is_iis    = ( stripos( $server, 'microsoft-iis' ) !== false );
	$htaccess_present = file_exists( ZKV_VAULT_DIR . '/.htaccess' );

	// A file-level deny is effective only where the server reads .htaccess (Apache/
	// LiteSpeed) or web.config (IIS). On nginx/unknown we cannot assume it applies.
	$file_rule_effective = $is_apache || $is_iis;

	// v1.7.0: vault files are stored under unguessable per-file random subdirectories, so a raw
	// file URL cannot be guessed even where the server ignores the .htaccess deny (e.g. nginx).
	// That, plus the authenticated /vault route, is the guarantee — so a non-Apache server is no
	// longer a security warning, only optional extra hardening.
	$report = array(
		'route_auth'          => true, // always — the serve handler enforces it
		'htaccess_present'    => $htaccess_present,
		'file_rule_effective' => $file_rule_effective,
		'server'              => $server,
		'warn'                => false,
		'message'             => '',
	);

	if ( $file_rule_effective && ! $htaccess_present ) {
		$report['warn']    = true;
		$report['message'] = __( 'Knowledge vault: the deny-all .htaccess is missing from the uploads/zkv-vault/ directory. Deactivate and reactivate the app, or ensure the directory is writable, to restore the file-level deny. The authenticated route and unguessable storage paths still protect files.', 'zorderz' );
	}

	return $report;
}

/**
 * Surface the vault-protection warning to admins (loud health check). Only
 * shown to users who can manage options, and only when there is something to fix.
 */
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! defined( 'ZKV_VAULT_DIR' ) ) {
		return;
	}
	$report = zkv_vault_protection_report();
	if ( empty( $report['warn'] ) || empty( $report['message'] ) ) {
		return;
	}
	printf(
		'<div class="notice notice-warning"><p><strong>Zorderz Knowledge:</strong> %s</p></div>',
		esc_html( $report['message'] )
	);
} );

// ── Deactivation ───────────────────────────────────────────────────
register_deactivation_hook( __FILE__, 'zkv_deactivate' );

function zkv_deactivate() {
	// Don't drop tables — prevent accidental data loss.
	// v1.4.0: stop the email-in poll (rescheduled automatically on reactivation).
	wp_clear_scheduled_hook( 'zkv_mail_poll_event' );
	wp_clear_scheduled_hook( 'zkv_private_sweep' );
}

// ── DB Upgrade ─────────────────────────────────────────────────────
add_action( 'plugins_loaded', 'zkv_maybe_upgrade', 5 );

/**
 * Ensure the Knowledge Vault schema is present and current.
 *
 * Runs on every load, independent of the stored DB version, so schema drift
 * self-heals. A version-gated migration cannot fix a column that goes missing
 * while the DB version already equals the plugin version (for example after a
 * staging DB pull from an environment that never had these columns). A missing
 * column here previously fatalled the whole site (a failed query counted or
 * iterated under PHP 8), so this must stay resilient. It is cheap on a healthy
 * install: a short-lived transient plus one sentinel SHOW COLUMNS; it only does
 * real work (ALTER or rebuild) when something is actually missing. SCHEMA ONLY,
 * it never inserts a business row.
 */
function zkv_ensure_schema() {
	global $wpdb;
	$docs = $wpdb->prefix . 'zkv_documents';

	// Fast path: recently verified and the sentinel column is still present.
	if ( get_transient( 'zkv_schema_ok' ) ) {
		$sentinel = $wpdb->get_results( "SHOW COLUMNS FROM {$docs} LIKE 'is_private'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a fixed literal; no input.
		if ( ! empty( $sentinel ) ) {
			return;
		}
		delete_transient( 'zkv_schema_ok' ); // drift detected; fall through and heal
	}

	// Whole table missing (for example a staging DB refresh): rebuild every table.
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $docs ) ) !== $docs ) {
		zkv_activate();
		set_transient( 'zkv_schema_ok', 1, HOUR_IN_SECONDS );
		return;
	}

	// Ensure the columns later versions added exist (self-heal a dropped column).
	// transcript_status is a lifecycle column ('' normal; 'suggested', 'detected',
	// 'active', 'latent', 'not_transcript'); the source of truth for "is this a
	// transcript" stays visibility='transcript_private'.
	$columns = array(
		'is_pricing_authority' => 'ADD COLUMN is_pricing_authority TINYINT(1) NOT NULL DEFAULT 0 AFTER visibility',
		'transcript_status'    => "ADD COLUMN transcript_status VARCHAR(24) NOT NULL DEFAULT '' AFTER visibility",
		// A person's title and category are theirs: the index leaves a locked one alone.
		// Existing rows stay unlocked (nobody's choice is on record for them).
		'title_locked'         => 'ADD COLUMN title_locked TINYINT(1) NOT NULL DEFAULT 0 AFTER visibility',
		'category_locked'      => 'ADD COLUMN category_locked TINYINT(1) NOT NULL DEFAULT 0 AFTER visibility',
		// A Private document (ZKV_Private) is never sent to an Ai service and its file is
		// stored encrypted. Every existing document starts ordinary (0).
		'is_private'           => 'ADD COLUMN is_private TINYINT(1) NOT NULL DEFAULT 0 AFTER visibility',
	);
	foreach ( $columns as $col => $add_sql ) {
		if ( empty( $wpdb->get_results( "SHOW COLUMNS FROM {$docs} LIKE '{$col}'" ) ) ) {
			$wpdb->query( "ALTER TABLE {$docs} {$add_sql}" );
		}
	}

	// Ensure supporting indexes exist.
	$indexes = array(
		'idx_pricing_authority' => 'ADD KEY idx_pricing_authority (is_pricing_authority)',
		'idx_transcript_status' => 'ADD KEY idx_transcript_status (transcript_status)',
		'idx_visibility'        => 'ADD KEY idx_visibility (visibility)',
		'idx_private'           => 'ADD KEY idx_private (is_private)',
	);
	foreach ( $indexes as $idx => $add_sql ) {
		if ( empty( $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM {$docs} WHERE Key_name = %s", $idx ) ) ) ) {
			$wpdb->query( "ALTER TABLE {$docs} {$add_sql}" );
		}
	}

	set_transient( 'zkv_schema_ok', 1, HOUR_IN_SECONDS );
}

function zkv_maybe_upgrade() {
	$db_ver = get_option( 'zkv_db_version', '0' );
	$needs  = version_compare( $db_ver, ZKV_VERSION, '<' );

	if ( $needs ) {
		zkv_activate();
	}

	// Always self-heal the schema, independent of the stored version (see
	// zkv_ensure_schema). This is the fix for the missing-column 502.
	zkv_ensure_schema();

	if ( $needs ) {
		// v1.3.0+ migration: backfill content chunks for existing indexed docs.
		// The chunks table was created above by zkv_activate(). Schedule a
		// background task to extract and store chunks for docs that lack them.
		if ( version_compare( $db_ver, '1.3.0', '<' ) ) {
			if ( ! wp_next_scheduled( 'zkv_backfill_chunks' ) ) {
				wp_schedule_single_event( time() + 30, 'zkv_backfill_chunks' );
			}
		}
		// The /vault/l/<token> route needs the rewrite rules flushed (on init, 99 — replacing
		// the files never re-runs activation), and the chat's cached Vault blocks may carry titles of
		// documents that are now Private: drop them once.
		if ( version_compare( $db_ver, '1.8.0', '<' ) ) {
			set_transient( 'zkv_flush_rewrite', '1', 60 );
			if ( class_exists( 'ZKV_ZANA_Bridge' ) && method_exists( 'ZKV_ZANA_Bridge', 'invalidate_cache' ) ) {
				ZKV_ZANA_Bridge::invalidate_cache();
			}
			if ( class_exists( 'ZKV_Bridge' ) && method_exists( 'ZKV_Bridge', 'invalidate_cache' ) ) {
				ZKV_Bridge::invalidate_cache();
			}
		}
		update_option( 'zkv_db_version', ZKV_VERSION );
	}
}

// ── Load all class files ───────────────────────────────────────────
add_action( 'plugins_loaded', 'zkv_load_includes' );

function zkv_load_includes() {
	$dir = ZKV_PLUGIN_DIR . 'includes/';
	foreach ( glob( $dir . '*.php' ) as $file ) {
		require_once $file;
	}
	if ( class_exists( 'ZKV_Files' ) ) { ZKV_Files::boot(); } // the access check other apps ask through
}

// Hourly - finish a "make Private" a dying request left, and remove the upload form's abandoned files.
add_action( 'zkv_private_sweep', function () {
	if ( class_exists( 'ZKV_Private' ) ) { ZKV_Private::sweep(); }
} );
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'zkv_private_sweep' ) ) { wp_schedule_event( time() + 300, 'hourly', 'zkv_private_sweep' ); }
} );

// Outside links. Priority 1 - before the theme's sign-in gate (10) and the /vault/{slug} handler.
add_action( 'template_redirect', function () {
	if ( class_exists( 'ZKV_Ext_Links' ) ) { ZKV_Ext_Links::maybe_serve(); }
}, 1 );

// Install state, not business data: the file-rename bookkeeping (paths on THIS server) and the
// notes a dying "make Private" or an abandoned upload leave for the hourly sweep never travel
// in a Company Data export or import. The wrapped Private key does travel: it opens nothing
// without this site's secret keys or the recovery code, and the recovery code needs it.
add_filter( 'zdz_export_non_portable_option_patterns', function ( $patterns ) {
	$patterns   = is_array( $patterns ) ? $patterns : array();
	$patterns[] = '/^zkv_(pend|tmpf)_/';
	$patterns[] = '/^zkv_rename_/';
	$patterns[] = '/^zkv_renamed_paths$/';
	$patterns[] = '/^zkv_names_done$/';
	return $patterns;
} );

// The Model Registry slot this app's Ai calls resolve through (ZKV_Dashboard::get_ai_model).
add_filter( 'zdz_model_slots', function ( $slots ) {
	if ( is_array( $slots ) && ! isset( $slots['knowledge'] ) ) {
		$slots['knowledge'] = array( 'tier' => 'frontier', 'purpose' => 'reads, titles and indexes knowledge documents' );
	}
	return $slots;
} );

// ── Background Indexing (WP Cron) ──────────────────────────────────
// Processes uploaded documents that need deep AI indexing.
// Scheduled per-document by the upload handler.
add_action( 'zkv_process_pending_doc', 'zkv_cron_process_document' );

function zkv_cron_process_document( $document_id ) {
	if ( class_exists( 'ZKV_Indexer' ) ) {
		ZKV_Indexer::process_document( (int) $document_id );
	}
}

// ── v1.3.0: Backfill content chunks for existing documents ───────
// Runs once after upgrade. Extracts full text from each indexed
// document and stores it in wp_zkv_chunks for FULLTEXT search.
add_action( 'zkv_backfill_chunks', 'zkv_backfill_content_chunks' );

function zkv_backfill_content_chunks() {
	if ( ! class_exists( 'ZKV_Indexer' ) ) { return; }

	global $wpdb;

	// Find indexed documents that have no chunks yet.
	$docs = $wpdb->get_results(
		"SELECT d.*
		 FROM {$wpdb->prefix}zkv_documents d
		 WHERE d.status = 'indexed' AND d.is_private = 0
		   AND d.id NOT IN (SELECT DISTINCT document_id FROM {$wpdb->prefix}zkv_chunks)
		 ORDER BY d.id ASC
		 LIMIT 50",
		ARRAY_A
	);

	if ( empty( $docs ) ) {
		error_log( 'ZKV v1.3.0: Chunk backfill complete — all documents processed.' );
		return;
	}

	$count = 0;
	foreach ( $docs as $doc ) {
		$text = ZKV_Indexer::quick_extract(
			ZKV_Indexer::resolve_file_path_public( $doc ),
			$doc['mime_type'],
			500000 // Allow up to 500K chars for chunk storage.
		);
		if ( ! empty( trim( $text ) ) && strlen( trim( $text ) ) > 50 ) {
			ZKV_Indexer::store_content_chunks( (int) $doc['id'], $text, $doc );
			$count++;
		}
	}

	error_log( 'ZKV v1.3.0: Chunk backfill batch — processed ' . $count . ' of ' . count( $docs ) . ' documents.' );

	// If there are more, schedule another batch.
	$remaining = $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->prefix}zkv_documents d
		 WHERE d.status = 'indexed' AND d.is_private = 0
		   AND d.id NOT IN (SELECT DISTINCT document_id FROM {$wpdb->prefix}zkv_chunks)"
	);
	if ( (int) $remaining > 0 ) {
		wp_schedule_single_event( time() + 10, 'zkv_backfill_chunks' );
	}
}

// ── REST API ───────────────────────────────────────────────────────
add_action( 'rest_api_init', function () {
	if ( class_exists( 'ZKV_Rest' ) ) {
		ZKV_Rest::register_routes();
	}
} );

// ── Chat bridge: feed vault knowledge into the analytics assistant ──
// v1.7.1: The analytics/Chat app (ZANA_Chat) gathers each turn's data context
// from the neutral `zdz_analytics_data_context` filter, which ships EMPTY. The
// vault's retrieval path (ZKV_ZANA_Bridge) is the ACL-aware code that turns a
// question into matched document content and a compact document inventory — but
// nothing connected that producer to the consumer seam, so an uploaded document
// could never reach the assistant. This wires them together.
//
// No new exposure: the bridge enforces visibility itself (party-only for private
// transcripts; admin_only content only for admins; everything else all_employees),
// so this only makes already-permitted, indexed documents answerable in chat. The
// text is handed back as inert data (the chat fences it), and verified_figures is
// left untouched, so any number the model repeats from a document is still held to
// the outbound Answer-Authority gate rather than stated as a confirmed figure.
add_filter(
	'zdz_analytics_data_context',
	function ( $data, $message, $user_id, $context ) {
		if ( ! class_exists( 'ZKV_ZANA_Bridge' ) ) {
			return $data;
		}
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		$uid = (int) $user_id;

		$parts = array();

		// Compact list of the documents the assistant may draw on.
		$inventory = ZKV_ZANA_Bridge::get_inventory( $uid );
		if ( is_string( $inventory ) && '' !== trim( $inventory ) ) {
			$parts[] = $inventory;
		}

		// Content matched to THIS question (ACL-scoped inside the bridge).
		$matched = ZKV_ZANA_Bridge::get_context( (string) $message, 8, $uid );
		if ( is_string( $matched ) && '' !== trim( $matched ) ) {
			$parts[] = $matched;
		}

		if ( ! empty( $parts ) ) {
			$vault_text   = implode( "\n", $parts );
			$existing     = isset( $data['text'] ) ? (string) $data['text'] : '';
			$data['text'] = ( '' !== $existing ) ? $existing . "\n\n" . $vault_text : $vault_text;
		}

		return $data;
	},
	10,
	4
);

// ── Allow additional file type uploads ────────────────────────────
// WordPress blocks unknown file types by default. This tells WP
// these formats are safe for authenticated users to upload.
add_filter( 'upload_mimes', function ( $mimes ) {
	// Markdown.
	$mimes['md']   = 'text/markdown';
	// Transcript & caption formats.
	$mimes['srt']  = 'application/x-subrip';
	$mimes['vtt']  = 'text/vtt';
	$mimes['itt']  = 'application/xml';
	$mimes['sbv']  = 'text/plain';
	$mimes['ass']  = 'text/plain';
	$mimes['ssa']  = 'text/plain';
	$mimes['sub']  = 'text/plain';
	$mimes['lrc']  = 'text/plain';
	return $mimes;
} );

// WP Engine / WordPress 5.x+ real MIME type verification can reject
// text-based files with unusual extensions. Override for our formats.
// (PHP's finfo detects .md as text/plain, not text/markdown, causing
// a type mismatch that blocks the upload.)
add_filter( 'wp_check_filetype_and_ext', function ( $data, $file, $filename, $mimes ) {
	$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
	$override_exts = array(
		'md'  => 'text/markdown',
		'srt' => 'application/x-subrip',
		'vtt' => 'text/vtt',
		'itt' => 'application/xml',
		'sbv' => 'text/plain',
		'ass' => 'text/plain',
		'ssa' => 'text/plain',
		'sub' => 'text/plain',
		'lrc' => 'text/plain',
	);
	if ( isset( $override_exts[ $ext ] ) ) {
		$data['ext']             = $ext;
		$data['type']            = $override_exts[ $ext ];
		$data['proper_filename'] = false;
	}
	return $data;
}, 10, 4 );

// ── v1.3.0: Override WordPress upload size for vault uploads ──────
// WordPress may have a 2MB or 8MB default upload_max_filesize.
// Knowledge Vault needs up to 50MB for supplier PDFs, pricing sheets, etc.
// This filter raises the WP-side limit; the server's php.ini must also
// allow it (upload_max_filesize ≥ 50M, post_max_size ≥ 55M).
// Only applies during vault upload AJAX actions.
add_filter( 'upload_size_limit', function ( $size ) {
	if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
		$action = $_REQUEST['action'] ?? '';
		if ( in_array( $action, array( 'zkv_preanalyze', 'zkv_upload_document', 'zkv_upload_from_chat' ), true ) ) {
			return max( $size, ZKV_MAX_UPLOAD_BYTES );
		}
	}
	return $size;
} );

// ── Admin Page ────────────────────────────────────────────────────
add_action( 'admin_menu', function () {
	add_menu_page(
		'Knowledge Vault',
		'Knowledge Vault',
		'manage_options',
		'zkv-settings',
		'zkv_render_admin_page',
		'dashicons-book-alt',
		58
	);
} );

function zkv_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Access denied.' );
	}

	// Handle form submission.
	if ( isset( $_POST['zkv_save_settings'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'zkv_admin_settings' ) ) {
		$api_key = sanitize_text_field( $_POST['zkv_api_key'] ?? '' );
		$model   = sanitize_text_field( wp_unslash( $_POST['zkv_ai_model'] ?? '' ) );
		if ( class_exists( 'ZKV_Dashboard' ) ) {
			$model = ZKV_Dashboard::approved_model( $model ); // only an approved model is stored
		}

		// Managed (or sent from a form that showed it managed): the row belongs to the Credentials screen.
		$poe_managed = class_exists( 'ZKV_Dashboard' ) && ( ZKV_Dashboard::poe_managed() || ZKV_Dashboard::managed_posted( 'poe' ) );
		if ( ! $poe_managed && ! empty( $api_key ) ) {
			if ( class_exists( 'ZKV_Dashboard' ) ) {
				$encrypted = ZKV_Dashboard::encrypt_key( $api_key );
				update_option( 'zkv_poe_api_key', $encrypted ?: $api_key );
			} else {
				update_option( 'zkv_poe_api_key', $api_key );
			}
		}
		update_option( 'zkv_ai_model', $model );
		echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
	}

	// v1.4.0 — Email-In (Microsoft 365) settings: Save / Save & Test / Check now.
	// All three buttons save the posted fields first (secret blank = keep existing).
	if ( class_exists( 'ZKV_Mailbox' )
		&& ( isset( $_POST['zkv_mail_save'] ) || isset( $_POST['zkv_mail_test'] ) || isset( $_POST['zkv_mail_poll_now'] ) )
		&& wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'zkv_mail_settings' ) ) {

		// While the mail-in app is managed on Zorderz → Credentials
		// (or the form said so), tenant / client id / secret are the theme's: not in the patch
		// (update_config keeps the stored values) and the secret is never written.
		$mail_app_locked = class_exists( 'ZKV_Dashboard' ) && ZKV_Dashboard::mail_app_locked();
		$mail_patch = array(
			'mailbox'   => sanitize_email( $_POST['zkv_mail_mailbox'] ?? '' ),
			'enabled'   => ! empty( $_POST['zkv_mail_enabled'] ),
		);
		if ( ! $mail_app_locked ) {
			$mail_patch['tenant_id'] = sanitize_text_field( wp_unslash( $_POST['zkv_mail_tenant'] ?? '' ) );
			$mail_patch['client_id'] = sanitize_text_field( wp_unslash( $_POST['zkv_mail_client'] ?? '' ) );
		}
		ZKV_Mailbox::update_config( $mail_patch );
		$posted_secret = trim( (string) wp_unslash( $_POST['zkv_mail_secret'] ?? '' ) );
		if ( ! $mail_app_locked && '' !== $posted_secret ) {
			ZKV_Mailbox::set_secret( $posted_secret );
		}
		ZKV_Mailbox::maybe_schedule();
		echo '<div class="notice notice-success"><p>Email-in settings saved.</p></div>';

		if ( isset( $_POST['zkv_mail_test'] ) ) {
			$t = ZKV_Mailbox::test_connection();
			echo '<div class="notice notice-' . ( $t['ok'] ? 'success' : 'error' ) . '"><p>' . esc_html( $t['message'] ) . '</p></div>';
		}
		if ( isset( $_POST['zkv_mail_poll_now'] ) ) {
			$p = ZKV_Mailbox::poll( true );
			if ( ! empty( $p['ran'] ) ) {
				$msg = sprintf( 'Checked the mailbox: %d filed, %d duplicate, %d rejected, %d failed.',
					$p['stored'], $p['duplicates'], $p['rejected'], $p['failed'] );
				if ( ! empty( $p['errors'] ) ) { $msg .= ' Errors: ' . implode( ' · ', array_slice( $p['errors'], 0, 3 ) ); }
				echo '<div class="notice notice-' . ( $p['failed'] > 0 ? 'warning' : 'success' ) . '"><p>' . esc_html( $msg ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error"><p>' . esc_html( 'Could not check the mailbox: ' . implode( ' · ', $p['errors'] ) ) . '</p></div>';
			}
		}
	}

	// Get current settings.
	$current_key = '';
	$poe_set     = false;
	$poe_managed = class_exists( 'ZKV_Dashboard' ) && ZKV_Dashboard::poe_managed();
	if ( $poe_managed ) {
		$poe_set = '' !== (string) ZKV_Dashboard::get_poe_api_key(); // managed: no part of the key is shown
	} elseif ( class_exists( 'ZKV_Dashboard' ) ) {
		$k = ZKV_Dashboard::get_poe_api_key();
		if ( ! empty( $k ) ) { $current_key = substr( $k, 0, 8 ) . '...' . substr( $k, -4 ); }
	}
	$model         = (string) get_option( 'zkv_ai_model', '' );
	$model_used    = class_exists( 'ZKV_Dashboard' ) ? ZKV_Dashboard::get_ai_model() : '';
	$model_choices = class_exists( 'ZKV_Dashboard' ) ? ZKV_Dashboard::model_choices() : array();
	$has_own = ! empty( get_option( 'zkv_poe_api_key', '' ) );

	// Get document stats.
	global $wpdb;
	$total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}zkv_documents" );
	$indexed  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}zkv_documents WHERE status = 'indexed'" );
	$pending  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}zkv_documents WHERE status IN ('pending','processing')" );
	$failed   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}zkv_documents WHERE status = 'failed'" );

	$docs = $wpdb->get_results(
		"SELECT d.*, c.label as category_label, u.display_name as uploader_name
		 FROM {$wpdb->prefix}zkv_documents d
		 LEFT JOIN {$wpdb->prefix}zkv_categories c ON d.category_id = c.id
		 LEFT JOIN {$wpdb->users} u ON d.uploaded_by = u.ID
		 ORDER BY d.created_at DESC LIMIT 50",
		ARRAY_A
	);

	?>
	<div class="wrap">
		<h1>Knowledge Vault</h1>

		<div style="display:flex;gap:20px;margin:20px 0;">
			<div class="card" style="flex:1;padding:15px;"><h3 style="margin:0 0 5px;"><?php echo $total; ?></h3><p style="margin:0;color:#666;">Total Documents</p></div>
			<div class="card" style="flex:1;padding:15px;"><h3 style="margin:0 0 5px;color:#059669;"><?php echo $indexed; ?></h3><p style="margin:0;color:#666;">Indexed</p></div>
			<div class="card" style="flex:1;padding:15px;"><h3 style="margin:0 0 5px;color:#D97706;"><?php echo $pending; ?></h3><p style="margin:0;color:#666;">Pending</p></div>
			<div class="card" style="flex:1;padding:15px;"><h3 style="margin:0 0 5px;color:#DC2626;"><?php echo $failed; ?></h3><p style="margin:0;color:#666;">Failed</p></div>
		</div>

		<div class="card" style="max-width:600px;padding:20px;margin-bottom:20px;">
			<h2>AI Settings</h2>
			<form method="post">
				<?php wp_nonce_field( 'zkv_admin_settings' ); ?>
				<table class="form-table">
					<tr>
						<th>Poe API Key</th>
						<td>
							<?php if ( $poe_managed ) : // no key field, no part of the key ?>
							<input type="hidden" name="zdz_cred_managed[poe]" value="1">
							<p><?php echo ZKV_Dashboard::managed_note_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped where built ?></p>
							<p class="description">Poe: <?php echo $poe_set ? 'configured' : 'not set'; ?></p>
							<?php else : ?>
							<input type="password" name="zkv_api_key" class="regular-text" placeholder="sk-poe-..." autocomplete="new-password" />
							<p class="description">
								<?php if ( ! empty( $current_key ) ) : ?>
									Current: <code><?php echo esc_html( $current_key ); ?></code>
									(<?php echo esc_html( ZKV_Dashboard::key_source() ); ?>)
								<?php else : ?>
									No API key configured. Get one at <a href="https://poe.com/api/keys" target="_blank" rel="noopener">poe.com/api/keys</a>
								<?php endif; ?>
							</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th>AI Model</th>
						<td>
							<?php if ( $model_choices ) : // the models the business offers (Model Registry roster) ?>
							<select name="zkv_ai_model">
								<option value="" <?php selected( $model, '' ); ?>>Platform model</option>
								<?php foreach ( $model_choices as $handle => $label ) : ?>
								<option value="<?php echo esc_attr( $handle ); ?>" <?php selected( $model, $handle ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<?php else : // no roster: type the handle your gateway uses, or leave blank for the platform model ?>
							<input type="text" name="zkv_ai_model" class="regular-text" value="<?php echo esc_attr( $model ); ?>" placeholder="Platform model" />
							<?php endif; ?>
							<p class="description">In use: <code><?php echo esc_html( '' !== $model_used ? $model_used : 'none configured' ); ?></code></p>
						</td>
					</tr>
				</table>
				<p><button type="submit" name="zkv_save_settings" class="button button-primary">Save Settings</button></p>
			</form>
		</div>

		<?php if ( class_exists( 'ZKV_Mailbox' ) ) :
			$mail_cfg    = ZKV_Mailbox::get_config();
			$mail_status = ZKV_Mailbox::status();
			$mail_creds  = ZKV_Mailbox::effective_credentials();
			$mail_app_managed = class_exists( 'ZKV_Dashboard' ) && ZKV_Dashboard::cred_managed( 'ms_vault_mail' );
		?>
		<div class="card" style="max-width:600px;padding:20px;margin-bottom:20px;">
			<h2>Email-In — Forward Mail to the Vault (Microsoft 365)</h2>
			<p class="description" style="margin-bottom:10px;">
				Staff forward an email to the address below and it is filed into the vault automatically:
				the AI picks a category, tags it "<?php echo esc_html( class_exists( 'ZKV_Email_Ingest' ) ? ZKV_Email_Ingest::TAG_LABEL : 'Email Correspondence' ); ?>", records who sent it,
				and replies with a confirmation link. Only emails from active staff accounts are accepted.
				Requires the Azure app permission <code>Mail.Read</code> (Application, admin-consented).
			</p>
			<p style="margin:0 0 10px;">
				<strong>Status:</strong>
				<?php if ( $mail_status['enabled'] && $mail_status['configured'] ) : ?>
					<span style="color:#059669;font-weight:600;">Active</span> — polling every 5 minutes.
				<?php elseif ( $mail_status['configured'] ) : ?>
					<span style="color:#D97706;font-weight:600;">Configured but disabled</span>
				<?php else : ?>
					<span style="color:#DC2626;font-weight:600;">Not configured</span>
				<?php endif; ?>
				<?php if ( 'scheduler' === $mail_creds['source'] ) : ?>
					<br><em>Using the Scheduler's Microsoft connection — add <code>Mail.Read</code> to that same Azure app.</em>
				<?php endif; ?>
				<?php if ( ! empty( $mail_status['last_poll'] ) ) : ?>
					<br>Last check: <?php echo esc_html( $mail_status['last_poll'] ); ?> — <?php echo esc_html( $mail_status['last_result'] ); ?>
				<?php endif; ?>
			</p>
			<form method="post">
				<?php wp_nonce_field( 'zkv_mail_settings' ); ?>
				<table class="form-table">
					<tr>
						<th>Vault mailbox</th>
						<td>
							<input type="email" name="zkv_mail_mailbox" class="regular-text" placeholder="documents@yourdomain.com"
								value="<?php echo esc_attr( $mail_cfg['mailbox'] ); ?>" />
							<p class="description">The dedicated address staff forward to (a free shared mailbox works).</p>
						</td>
					</tr>
					<?php if ( $mail_app_managed ) : // no tenant / client id / secret fields ?>
					<tr>
						<th>Microsoft 365 app</th>
						<td>
							<input type="hidden" name="zdz_cred_managed[ms_vault_mail]" value="1">
							<p><?php echo ZKV_Dashboard::managed_app_note_html(); // phpcs:ignore -- built with esc_url ?></p>
						</td>
					</tr>
					<?php else : ?>
					<tr>
						<th>Directory (tenant) ID</th>
						<td><input type="text" name="zkv_mail_tenant" class="regular-text" value="<?php echo esc_attr( $mail_cfg['tenant_id'] ); ?>"
							placeholder="<?php echo 'scheduler' === $mail_creds['source'] ? 'using Scheduler credentials' : ''; ?>" /></td>
					</tr>
					<tr>
						<th>Application (client) ID</th>
						<td><input type="text" name="zkv_mail_client" class="regular-text" value="<?php echo esc_attr( $mail_cfg['client_id'] ); ?>"
							placeholder="<?php echo 'scheduler' === $mail_creds['source'] ? 'using Scheduler credentials' : ''; ?>" /></td>
					</tr>
					<tr>
						<th>Client secret</th>
						<td>
							<input type="password" name="zkv_mail_secret" class="regular-text" autocomplete="new-password"
								placeholder="<?php echo ZKV_Mailbox::has_secret() ? '•••••• (saved — leave blank to keep)' : ''; ?>" />
							<p class="description">Stored in its own isolated option; read only server-side. Leave blank to keep the saved value.</p>
						</td>
					</tr>
					<?php endif; ?>
					<tr>
						<th>Enable email-in</th>
						<td><label><input type="checkbox" name="zkv_mail_enabled" value="1" <?php checked( ! empty( $mail_cfg['enabled'] ) ); ?> />
							Poll the mailbox every 5 minutes</label></td>
					</tr>
				</table>
				<p>
					<button type="submit" name="zkv_mail_save" class="button button-primary">Save</button>
					<button type="submit" name="zkv_mail_test" class="button">Save &amp; Test Connection</button>
					<button type="submit" name="zkv_mail_poll_now" class="button">Check Mailbox Now</button>
				</p>
			</form>
		</div>
		<?php endif; ?>

		<div class="card" style="padding:20px;">
			<h2>Documents</h2>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width:30%;">Title</th>
						<th>Category</th>
						<th>Status</th>
						<th>Uploaded By</th>
						<th>Date</th>
						<th>Size</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $docs ) ) : ?>
						<tr><td colspan="6">No documents uploaded yet.</td></tr>
					<?php else : ?>
						<?php foreach ( $docs as $doc ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $doc['title'] ); ?></strong><br><small><?php echo esc_html( $doc['original_name'] ); ?></small><?php if ( ! empty( $doc['user_context'] ) ) : ?><br><small style="color:#2C5F8A;"><?php echo esc_html( substr( $doc['user_context'], 0, 80 ) ); ?><?php echo strlen( $doc['user_context'] ) > 80 ? '...' : ''; ?></small><?php endif; ?></td>
							<td><?php echo esc_html( $doc['category_label'] ?? 'General' ); ?></td>
							<td>
								<?php
								$status_colors = array( 'indexed' => '#059669', 'pending' => '#D97706', 'processing' => '#2563EB', 'failed' => '#DC2626' );
								$color = $status_colors[ $doc['status'] ] ?? '#666';
								echo '<span style="color:' . $color . ';font-weight:600;">' . ucfirst( esc_html( $doc['status'] ) ) . '</span>';
								?>
							</td>
							<td><?php echo esc_html( $doc['uploader_name'] ?? 'Unknown' ); ?></td>
							<td><?php echo date( 'M j, Y', strtotime( $doc['created_at'] ) ); ?></td>
							<td><?php echo size_format( (int) $doc['file_size'] ); ?></td>
						</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>
	<?php
}

// ── Pretty URL Routing: /vault/{slug} ─────────────────────────────
// Registers a rewrite rule so {home_url}/vault/{slug}
// routes through WordPress (which means the theme's template_redirect
// login gate protects it automatically — no non-logged-in access).

add_action( 'init', function () {
	// v1.5.0: excerpt route (two path segments — cannot collide with the
	// single-segment slug rule below, whose char class excludes '/').
	add_rewrite_rule(
		'^vault/excerpt/([0-9]+)/?$',
		'index.php?zkv_excerpt_id=$matches[1]',
		'top'
	);
	add_rewrite_tag( '%zkv_excerpt_id%', '([0-9]+)' );
	add_rewrite_rule(
		'^vault/([a-z0-9-]+)/?$',
		'index.php?zkv_doc_slug=$matches[1]',
		'top'
	);
	add_rewrite_tag( '%zkv_doc_slug%', '([a-z0-9-]+)' );

	// v1.5.2 (KV1): "Shared with me" landing page. Registered LAST so it prepends
	// ahead of the generic single-segment slug rule (which would otherwise capture
	// 'shared' as a document slug). This page is reachable WITHOUT the coarse
	// knowledge-vault app grant — it is how a shared-with recipient who does not
	// have the vault app (e.g. a crew member without it) finds and opens what was shared with them.
	add_rewrite_rule( '^vault/shared/?$', 'index.php?zkv_shared=1', 'top' );
	add_rewrite_tag( '%zkv_shared%', '([0-9]+)' );
	// /vault/l/<token>[/file] - a link for someone outside, for 14 or 30 days.
	if ( class_exists( 'ZKV_Ext_Links' ) ) { ZKV_Ext_Links::rewrite(); }
} );

// ── v1.5.0: Excerpt view — /vault/excerpt/{share_id} ──────────────
// Serves ONLY the materialized excerpt_text stored on an ACTIVE excerpt
// share addressed to the logged-in user. It never joins back to the
// document's file, chunks, or index — the unshared text is not merely
// hidden from this response, it is not present anywhere in it (P3).
// Response hygiene borrows ZDZ_Share_Link (rate limit, private headers,
// silent 404) but authorization is the live DB grant re-checked on every
// request — never a token.
add_action( 'template_redirect', function () {
	$share_id = (int) get_query_var( 'zkv_excerpt_id', 0 );
	if ( $share_id <= 0 ) {
		return; // Not an excerpt URL.
	}

	$deny = function () {
		if ( class_exists( 'ZDZ_Share_Link' ) ) {
			ZDZ_Share_Link::not_found( false );
		}
		status_header( 404 );
		echo 'Not found.';
		exit;
	};

	if ( ! is_user_logged_in() ) {
		ZKV_Viewer::remember_and_login( '/vault/excerpt/' . $share_id ); // signing in comes back here
	}
	// v1.5.2 (KV1): NO coarse knowledge-vault app gate here. An excerpt recipient
	// (e.g. a named party or a teammate who does NOT have the vault app) must be
	// able to open the excerpt shared with them. Authorization is the live per-user
	// excerpt-share grant re-checked below (active_excerpt_share), which serves ONLY
	// the materialized excerpt_text and never the document/file/chunks — so this
	// grants no other reach into the vault. Login is still required (above).
	if ( class_exists( 'ZDZ_Share_Link' ) && ! ZDZ_Share_Link::rate_ok( 'zkv-excerpt' ) ) {
		$deny(); // Blunt id enumeration; 404 not 429.
	}
	if ( ! class_exists( 'ZKV_ACL' ) ) {
		$deny(); // Fail closed.
	}

	$share = ZKV_ACL::active_excerpt_share( $share_id, get_current_user_id() );
	if ( ! $share ) {
		$deny(); // No such share / not yours / revoked / expired — all identical.
	}

	global $wpdb;
	$doc = $wpdb->get_row( $wpdb->prepare(
		"SELECT title FROM {$wpdb->prefix}zkv_documents WHERE id = %d",
		(int) $share['document_id']
	), ARRAY_A );
	$sharer = get_userdata( (int) $share['shared_by'] );

	ZKV_ACL::log( 'excerpt_viewed', (int) $share['document_id'], get_current_user_id(),
		'share=' . $share_id );

	if ( class_exists( 'ZDZ_Share_Link' ) ) {
		ZDZ_Share_Link::send_private_headers();
	} else {
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Cache-Control: private, no-store, max-age=0' );
	}
	header( 'Content-Type: text/html; charset=utf-8' );

	$title   = $doc ? $doc['title'] : 'Transcript excerpt';
	$by      = $sharer ? $sharer->display_name : 'a colleague';
	$expires = ! empty( $share['expires_at'] )
		? date_i18n( get_option( 'date_format', 'M j, Y' ), strtotime( $share['expires_at'] ) )
		: '';
	?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="robots" content="noindex, nofollow">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Excerpt — <?php echo esc_html( $title ); ?></title>
<style>
	body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;margin:0;background:#f4f5f7;color:#1f2937;}
	.zkv-x-wrap{max-width:760px;margin:0 auto;padding:24px 16px 64px;}
	.zkv-x-banner{background:#eef2ff;border:1px solid #c7d2fe;border-radius:10px;padding:12px 16px;font-size:14px;color:#3730a3;margin-bottom:18px;}
	.zkv-x-title{font-size:20px;font-weight:700;margin:0 0 4px;}
	.zkv-x-meta{font-size:13px;color:#6b7280;margin-bottom:18px;}
	.zkv-x-body{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px 22px;white-space:pre-wrap;line-height:1.55;font-size:15px;}
	.zkv-x-body .redacted{color:#9ca3af;font-style:italic;}
	@media (prefers-color-scheme: dark){
		body{background:#111827;color:#e5e7eb;}
		.zkv-x-banner{background:#1e1b4b;border-color:#3730a3;color:#c7d2fe;}
		.zkv-x-body{background:#1f2937;border-color:#374151;}
		.zkv-x-meta{color:#9ca3af;}
	}
</style>
</head><body><div class="zkv-x-wrap">
	<div class="zkv-x-banner">Excerpt shared with you by <?php echo esc_html( $by ); ?> · view only<?php echo $expires ? ' · expires ' . esc_html( $expires ) : ''; ?>. This is the shared portion only — there is no full-document link.</div>
	<h1 class="zkv-x-title">Excerpt from &ldquo;<?php echo esc_html( $title ); ?>&rdquo;</h1>
	<div class="zkv-x-meta">Private transcript excerpt · shared <?php echo esc_html( date_i18n( get_option( 'date_format', 'M j, Y' ), strtotime( $share['created_at'] ) ) ); ?></div>
	<div class="zkv-x-body"><?php
		// The ONLY content source is the materialized excerpt_text.
		$out = esc_html( (string) $share['excerpt_text'] );
		$out = str_replace( '[redacted]', '<span class="redacted">[redacted]</span>', $out );
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above; marker span re-inserted after escaping.
	?></div>
</div></body></html><?php
	exit;
} );

// ── v1.5.2 (KV1): "Shared with me" — /vault/shared ────────────────
// A coarse-grant-free landing page: lists every transcript the logged-in user is a
// party to or holds an active WHOLE-doc share on (each opens /vault/{slug}), plus
// every active EXCERPT shared with them (each opens /vault/excerpt/{id}). This is how
// a shared-with recipient who does NOT have the vault app (e.g. a crew member without it) finds and opens
// what was shared. Authorization is entirely per-item — this page only ever lists
// grants the user already holds (the same ACL the serve gates re-check on open).
add_action( 'template_redirect', function () {
	if ( (int) get_query_var( 'zkv_shared', 0 ) !== 1 ) {
		return; // Not the /vault/shared URL.
	}
	if ( ! is_user_logged_in() ) {
		ZKV_Viewer::remember_and_login( '/vault/shared' );
	}
	if ( ! class_exists( 'ZKV_ACL' ) ) {
		status_header( 404 ); echo 'Not found.'; exit; // Fail closed.
	}

	global $wpdb;
	$uid = get_current_user_id();

	// Whole-document access = party OR active whole-share (both open /vault/{slug}).
	$party_ids = ZKV_ACL::party_doc_ids( $uid );
	$party_set = array_flip( array_map( 'intval', $party_ids ) );
	$whole_ids = array_values( array_unique( array_merge(
		$party_ids, ZKV_ACL::whole_share_doc_ids( $uid )
	) ) );

	$whole_docs = array();
	if ( ! empty( $whole_ids ) ) {
		$in = implode( ',', array_map( 'intval', $whole_ids ) );
		$whole_docs = $wpdb->get_results(
			"SELECT id, slug, title FROM {$wpdb->prefix}zkv_documents
			 WHERE id IN ({$in}) AND visibility = 'transcript_private'
			 ORDER BY updated_at DESC", ARRAY_A
		);
	}

	$excerpts = ZKV_ACL::excerpt_shares_for( $uid );
	$ex_titles = array();
	if ( ! empty( $excerpts ) ) {
		$ex_ids = implode( ',', array_map( function ( $e ) { return (int) $e['document_id']; }, $excerpts ) );
		$rows   = $wpdb->get_results(
			"SELECT id, title FROM {$wpdb->prefix}zkv_documents WHERE id IN ({$ex_ids})", ARRAY_A
		);
		foreach ( (array) $rows as $r ) { $ex_titles[ (int) $r['id'] ] = $r['title']; }
	}

	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	header( 'Cache-Control: private, no-store, max-age=0' );
	$total = count( $whole_docs ) + count( $excerpts );
	?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="robots" content="noindex, nofollow">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Shared with me — Knowledge Vault</title>
<style>
	body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;margin:0;background:#f4f5f7;color:#1f2937;}
	.zkv-s-wrap{max-width:760px;margin:0 auto;padding:24px 16px 64px;}
	.zkv-s-head{font-size:22px;font-weight:700;margin:0 0 4px;}
	.zkv-s-sub{font-size:13px;color:#6b7280;margin:0 0 20px;}
	.zkv-s-item{display:block;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px 16px;margin-bottom:12px;text-decoration:none;color:inherit;}
	.zkv-s-item:hover{border-color:#a5b4fc;}
	.zkv-s-title{font-size:16px;font-weight:600;margin:0 0 3px;}
	.zkv-s-badge{display:inline-block;font-size:11px;font-weight:600;letter-spacing:.02em;text-transform:uppercase;padding:2px 8px;border-radius:999px;background:#eef2ff;color:#3730a3;margin-right:6px;}
	.zkv-s-badge.excerpt{background:#fef3c7;color:#92400e;}
	.zkv-s-meta{font-size:12px;color:#6b7280;}
	.zkv-s-empty{background:#fff;border:1px dashed #d1d5db;border-radius:12px;padding:28px 20px;text-align:center;color:#6b7280;font-size:14px;}
	@media (prefers-color-scheme: dark){
		body{background:#111827;color:#e5e7eb;}
		.zkv-s-item{background:#1f2937;border-color:#374151;}
		.zkv-s-sub,.zkv-s-meta{color:#9ca3af;}
		.zkv-s-empty{background:#1f2937;border-color:#374151;color:#9ca3af;}
		.zkv-s-badge{background:#1e1b4b;color:#c7d2fe;}
		.zkv-s-badge.excerpt{background:#3f2d0a;color:#fcd34d;}
	}
</style>
</head><body><div class="zkv-s-wrap">
	<h1 class="zkv-s-head">Shared with me</h1>
	<p class="zkv-s-sub">Transcripts you're a named party to, or that a colleague has shared with you. View only.</p>
	<?php if ( $total === 0 ) : ?>
		<div class="zkv-s-empty">Nothing has been shared with you yet.</div>
	<?php else : ?>
		<?php foreach ( $whole_docs as $d ) :
			$is_party = isset( $party_set[ (int) $d['id'] ] );
			$url = home_url( '/vault/' . $d['slug'] ); ?>
			<a class="zkv-s-item" href="<?php echo esc_url( $url ); ?>">
				<div class="zkv-s-title"><?php echo esc_html( $d['title'] ); ?></div>
				<span class="zkv-s-badge"><?php echo $is_party ? 'You&rsquo;re a party' : 'Shared with you'; ?></span>
				<span class="zkv-s-meta">Full transcript</span>
			</a>
		<?php endforeach; ?>
		<?php foreach ( $excerpts as $e ) :
			$title = $ex_titles[ (int) $e['document_id'] ] ?? 'Transcript excerpt';
			$url   = home_url( '/vault/excerpt/' . (int) $e['id'] );
			$exp   = ! empty( $e['expires_at'] ) ? date_i18n( get_option( 'date_format', 'M j, Y' ), strtotime( $e['expires_at'] ) ) : ''; ?>
			<a class="zkv-s-item" href="<?php echo esc_url( $url ); ?>">
				<div class="zkv-s-title"><?php echo esc_html( $title ); ?></div>
				<span class="zkv-s-badge excerpt">Excerpt</span>
				<span class="zkv-s-meta">Selected portion only<?php echo $exp ? ' · expires ' . esc_html( $exp ) : ''; ?></span>
			</a>
		<?php endforeach; ?>
	<?php endif; ?>
</div></body></html><?php
	exit;
} );

// Flush rewrite rules once after plugin activation (via transient flag).
add_action( 'init', function () {
	if ( get_transient( 'zkv_flush_rewrite' ) ) {
		flush_rewrite_rules();
		delete_transient( 'zkv_flush_rewrite' );
	}
}, 99 );

// Serve the document when /vault/{slug} is hit by a logged-in user.
add_action( 'template_redirect', function () {
	$slug = get_query_var( 'zkv_doc_slug', '' );
	if ( empty( $slug ) ) {
		return; // Not a vault URL — let WordPress handle normally.
	}

	// The theme's login gate fires at priority 10 on template_redirect
	// and blocks non-logged-in users BEFORE we get here. But double-check.
	if ( ! is_user_logged_in() ) {
		// Remember the document so signing in comes back to it.
		ZKV_Viewer::remember_and_login( '/vault/' . sanitize_title( $slug ) );
	}

	global $wpdb;
	$slug = sanitize_title( $slug );
	$doc  = $wpdb->get_row( $wpdb->prepare(
		"SELECT id, slug, title, file_url, attachment_id, original_name, mime_type, visibility, is_private FROM {$wpdb->prefix}zkv_documents WHERE slug = %s",
		$slug
	), ARRAY_A );

	// v1.5.2 (KV1) — SCOPED transcript access. A named party or the holder of an
	// active WHOLE-document share may open THEIR transcript even without the coarse
	// knowledge-vault app grant. This is the ONLY bypass of that grant; it is limited
	// to transcript rows the ACL admits and grants NO reach into the general vault
	// (normal documents still require the app grant immediately below, and the
	// dashboard/search endpoints stay coarse-gated). Excerpt sharees never reach this
	// raw file — they get their materialized excerpt at /vault/excerpt/{id}.
	$is_transcript = $doc && class_exists( 'ZKV_ACL' )
		&& ZKV_ACL::is_transcript_visibility( $doc['visibility'] );
	$acl_transcript_ok = $is_transcript
		&& ZKV_ACL::can_view_whole( get_current_user_id(), (int) $doc['id'] );

	// Coarse app-access gate — required for everything EXCEPT an ACL-authorized
	// transcript view. A user with neither the app grant nor an admitting ACL row
	// gets the same 403 as before, for a normal OR an absent slug (no new existence
	// disclosure).
	if ( ! $acl_transcript_ok && ! zkv_user_has_access() ) {
		status_header( 403 );
		echo 'Access denied.';
		exit;
	}

	if ( ! $doc ) {
		status_header( 404 );
		echo 'Document not found.';
		exit;
	}

	// v1.5.0/1.5.2: Private-transcript scoping — MUST come before the admin_only
	// branch and deliberately has NO admin bypass: the only keys are party membership
	// or an active whole-document share (ZKV_ACL). A non-party/non-sharee (admins
	// included) gets the same silent 404 as a nonexistent slug (P2 — denial is
	// indistinguishable from "does not exist").
	if ( $is_transcript ) {
		if ( ! $acl_transcript_ok ) {
			status_header( 404 );
			echo 'Document not found.'; // Silent scoping — don't reveal it exists
			exit;
		}
		// Party or active whole-doc sharee → fall through and serve the file.
	} elseif ( 'transcript_private' === $doc['visibility'] ) {
		// ACL class somehow absent → fail CLOSED, never open.
		status_header( 404 );
		echo 'Document not found.';
		exit;
	}

	// Visibility check (silent scoping).
	if ( $doc['visibility'] === 'admin_only' ) {
		$is_admin = false;
		if ( class_exists( 'ZDZ_User_Roles' ) ) {
			$user  = wp_get_current_user();
			$roles = (array) $user->roles;
			$role  = ! empty( $roles ) ? $roles[0] : '';
			$is_admin = ZDZ_User_Roles::is_admin_role( $role );
		} else {
			$is_admin = current_user_can( 'manage_options' );
		}
		if ( ! $is_admin ) {
			status_header( 404 );
			echo 'Document not found.'; // Silent scoping — don't reveal it exists
			exit;
		}
	}

	// Find the file the way the indexer does (a stored path, a stored uploads URL, the media
	// attachment, the record of a random rename), plus a re-base onto this server's uploads
	// folder when the stored absolute path names an older server location.
	$file_path = ZKV_Viewer::locate( $doc );
	if ( '' === $file_path ) {
		if ( ! ZKV_Viewer::wants_file() ) {
			ZKV_Viewer::render( $doc, '' ); // the viewer says the file is missing and shows the text the Vault kept
		}
		error_log( 'ZKV: /vault/ file not found for doc #' . (int) $doc['id'] . ' (stored path does not resolve on this server).' );
		status_header( 404 );
		echo 'File not found.';
		exit;
	}

	// Path traversal check — inside the vault folder or uploads, with the folder separator
	// required after the root (ZKV_File_Names::is_allowed_path).
	$real = realpath( $file_path );
	if ( ! $real || ! ZKV_File_Names::is_allowed_path( $real ) ) {
		status_header( 403 );
		exit;
	}

	// A Private document opens only with its key; locked (the site's keys changed) says so.
	if ( ! empty( $doc['is_private'] ) && ( ! class_exists( 'ZKV_Private' ) || ! ZKV_Private::is_encrypted_file( $real ) || null === ZKV_Private::key( false ) ) ) {
		status_header( 503 );
		ZKV_Viewer::private_headers();
		echo 'This Private document is locked. An administrator can unlock it with the recovery code (Knowledge settings).';
		exit;
	}

	// A used "come back here" cookie is spent.
	if ( class_exists( 'ZDZ_Login_Next' ) && isset( $_COOKIE[ ZDZ_Login_Next::KEY ] ) ) {
		ZDZ_Login_Next::forget();
	}

	// The address itself is the viewer page; the file is ?raw=1 (&dl=1 downloads it).
	if ( ! ZKV_Viewer::wants_file() ) {
		ZKV_Viewer::render( $doc, $real );
	}

	// Serve the file.
	$mime = $doc['mime_type'] ?: 'application/octet-stream';
	$name = $doc['original_name'] ?: basename( $file_path );

	ZKV_Viewer::private_headers(); // no-store, noindex, same-site framing, nosniff
	if ( ZKV_Viewer::wants_download() ) {
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $name ) . '"' );
	} else {
		zkv_serve_file_headers( $mime, $name ); // Content-Type + nosniff + safe Content-Disposition
	}
	// A Private document's file is decrypted on the way out, never written anywhere in plain.
	if ( ! empty( $doc['is_private'] ) ) {
		if ( ZKV_Private::stream( $real ) < 0 ) {
			error_log( 'ZKV: Private doc #' . (int) $doc['id'] . ' did not decrypt whole (file damaged).' );
		}
		exit;
	}
	header( 'Content-Length: ' . filesize( $real ) );
	readfile( $real );
	exit;
} );

// ══════════════════════════════════════════════════════════════════
// ZORDERZ THEME INTEGRATION
// ══════════════════════════════════════════════════════════════════
add_action( 'after_setup_theme', function () {

	// ── TIER 2: Inline widget (theme v2.0+) ──
	if ( interface_exists( '\Zorderz\Widget_App_Interface' ) ) {

		class ZKV_App implements \Zorderz\Widget_App_Interface {

			public function get_config(): array {
				return [
					'id'          => 'knowledge-vault',
					'nm'          => 'Knowledge',
					'icon'        => 'library',
					'cat'         => 'Admin',
					'cc'          => '#059669',
					'desc'        => 'Company document repository with AI indexing.',
					'roles'       => [ 'zdz_owner', 'zdz_admin', 'zdz_sales', 'zdz_operator', 'zdz_mfg', 'zdz_tech' ],
					'bridge_type' => 'inline_widget',
					'admin_url'   => admin_url( 'admin.php?page=zkv-settings' ),
				];
			}

			public function render_mobile_view( int $user_id ): void {
				echo '<p>Please use the main dashboard to access Knowledge.</p>';
			}

			public function render_dashboard_widget( int $user_id ): ?string {

				wp_enqueue_style(
					'zkv-widget-css',
					ZKV_PLUGIN_URL . 'assets/css/widget.css',
					[],
					ZKV_VERSION
				);
				wp_enqueue_script(
					'zkv-widget-js',
					ZKV_PLUGIN_URL . 'assets/js/widget.js',
					[],
					ZKV_VERSION,
					true
				);
				wp_enqueue_script(
					'zkv-scanner-js',
					ZKV_PLUGIN_URL . 'assets/js/scanner.js',
					[],
					ZKV_VERSION,
					true
				);

				$is_admin = false;
				if ( class_exists( 'ZDZ_User_Roles' ) ) {
					$user  = get_userdata( $user_id );
					$roles = (array) ( $user ? $user->roles : [] );
					$role  = ! empty( $roles ) ? $roles[0] : '';
					$is_admin = ZDZ_User_Roles::is_admin_role( $role );
				} else {
					$is_admin = user_can( $user_id, 'manage_options' );
				}

				wp_localize_script( 'zkv-widget-js', 'zkvData', [
					'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
					'nonce'     => wp_create_nonce( ZKV_NONCE ),
					'userId'    => $user_id,
					'isAdmin'   => $is_admin,
					'wpAdmin'   => user_can( $user_id, 'manage_options' ) ? '1' : '0', // the recovery code is theirs
					'maxUpload' => ZKV_MAX_UPLOAD_BYTES,
					'version'   => ZKV_VERSION,
					// Vendored pdf.js shipped with the bundle (the Estimates app owns the copy).
					// Previously loaded pdf.js 3.11.174 from a CDN at runtime.
					'pdfLib'    => plugins_url( 'estimate/assets/js/vendor/pdfjs/pdf.min.js', dirname( __FILE__ ) ) . '?v=' . rawurlencode( ZKV_VERSION ),
					'pdfWorker' => plugins_url( 'estimate/assets/js/vendor/pdfjs/pdf.worker.min.js', dirname( __FILE__ ) ) . '?v=' . rawurlencode( ZKV_VERSION ),
				] );

				ob_start();
				?>
				<div id="zkv-vault" class="zkv-vault" style="display:none;">
					<div class="zkv-header">
						<div class="zkv-header-title">
							<i data-lucide="library"></i>
							<span>Knowledge Vault</span>
						</div>
						<div class="zkv-header-actions">
							<?php if ( current_user_can( 'manage_options' ) ) : ?>
							<button class="zkv-btn-icon" id="zkv-settings-btn" title="Settings"><i data-lucide="settings"></i></button>
							<?php endif; ?>
							<button class="zkv-btn zkv-btn-upload" id="zkv-upload-btn">
								<i data-lucide="upload"></i> Upload
							</button>
						</div>
					</div>

					<!-- Settings Modal (admin only) -->
					<div class="zkv-modal-overlay" id="zkv-settings-modal" style="display:none;">
						<div class="zkv-modal">
							<div class="zkv-modal-header">
								<span>Vault Settings</span>
								<button class="zkv-modal-close" id="zkv-settings-close"><i data-lucide="x"></i></button>
							</div>
							<div class="zkv-modal-body">
								<?php // The Private key and its recovery code (WordPress administrators only). ?>
								<div class="zkv-form-group" id="zkv-private-admin">
									<label>Private documents</label>
									<span class="zkv-hint" id="zkv-private-state">Checking…</span>
									<div id="zkv-private-code-box" style="display:none;"><code id="zkv-private-code" class="zkv-private-code"></code>
										<span class="zkv-hint">Write this down and keep it somewhere safe, off this computer. Whoever has it can open every Private document. If the site's security keys are ever reset, typing it below unlocks them again.</span>
										<button type="button" class="zkv-btn zkv-btn-secondary" id="zkv-private-saved">I've written it down</button></div>
									<div id="zkv-private-unlock-box" style="display:none;"><input type="text" id="zkv-private-unlock-code" placeholder="Recovery code" autocomplete="off" autocapitalize="characters" spellcheck="false" />
										<button type="button" class="zkv-btn zkv-btn-primary" id="zkv-private-unlock">Unlock</button><span class="zkv-hint" id="zkv-private-unlock-msg"></span></div>
									<button type="button" class="zkv-btn zkv-btn-secondary" id="zkv-private-show" style="display:none;">Show the recovery code</button>
								</div>
								<div class="zkv-form-group">
									<?php if ( class_exists( 'ZKV_Dashboard' ) && ZKV_Dashboard::poe_managed() ) : ?>
									<label>Poe API Key</label>
									<input type="hidden" id="zkv-settings-managed-poe" name="zdz_cred_managed[poe]" value="1">
									<span class="zkv-hint"><?php echo ZKV_Dashboard::managed_note_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped where built ?></span>
									<span class="zkv-hint" id="zkv-key-status"></span>
									<?php else : ?>
									<label for="zkv-settings-apikey">Poe API Key</label>
									<input type="password" id="zkv-settings-apikey" placeholder="sk-poe-..." autocomplete="new-password" />
									<span class="zkv-hint" id="zkv-key-status"></span>
									<?php endif; ?>
								</div>
								<div class="zkv-form-group">
									<label for="zkv-settings-model">AI Model</label>
									<?php $zkv_models = class_exists( 'ZKV_Dashboard' ) ? ZKV_Dashboard::model_choices() : array(); ?>
									<?php if ( $zkv_models ) : // the models the business offers (Model Registry roster) ?>
									<select id="zkv-settings-model">
										<option value="">Platform model</option>
										<?php foreach ( $zkv_models as $zkv_handle => $zkv_label ) : ?>
										<option value="<?php echo esc_attr( $zkv_handle ); ?>"><?php echo esc_html( $zkv_label ); ?></option>
										<?php endforeach; ?>
									</select>
									<?php else : ?>
									<input type="text" id="zkv-settings-model" placeholder="Platform model" autocomplete="off" spellcheck="false" />
									<?php endif; ?>
									<span class="zkv-hint" id="zkv-model-used"></span>
								</div>
								<div class="zkv-form-group">
									<button class="zkv-btn zkv-btn-secondary" id="zkv-reindex-all-btn" style="width:100%">
										<i data-lucide="refresh-cw"></i> Re-index All Documents
									</button>
								</div>
							</div>
							<div class="zkv-modal-footer">
								<button class="zkv-btn zkv-btn-cancel" id="zkv-settings-cancel">Cancel</button>
								<button class="zkv-btn zkv-btn-primary" id="zkv-settings-save">Save Settings</button>
							</div>
						</div>
					</div>

					<div class="zkv-search-bar">
						<i data-lucide="search" class="zkv-search-icon"></i>
						<input type="text" id="zkv-search-input" placeholder="Search documents..." autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" />
					</div>

					<div class="zkv-filters" id="zkv-filters">
						<button class="zkv-filter-pill active" data-category="">All</button>
					</div>

					<div class="zkv-doc-list" id="zkv-doc-list">
						<div class="zkv-loading" id="zkv-loading">
							<div class="zkv-spinner"></div>
							<span>Loading documents...</span>
						</div>
					</div>

					<div class="zkv-status" id="zkv-status"></div>

					<!-- Upload Modal -->
					<div class="zkv-modal-overlay" id="zkv-upload-modal" style="display:none;">
						<div class="zkv-modal">
							<div class="zkv-modal-header">
								<span id="zkv-modal-title">Upload Document</span>
								<button class="zkv-modal-close" id="zkv-modal-close"><i data-lucide="x"></i></button>
							</div>
							<div class="zkv-modal-body">
								<?php // Chosen FIRST, so nothing is read by the Ai before the person decides. ?>
								<div class="zkv-private-pick" id="zkv-private-pick">
									<label class="zkv-private-label"><input type="checkbox" id="zkv-private" /> <strong>Keep private</strong></label>
									<span class="zkv-hint">Never sent to any AI service, and stored encrypted. Only its title and the Context you type are searchable, so keep sensitive details out of those. Choose this before picking the file.</span>
									<span class="zkv-hint zkv-private-warn" id="zkv-private-warn" style="display:none;">The AI already read this file to suggest a title. Remove it and pick it again to keep it from the AI completely.</span>
								</div>
								<div class="zkv-input-toggle">
									<button class="zkv-toggle-btn active" id="zkv-mode-file">Upload File</button>
									<button class="zkv-toggle-btn" id="zkv-mode-scan">Scan</button>
									<button class="zkv-toggle-btn" id="zkv-mode-paste">Paste Text</button>
								</div>
								<div id="zkv-file-mode">
									<div class="zkv-dropzone" id="zkv-dropzone">
										<i data-lucide="upload-cloud"></i>
										<p>Tap to select files or drag &amp; drop</p>
										<span class="zkv-dropzone-hint">PDF, DOC, MD, TXT, SRT, VTT, images &amp; more — up to 50 MB each, 10 at a time</span>
										<input type="file" id="zkv-file-input" multiple style="display:none;" />
									</div>
									<div class="zkv-file-preview" id="zkv-file-preview" style="display:none;">
										<i data-lucide="file-text"></i>
										<span id="zkv-file-name"></span>
										<button class="zkv-btn-text" id="zkv-file-remove"><i data-lucide="x"></i></button>
									</div>
									<div class="zkv-batch-list" id="zkv-batch-list" style="display:none;">
										<div class="zkv-batch-header">
											<span id="zkv-batch-count"></span>
											<button class="zkv-btn-text" id="zkv-batch-clear">Clear all</button>
										</div>
										<div class="zkv-batch-items" id="zkv-batch-items"></div>
									</div>
								</div>
								<div id="zkv-paste-mode" style="display:none;">
									<textarea id="zkv-paste-input" rows="8" placeholder="Paste your text here — meeting transcripts, notes, copy from phone, etc. No length limit."></textarea>
								</div>
								<div id="zkv-duplicate-warning" class="zkv-duplicate-warning" style="display:none;">
									<i data-lucide="alert-triangle"></i>
									<span id="zkv-duplicate-msg"></span>
									<button class="zkv-btn-text" id="zkv-duplicate-dismiss">Upload anyway</button>
								</div>
								<div class="zkv-form-group">
									<label for="zkv-title">Title</label>
									<input type="text" id="zkv-title" placeholder="Auto-detected from filename" />
								</div>
								<div class="zkv-form-group">
									<label for="zkv-category">Category</label>
									<select id="zkv-category"><option value="">General</option></select>
								</div>
								<div class="zkv-form-group">
									<label for="zkv-description">Notes (optional)</label>
									<textarea id="zkv-description" rows="2" placeholder="What is this document about?"></textarea>
								</div>
								<div class="zkv-form-group zkv-context-group">
									<label for="zkv-user-context">Context <span class="zkv-label-hint">— stored exactly as you type it, always searchable</span></label>
									<textarea id="zkv-user-context" rows="3" placeholder="Anything you'd search by later — job number, invoice number, lead number, address, customer name..."></textarea>
								</div>
								<div class="zkv-form-group">
									<label>Visibility</label>
									<div class="zkv-radio-group">
										<label><input type="radio" name="zkv-visibility" value="all_employees" checked /> All employees</label>
										<label><input type="radio" name="zkv-visibility" value="admin_only" /> Admin only</label>
										<label><input type="radio" name="zkv-visibility" value="transcript_private" /> Private transcript</label>
									</div>
									<span class="zkv-hint" id="zkv-transcript-hint" style="display:none;">Only the people named as speakers in this transcript will be able to see it — not admins, not the uploader, unless they spoke in it. Speaker names are matched to staff accounts automatically; anyone we can't match waits for an admin to confirm.</span>
								</div>
							</div>
							<div class="zkv-modal-footer">
								<button class="zkv-btn zkv-btn-cancel" id="zkv-cancel-upload">Cancel</button>
								<button class="zkv-btn zkv-btn-primary" id="zkv-submit-upload" disabled>
									<i data-lucide="sparkles"></i> Upload &amp; Index
								</button>
							</div>
							<div class="zkv-processing" id="zkv-processing" style="display:none;">
								<div class="zkv-spinner"></div>
								<p id="zkv-processing-msg">Uploading document...</p>
							</div>
						</div>
					</div>

					<!-- Detail Modal -->
					<div class="zkv-modal-overlay" id="zkv-detail-modal" style="display:none;">
						<div class="zkv-modal zkv-modal-detail">
							<div class="zkv-modal-header">
								<span id="zkv-detail-title">Document</span>
								<button class="zkv-modal-close" id="zkv-detail-close"><i data-lucide="x"></i></button>
							</div>
							<div class="zkv-modal-body" id="zkv-detail-body"></div>
						</div>
					</div>
				</div>
				<?php
				return ob_get_clean();
			}
		}

		add_filter( 'zdz_register_apps', function( $apps ) {
			$apps['knowledge-vault'] = new ZKV_App();
			return $apps;
		} );

	// ── TIER 1 FALLBACK ──
	} elseif ( interface_exists( '\Zorderz\App_Interface' ) ) {

		class ZKV_App implements \Zorderz\App_Interface {
			public function get_config(): array {
				return [
					'id'          => 'knowledge-vault',
					'nm'          => 'Knowledge',
					'icon'        => 'library',
					'cat'         => 'Admin',
					'cc'          => '#059669',
					'desc'        => 'Company document repository with AI indexing.',
					'roles'       => [ 'zdz_owner', 'zdz_admin', 'zdz_sales', 'zdz_operator', 'zdz_mfg', 'zdz_tech' ],
					'bridge_type' => 'iframe',
					'admin_url'   => admin_url( 'admin.php?page=zkv-settings' ),
				];
			}
			public function render_mobile_view( int $user_id ): void {
				echo '<p>Knowledge requires theme v2.0+.</p>';
			}
		}

		add_filter( 'zdz_register_apps', function( $apps ) {
			$apps['knowledge-vault'] = new ZKV_App();
			return $apps;
		} );
	}

} );
