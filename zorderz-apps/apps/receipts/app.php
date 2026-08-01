<?php
/**
 * Module: Zorderz - Receipts
 * Description: AI-assisted installation / service receipt generator for the Zorderz dashboard.
 *   Look up a job by customer name, document number, phone or email through the configured
 *   billing provider + CRM, attach the install photos from the shared media store, render a
 *   NEUTRAL letterhead template, and publish a token-gated public receipt page behind a
 *   reviewer Approve-&-Send gate. Ships with NO business data.
 * Version:     3.10.0
 * Author:      Zorderz
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zorderz
 * Requires PHP: 8.0
 *
 * This is a bundled app module (loaded by zorderz-apps.php), not a standalone plugin.
 * It registers with the theme through the `zdz_register_apps` filter on after_setup_theme
 * and declines cleanly when the theme is absent.
 *
 * CORE-SERVICE BINDINGS (a service not yet built is bound via a documented filter with a
 * NEUTRAL fallback — no competing taxonomy is invented, and nothing is silent):
 *   - Item Engine        : the receipt "mode" is bound to an admin-chosen item tag/subtype
 *                          (NO product name in code). Unit counting speaks the COUNTS
 *                          CONTRACT — zrcpt_count_classify()/zrcpt_count_phrase() resolve
 *                          through ZDZ_Item_Engine::classify()/count_phrase() (mirrored
 *                          filters zdz_item_classify / zdz_item_count_categories). An empty
 *                          catalog degrades to a neutral all-priced-line count.
 *   - Document Conventions: the closing line ("Tax and Installation Included"), price
 *                          rounding, the reference-code grammar and the invoice receipt-link
 *                          line resolve through `zrcpt_document_convention` with neutral
 *                          fallbacks. The Core Document Conventions service is NOT built yet
 *                          (crosswalk 03-D); this filter is the documented seam.
 *   - Business Profile    : letterhead + email identity (name, colours, logo, address,
 *                          sender) come from ZDZ_Business_Profile. NO production hostname is
 *                          compiled in; the image CDN base + app-domain rewrite come from
 *                          web.asset_cdn_host / web.app_domain.
 *   - Connections/Billing : billing OAuth resolves through ZDZ_Core_FreshBooks /
 *                          ZDZ_Token_Service (single-flight refresh); the module no longer
 *                          POSTs the token endpoint itself (crosswalk 03-B13).
 *   - AI gateway          : the receipt-writer bot handle is a setting; prompts are assembled
 *                          at runtime and sent through ZDZ_Core_Poe when available.
 *   - Media / Party       : install photos come from ZDZ_User_Media; access from ZDZ roles.
 *
 * SAFETY FLOOR: a receipt is never emailed to a customer without a human reviewer's recorded
 * Approve-&-Send (draft -> preview -> approve -> confirm -> server-verified send).
 *
 * @package Zorderz\Receipts
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── Constants ──────────────────────────────────────────────────────
define( 'ZRCPT_VERSION', '3.10.0' );
define( 'ZRCPT_FILE', __FILE__ );
define( 'ZRCPT_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZRCPT_URL', plugin_dir_url( __FILE__ ) );
define( 'ZRCPT_APP_ID', 'receipts' );

/**
 * The authoritative-unit-count marker the receipt-writer prompt understands. Protocol tokens
 * live in ONE constant, referenced by the builder + the count enforcer — never typed twice.
 * Replaces the legacy product-named count token.
 */
if ( ! defined( 'ZRCPT_COUNT_MARKER' ) ) {
    define( 'ZRCPT_COUNT_MARKER', 'ZDZ_RCPT_COUNT' );
}

/**
 * Content-keyed asset version: module version + file mtime, so a byte change to a CSS/JS
 * asset busts caches even within one version.
 */
function zrcpt_asset_ver( $rel ) {
    $abs   = ZRCPT_DIR . ltrim( (string) $rel, '/' );
    $mtime = @filemtime( $abs );
    return $mtime ? ZRCPT_VERSION . '.' . $mtime : ZRCPT_VERSION;
}

/* ══════════════════════════════════════════════════════════════════════
   ITEM ENGINE BINDING (counts contract + product-tag mode)
   The receipt used to hardcode a single product and count units with a fixed
   substring heuristic. Both now resolve through the
   Item Engine. An empty catalog degrades to neutral: no tag filter, and units
   counted as "priced lines" with a generic unit noun.
   ══════════════════════════════════════════════════════════════════════ */

/**
 * The admin-chosen item id/subtype this tenant's receipts are about (the generalization of
 * the old product-named mode's reference filter). Ships EMPTY — an empty value means "any completed job",
 * i.e. no product-tag restriction. NO product name is hardcoded.
 */
function zrcpt_receipt_item_tag(): string {
    $opts = get_option( 'zrcpt_options', array() );
    $tag  = is_array( $opts ) ? (string) ( $opts['item_tag'] ?? '' ) : '';
    return (string) apply_filters( 'zrcpt_receipt_item_tag', $tag );
}

/**
 * Classify one line's free text to an Item Engine item id (a "kind"), or '' when unknown /
 * empty catalog. Prefers the mirrored filter so there is no hard class dependency.
 */
function zrcpt_count_classify( string $text ): string {
    $pre = apply_filters( 'zdz_item_classify', null, $text );
    if ( is_string( $pre ) && $pre !== '' ) {
        return $pre;
    }
    if ( class_exists( 'ZDZ_Item_Engine' ) && method_exists( 'ZDZ_Item_Engine', 'classify' ) ) {
        $id = ZDZ_Item_Engine::classify( $text );
        return is_string( $id ) ? $id : '';
    }
    return '';
}

/**
 * Is the catalog non-empty for counting purposes? When empty, every consumer falls back to
 * its own neutral default (INV-3 ship-empty guarantee).
 */
function zrcpt_catalog_has_kinds(): bool {
    $pre = apply_filters( 'zdz_item_count_categories', null );
    if ( is_array( $pre ) ) {
        return ! empty( $pre );
    }
    if ( class_exists( 'ZDZ_Item_Engine' ) && method_exists( 'ZDZ_Item_Engine', 'count_categories' ) ) {
        return ! empty( ZDZ_Item_Engine::count_categories() );
    }
    return false;
}

/**
 * Prose for "N of a kind" using the item's OWN unit noun (COUNTS CONTRACT: never hardcode
 * 'screen'/'door'/'unit'). Falls back to a neutral pluralized unit noun on an empty catalog.
 *
 * @param string $item_id item id (a "kind"); '' uses the neutral unit noun.
 * @param int    $n       count
 */
function zrcpt_count_phrase( string $item_id, int $n ): string {
    if ( $item_id !== '' && class_exists( 'ZDZ_Item_Engine' ) && method_exists( 'ZDZ_Item_Engine', 'count_phrase' ) ) {
        $phrase = ZDZ_Item_Engine::count_phrase( $item_id, $n );
        if ( is_string( $phrase ) && $phrase !== '' ) {
            return $phrase;
        }
    }
    $noun = zrcpt_unit_noun( $n !== 1 );
    return $n . ' ' . $noun;
}

/**
 * The neutral unit noun (singular/plural), tenant-overridable. Core default 'item(s)' — a
 * business selling a countable thing sets it (or, better, populates the Item Engine so the
 * per-item unit nouns are used instead of this fallback).
 */
function zrcpt_unit_noun( bool $plural = false ): string {
    $noun = (string) apply_filters( 'zrcpt_unit_noun', $plural ? 'items' : 'item', $plural );
    return $noun !== '' ? $noun : ( $plural ? 'items' : 'item' );
}

/* ══════════════════════════════════════════════════════════════════════
   DOCUMENT CONVENTIONS BINDING
   The receipt writes a fixed closing line, rounds prices, and formats a
   reference code / invoice receipt-link line. Those are per-tenant paper rules
   (crosswalk 03 §D). Until the Core Document Conventions service lands, they
   bind here through ONE filter with neutral, safe fallbacks.
   ══════════════════════════════════════════════════════════════════════ */

/**
 * Resolve a named document-convention value. Neutral fallbacks:
 *   - closing_line      : '' (no forced closing line)  [D10]
 *   - price_round_mode  : 'none'                        [D13]
 *   - price_round_step  : 0
 *   - receipt_link_label: 'Receipt - Link'             [D20]  (no product/brand word)
 *
 * @param string $key
 * @param mixed  $default
 * @return mixed
 */
function zrcpt_document_convention( string $key, $default = '' ) {
    $neutral = array(
        'closing_line'       => '',
        'price_round_mode'   => 'none',   // 'none' | 'ceil'
        'price_round_step'   => 0,
        'receipt_link_label' => 'Receipt - Link',
    );
    $val = array_key_exists( $key, $neutral ) ? $neutral[ $key ] : $default;
    return apply_filters( 'zrcpt_document_convention', $val, $key, $default );
}

// ── Load classes + the neutral in-repo letterhead template ──────────
require_once ZRCPT_DIR . 'templates/receipt-letterhead.php';
require_once ZRCPT_DIR . 'includes/class-zrcpt-heic.php';
require_once ZRCPT_DIR . 'includes/class-zrcpt-media.php';
require_once ZRCPT_DIR . 'includes/class-zrcpt-nutshell.php';
require_once ZRCPT_DIR . 'includes/class-zrcpt-freshbooks.php';
require_once ZRCPT_DIR . 'includes/class-zrcpt-receipt.php';

// Boot the engine (registers post type, admin, AJAX, routes, template hooks).
ZRCPT_Receipt::get_instance();

/**
 * Activation (called by the zorderz-apps bundle activator via the manifest entry — a bundled
 * module's own register_activation_hook never fires). Registers the CPT so its rewrite rules
 * exist, sets the share-token cutover, and flushes rewrites. Schema is post-meta on a CPT, so
 * there is no custom table to create. NO business data is seeded.
 */
function zrcpt_activate() {
    $inst = ZRCPT_Receipt::get_instance();
    if ( method_exists( $inst, 'register_post_type' ) ) {
        $inst->register_post_type();
    }
    if ( method_exists( $inst, 'maybe_set_token_cutover' ) ) {
        $inst->maybe_set_token_cutover();
    }
    flush_rewrite_rules();
}

/** Deactivation — flush rewrites; data (receipt posts) is preserved. */
function zrcpt_deactivate() {
    flush_rewrite_rules();
}


/**
 * ────────────────────────────────────────────────────────
 * ZORDERZ THEME INTEGRATION
 * ────────────────────────────────────────────────────────
 *
 * IMPORTANT: Deferred to `after_setup_theme` because WordPress loads
 * plugins BEFORE themes. The Zorderz interfaces do not exist yet
 * at plugin load time — interface_exists() would always return false.
 *
 * Bridge type: iframe — the receipt generator form uses complex file
 * uploads (drag-and-drop, HEIC conversion, photo thumbnails) that work
 * best in a full-page context inside the SPA's bottom sheet.
 *
 * Tiered implementation:
 *   Tier 2 (theme v2.0+) — Widget_App_Interface with inline_widget
 *   Tier 1 (theme v1.x)  — App_Interface with iframe fallback
 */
add_action( 'after_setup_theme', function() {

    // ── TIER 2: Inline widget + iframe form (theme v2.0+) ──────────
    if ( interface_exists( '\Zorderz\Widget_App_Interface' ) ) {

        class ZRCPT_App implements \Zorderz\Widget_App_Interface {

            public function get_config(): array {
                return [
                    'id'          => 'receipts',
                    'nm'          => 'Receipts',
                    'icon'        => 'receipt',
                    'cat'         => 'Admin',
                    'cc'          => '#1E4D6E',
                    'desc'        => 'AI-assisted installation & service receipt generator.',
                    'roles'       => (array) apply_filters( 'zrcpt_app_roles', [ 'administrator', 'zdz_owner', 'zdz_admin', 'zdz_sales' ] ),
                    'bridge_type' => 'inline_widget',
                    'admin_url'   => admin_url( 'admin.php?page=zrcpt-dashboard' ),
                ];
            }

            public function render_mobile_view( int $user_id ): void {
                echo '<p>Please use the main dashboard to open the Receipts app.</p>';
            }

            public function render_dashboard_widget( int $user_id ): ?string {
                // Enqueue widget assets
                wp_enqueue_style(
                    'zrcpt-widget-css',
                    plugin_dir_url( __FILE__ ) . 'assets/css/widget.css',
                    [],
                    ZRCPT_Receipt::VERSION
                );
                wp_enqueue_script(
                    'zrcpt-widget-js',
                    plugin_dir_url( __FILE__ ) . 'assets/js/widget.js',
                    [],
                    ZRCPT_Receipt::VERSION,
                    true
                );
                wp_localize_script( 'zrcpt-widget-js', 'zrcptWidgetData', [
                    'ajaxurl'      => admin_url( 'admin-ajax.php' ),
                    'nonce'        => wp_create_nonce( ZRCPT_Receipt::NONCE_ACTION ),
                    'dashboardUrl' => admin_url( 'admin.php?page=zrcpt-dashboard' ),
                    'version'      => ZRCPT_Receipt::VERSION,
                    'hasLookup'    => true,
                    // v3.9.0 — admins get direct Delete in History; everyone
                    // else gets "Request deletion" (reason required).
                    'isAdmin'      => current_user_can( 'manage_options' ),
                    // v3.1.0 — is the shared photo library available to auto-pull from?
                    'hasMedia'     => class_exists( 'ZDZ_User_Media' ) && method_exists( 'ZDZ_User_Media', 'get_user_media' ),
                    // Receipt mode: the admin-chosen, product-tag-bound mode (Item Engine). Future modes gated server-side.
                    'mode'         => ZRCPT_Receipt::MODE_TAGGED,
                ] );

                ob_start();
                ?>
                <div class="zrcpt-w" id="zrcpt-widget">

                    <!-- ── TAB BAR ── -->
                    <div class="zrcpt-w-tabs">
                        <button class="zrcpt-w-tab zrcpt-w-tab-active" data-tab="input">New Receipt</button>
                        <button class="zrcpt-w-tab" data-tab="history">History</button>
                    </div>

                    <!-- ═══════════════════════════════════════════
                         TAB: NEW RECEIPT (input → status → success / error)
                         ═══════════════════════════════════════════ -->
                    <div class="zrcpt-w-panel" id="zrcpt-w-tab-input">

                        <!-- INPUT VIEW -->
                        <div id="zrcpt-w-input">

                            <!-- ── STEP 1 — FIND THE JOB (lead-first, like Prep) ── -->
                            <div class="zrcpt-w-section zrcpt-w-lookup" id="zrcpt-w-lookup">
                                <label class="zrcpt-w-label">Find the job</label>
                                <p class="zrcpt-w-hint">Customer name, invoice/estimate #, phone, or email — just like Prep.</p>
                                <div class="zrcpt-w-lookup-row">
                                    <input type="text" id="zrcpt-w-lookup-input" class="zrcpt-w-input" placeholder="e.g. Scott Meyer, 15217, or 858-555-1212" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" />
                                    <button type="button" id="zrcpt-w-lookup-btn" class="zrcpt-w-btn zrcpt-w-btn-sm">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                        Find
                                    </button>
                                </div>
                                <div id="zrcpt-w-lookup-status" class="zrcpt-w-lookup-status" style="display:none;"></div>
                                <div id="zrcpt-w-lookup-error" class="zrcpt-w-lookup-error" style="display:none;"></div>
                                <div id="zrcpt-w-lookup-cards" class="zrcpt-w-lookup-cards"></div>
                                <div id="zrcpt-w-lookup-confirmed" class="zrcpt-w-lookup-confirmed" style="display:none;"></div>
                                <div id="zrcpt-w-lookup-nutshell" class="zrcpt-w-lookup-nutshell" style="display:none;"></div>
                            </div>

                            <!-- ── STEP 2 — INSTALLATION PHOTOS ──
                                 v3.6.2: UPLOAD-FIRST. Uploading the install photos is the
                                 primary action and appears as soon as a job is chosen — the
                                 tech adds the exact photos for THIS receipt right here, then
                                 generates. Photos uploaded here are saved company-wide ("For
                                 Everybody") and tagged with the customer. Photos the tech
                                 personally captured in the app (if any) are offered as a
                                 secondary shortcut below. -->
                            <div class="zrcpt-w-section zrcpt-w-photoset" id="zrcpt-w-photoset" style="display:none;">
                                <label class="zrcpt-w-label">Installation photos</label>

                                <!-- PRIMARY: upload the photos for this receipt -->
                                <div id="zrcpt-w-upload-primary" class="zrcpt-w-upload-primary">
                                    <p class="zrcpt-w-hint" id="zrcpt-w-upload-hint">Upload the installation photos for this receipt, then generate. They're saved to the shared library for everyone and tagged with the customer.</p>
                                    <div class="zrcpt-w-dropzone zrcpt-w-dropzone-primary" id="zrcpt-w-photos-drop">
                                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                                        <span class="zrcpt-w-dropzone-text">Drag &amp; drop or tap to add the install photos</span>
                                        <span class="zrcpt-w-dropzone-types">JPG, PNG, WEBP, HEIC — add as many as you need</span>
                                        <input type="file" id="zrcpt-w-photos-file" accept="image/*,.heic,.heif" multiple style="display:none;" />
                                    </div>
                                    <div id="zrcpt-w-thumbs" class="zrcpt-w-thumbs"></div>
                                    <span id="zrcpt-w-photo-count" class="zrcpt-w-muted"></span>

                                    <!-- v3.6.2 — explicit "these are the photos I want" confirm.
                                         Hidden until at least one upload is ready; Generate stays
                                         disabled (on the upload path) until it's ticked, so a tech
                                         always confirms the exact set for THIS receipt. -->
                                    <label id="zrcpt-w-upload-confirm-wrap" class="zrcpt-w-upload-confirm" style="display:none;">
                                        <input type="checkbox" id="zrcpt-w-upload-confirm" />
                                        <span id="zrcpt-w-upload-confirm-text">These are the photos I want to use for this receipt.</span>
                                    </label>
                                </div>

                                <!-- SECONDARY: photos this tech already captured in the app.
                                     Only revealed when the tech has their OWN captures for a
                                     recent job; never shows anyone else's photos. -->
                                <div id="zrcpt-w-library" class="zrcpt-w-library" style="display:none;">
                                    <div class="zrcpt-w-library-divider"><span>or use photos you already captured</span></div>
                                    <p class="zrcpt-w-hint" id="zrcpt-w-photoset-hint"></p>
                                    <div id="zrcpt-w-photoset-status" class="zrcpt-w-lookup-status" style="display:none;"></div>
                                    <div id="zrcpt-w-sessions" class="zrcpt-w-sessions"></div>
                                </div>

                                <!-- Empty/looking status line (no photos of your own found). -->
                                <div id="zrcpt-w-photoset-empty" class="zrcpt-w-photoset-empty" style="display:none;"></div>
                            </div>

                            <!-- ── INVOICE FILE (fallback, only when NOT using Find) ──
                                 Collapsed by default; revealed only when no job is selected
                                 (the manual/no-lookup path). -->
                            <div id="zrcpt-w-manual" class="zrcpt-w-manual" style="display:none;">
                                <div class="zrcpt-w-section" id="zrcpt-w-invoice-section">
                                    <label class="zrcpt-w-label">Invoice file <span class="zrcpt-w-optional">(only if you didn't use Find)</span></label>
                                    <div class="zrcpt-w-dropzone" id="zrcpt-w-invoice-drop">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                        <span class="zrcpt-w-dropzone-text">Drag &amp; drop or click to upload</span>
                                        <span class="zrcpt-w-dropzone-types">PDF, JPG, PNG, WEBP, HEIC</span>
                                        <input type="file" id="zrcpt-w-invoice-file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif" style="display:none;" />
                                    </div>
                                    <span id="zrcpt-w-invoice-name" class="zrcpt-w-file-name"></span>
                                </div>
                            </div>

                            <!-- ── DETAILS (date pre-filled from photo EXIF) ── -->
                            <div class="zrcpt-w-section" id="zrcpt-w-details" style="display:none;">
                                <label class="zrcpt-w-label" for="zrcpt-w-date">
                                    Installation date
                                    <span class="zrcpt-w-optional" id="zrcpt-w-date-source"></span>
                                </label>
                                <input type="date" id="zrcpt-w-date" class="zrcpt-w-input" />

                                <label class="zrcpt-w-label" for="zrcpt-w-link" style="margin-top:12px;">Invoice link <span class="zrcpt-w-optional">(optional)</span></label>
                                <input type="url" id="zrcpt-w-link" class="zrcpt-w-input" placeholder="https://..." />
                            </div>

                            <!-- Generate Button -->
                            <button id="zrcpt-w-generate" class="zrcpt-w-btn zrcpt-w-btn-primary zrcpt-w-btn-full" style="display:none;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a10 10 0 1 0 10 10"/><path d="M12 12l5-3"/></svg>
                                Generate Receipt
                            </button>
                        </div>

                        <!-- STATUS VIEW (shown during AJAX) -->
                        <div id="zrcpt-w-status" class="zrcpt-w-status" style="display:none;">
                            <div class="zrcpt-w-spinner"></div>
                            <span id="zrcpt-w-status-text">Working&hellip;</span>
                        </div>

                        <!-- SUCCESS VIEW (shown after generation) -->
                        <div id="zrcpt-w-success" style="display:none;">
                            <div class="zrcpt-w-success-panel">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                <h3 class="zrcpt-w-success-title">Receipt Generated</h3>
                                <div class="zrcpt-w-success-details">
                                    <div class="zrcpt-w-detail"><strong>Address:</strong> <span id="zrcpt-w-res-address">--</span></div>
                                    <div class="zrcpt-w-detail"><strong>Date:</strong> <span id="zrcpt-w-res-date">--</span></div>
                                    <div class="zrcpt-w-detail"><strong>Units:</strong> <span id="zrcpt-w-res-vents">--</span></div>
                                    <div class="zrcpt-w-detail"><strong>Photos:</strong> <span id="zrcpt-w-res-photos">--</span></div>
                                    <div class="zrcpt-w-detail" id="zrcpt-w-res-fb" style="display:none;"><strong>FreshBooks:</strong> <span id="zrcpt-w-res-fb-text">--</span></div>
                                </div>
                                <a id="zrcpt-w-view-link" href="#" class="zrcpt-w-btn zrcpt-w-btn-primary zrcpt-w-btn-full" target="_blank" rel="noopener">
                                    View Receipt &rarr;
                                </a>
                                <button id="zrcpt-w-new-after" class="zrcpt-w-btn zrcpt-w-btn-secondary zrcpt-w-btn-full" style="margin-top:8px;">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                                    New Receipt
                                </button>
                            </div>
                        </div>

                        <!-- ERROR VIEW -->
                        <div id="zrcpt-w-error" style="display:none;">
                            <div class="zrcpt-w-error-panel">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                                <p id="zrcpt-w-error-msg" class="zrcpt-w-error-text">Something went wrong.</p>
                                <button id="zrcpt-w-retry" class="zrcpt-w-btn zrcpt-w-btn-primary zrcpt-w-btn-full">
                                    Try Again
                                </button>
                            </div>
                        </div>

                    </div><!-- /tab-input -->

                    <!-- ═══════════════════════════════════════════
                         TAB: HISTORY
                         ═══════════════════════════════════════════ -->
                    <div class="zrcpt-w-panel" id="zrcpt-w-tab-history" style="display:none;">
                        <!-- Stats Row -->
                        <div class="zrcpt-w-stats">
                            <div class="zrcpt-w-stat">
                                <span class="zrcpt-w-stat-val" id="zrcpt-w-total">--</span>
                                <span class="zrcpt-w-stat-label">Total Receipts</span>
                            </div>
                            <div class="zrcpt-w-stat">
                                <span class="zrcpt-w-stat-val" id="zrcpt-w-month">--</span>
                                <span class="zrcpt-w-stat-label">This Month</span>
                            </div>
                            <div class="zrcpt-w-stat">
                                <span class="zrcpt-w-stat-val" id="zrcpt-w-latest">--</span>
                                <span class="zrcpt-w-stat-label">Last Created</span>
                            </div>
                        </div>
                        <div id="zrcpt-w-recent" class="zrcpt-w-recent">
                            <div class="zrcpt-w-loading">Loading recent receipts&hellip;</div>
                        </div>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=zrcpt-dashboard' ) ); ?>"
                           class="zrcpt-w-btn zrcpt-w-btn-secondary zrcpt-w-btn-full" style="margin-top:12px;">
                            Open in WP Admin &rarr;
                        </a>
                    </div><!-- /tab-history -->


                <!-- ═══════════════════════════════════════════
                     v3.6.0 — APPROVE & SEND MODAL
                     The receipt maker must PREVIEW the generated receipt
                     (scroll all the way through it) and tick "I have read and
                     approved this" before the Approve button enables. Approving
                     is their sign-off. Sending is a separate, deliberate click
                     and only unlocks after approval.
                     ═══════════════════════════════════════════ -->
                <div class="zrcpt-w-modal" id="zrcpt-w-approve-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="zrcpt-w-modal-title">
                    <div class="zrcpt-w-modal-backdrop" id="zrcpt-w-modal-backdrop"></div>
                    <div class="zrcpt-w-modal-card" role="document">
                        <div class="zrcpt-w-modal-head">
                            <div>
                                <h3 class="zrcpt-w-modal-title" id="zrcpt-w-modal-title">Review &amp; approve receipt</h3>
                                <div class="zrcpt-w-modal-sub" id="zrcpt-w-modal-sub">--</div>
                            </div>
                            <button type="button" class="zrcpt-w-modal-x" id="zrcpt-w-modal-close" aria-label="Close">&times;</button>
                        </div>

                        <!-- Approval banner: only shown when already approved -->
                        <div class="zrcpt-w-approved-banner" id="zrcpt-w-approved-banner" style="display:none;"></div>

                        <p class="zrcpt-w-modal-instruct" id="zrcpt-w-modal-instruct">
                            Please read the full receipt below. You must scroll to the end before you can approve it.
                        </p>

                        <!-- v3.6.5 — Photo removal now happens directly on the photos
                             inside the receipt preview below: each photo in the receipt's
                             own gallery gets a corner × (injected into the same-origin
                             preview), and tapping it opens the centered "Delete this
                             photo?" dialog at the bottom of this modal. One unified view —
                             no separate gallery. -->

                        <!-- Scrollable preview of the exact receipt the customer will get -->
                        <div class="zrcpt-w-preview" id="zrcpt-w-preview" tabindex="0">
                            <div class="zrcpt-w-preview-loading" id="zrcpt-w-preview-loading">Loading receipt&hellip;</div>
                            <iframe id="zrcpt-w-preview-frame" class="zrcpt-w-preview-frame" title="Receipt preview" style="display:none;"></iframe>
                            <div class="zrcpt-w-preview-end" id="zrcpt-w-preview-end">— End of receipt —</div>
                        </div>
                        <div class="zrcpt-w-scroll-hint" id="zrcpt-w-scroll-hint">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="m19 12-7 7-7-7"/></svg>
                            Scroll to the bottom to continue
                        </div>

                        <!-- Affirmative consent: the "signature" -->
                        <label class="zrcpt-w-approve-check" id="zrcpt-w-approve-check-wrap">
                            <input type="checkbox" id="zrcpt-w-approve-check" disabled />
                            <span>I have read this receipt and approve it. I understand this records me as the person who approved it for sending to the customer.</span>
                        </label>

                        <div class="zrcpt-w-modal-actions">
                            <button type="button" class="zrcpt-w-btn zrcpt-w-btn-secondary" id="zrcpt-w-modal-cancel">Cancel</button>
                            <div class="zrcpt-w-modal-actions-right">
                                <button type="button" class="zrcpt-w-btn zrcpt-w-btn-primary" id="zrcpt-w-approve-btn" disabled>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                    Approve
                                </button>
                                <!-- Send appears only once approved -->
                                <button type="button" class="zrcpt-w-btn zrcpt-w-btn-send" id="zrcpt-w-send-btn" style="display:none;">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>
                                    Send via email
                                </button>
                            </div>
                        </div>
                        <div class="zrcpt-w-modal-msg" id="zrcpt-w-modal-msg" style="display:none;"></div>

                        <!-- v3.6.1 — Send confirmation. Clicking "Send via email" does NOT
                             send immediately; it reveals this confirm step so the user
                             understands the consequence (the customer is emailed) and can
                             back out. The customer's email address is never shown.
                             v3.9.2 — now a CENTERED overlay dialog (same pattern as the
                             delete-photo confirm): the old in-flow panel rendered BELOW
                             the modal footer, which on phones pushed it past the clipped
                             card edge — the buttons literally could not be reached. Also
                             fixes the stray backslash that rendered as "can\'t". -->
                        <div class="zrcpt-w-sendconfirm" id="zrcpt-w-sendconfirm" style="display:none;" role="alertdialog" aria-modal="true" aria-labelledby="zrcpt-w-sendconfirm-title">
                            <div class="zrcpt-w-sendconfirm-backdrop" id="zrcpt-w-sendconfirm-backdrop"></div>
                            <div class="zrcpt-w-sendconfirm-card" role="document">
                                <div class="zrcpt-w-sendconfirm-inner">
                                    <div class="zrcpt-w-sendconfirm-icon">
                                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>
                                    </div>
                                    <div class="zrcpt-w-sendconfirm-body">
                                        <h4 class="zrcpt-w-sendconfirm-title" id="zrcpt-w-sendconfirm-title">Send this receipt to the customer?</h4>
                                        <p class="zrcpt-w-sendconfirm-text">This emails the finished receipt to the customer on file right now. This action can&rsquo;t be undone.</p>
                                    </div>
                                </div>
                                <div class="zrcpt-w-sendconfirm-actions">
                                    <button type="button" class="zrcpt-w-btn zrcpt-w-btn-secondary" id="zrcpt-w-sendconfirm-cancel">Cancel</button>
                                    <button type="button" class="zrcpt-w-btn zrcpt-w-btn-send" id="zrcpt-w-sendconfirm-yes">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>
                                        Yes, send via email
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- v3.6.5 — Centered "Delete this photo?" dialog. Opened when the
                             reviewer taps the × injected onto a photo in the receipt
                             preview. Centered over the modal so it is never clipped by a
                             photo or the grid edge (the v3.6.4 problem). Deliberate to
                             confirm, easy to cancel. -->
                        <div class="zrcpt-w-delconfirm" id="zrcpt-w-delconfirm" style="display:none;" role="alertdialog" aria-modal="true" aria-labelledby="zrcpt-w-delconfirm-title">
                            <div class="zrcpt-w-delconfirm-backdrop" id="zrcpt-w-delconfirm-backdrop"></div>
                            <div class="zrcpt-w-delconfirm-card" role="document">
                                <div class="zrcpt-w-delconfirm-thumb" id="zrcpt-w-delconfirm-thumb"></div>
                                <h4 class="zrcpt-w-delconfirm-title" id="zrcpt-w-delconfirm-title">Delete this photo?</h4>
                                <p class="zrcpt-w-delconfirm-text">It will be removed from this receipt. The photo stays in your media library.</p>
                                <div class="zrcpt-w-delconfirm-actions">
                                    <button type="button" class="zrcpt-w-btn zrcpt-w-btn-secondary" id="zrcpt-w-delconfirm-cancel">Cancel</button>
                                    <button type="button" class="zrcpt-w-btn zrcpt-w-btn-danger" id="zrcpt-w-delconfirm-yes">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                        Delete photo
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                </div><!-- /zrcpt-widget -->
                <?php
                return ob_get_clean();
            }
        }

        add_filter( 'zdz_register_apps', function( $apps ) {
            $apps['receipts'] = new ZRCPT_App();
            return $apps;
        } );

    // ── TIER 1 FALLBACK: Standard iframe tile (theme v1.x) ─────────
    } elseif ( interface_exists( '\Zorderz\App_Interface' ) ) {

        class ZRCPT_App implements \Zorderz\App_Interface {

            public function get_config(): array {
                return [
                    'id'          => 'receipts',
                    'nm'          => 'Receipts',
                    'icon'        => 'receipt',
                    'cat'         => 'Admin',
                    'cc'          => '#1E4D6E',
                    'desc'        => 'AI-assisted installation & service receipt generator.',
                    'roles'       => (array) apply_filters( 'zrcpt_app_roles', [ 'administrator', 'zdz_owner', 'zdz_admin', 'zdz_sales' ] ),
                    'bridge_type' => 'iframe',
                    'admin_url'   => admin_url( 'admin.php?page=zrcpt-dashboard' ),
                ];
            }

            public function render_mobile_view( int $user_id ): void {
                echo '<iframe src="' . esc_url(
                    admin_url( 'admin.php?page=zrcpt-dashboard&zdz_mobile=1' )
                ) . '" style="width:100%;height:100%;border:none;"></iframe>';
            }
        }

        add_filter( 'zdz_register_apps', function( $apps ) {
            $apps['receipts'] = new ZRCPT_App();
            return $apps;
        } );
    }

} );

/**
 * ────────────────────────────────────────────────────────
 * ZORDERZ AJAX — Widget Stats (lightweight endpoint)
 * ────────────────────────────────────────────────────────
 */
add_action( 'wp_ajax_zrcpt_widget_stats', function() {
    check_ajax_referer( ZRCPT_Receipt::NONCE_ACTION, 'nonce' );

    // Dual RBAC: WP admin OR Zorderz custom role
    if ( ! ZRCPT_Receipt::user_can_access() ) { // v3.6.9 (H1): real app-access (this is a top-level closure, not a class method — no self::)
        wp_send_json_error( 'Unauthorized' );
    }

    global $wpdb;
    $post_type = ZRCPT_Receipt::POST_TYPE;

    // Total receipts
    $total = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
        $post_type
    ) );

    // This month
    $month_start = date( 'Y-m-01 00:00:00' );
    $this_month = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_date >= %s",
        $post_type,
        $month_start
    ) );

    // Recent receipts (last 5)
    $recent = $wpdb->get_results( $wpdb->prepare(
        "SELECT ID, post_title, post_date, post_name FROM {$wpdb->posts}
         WHERE post_type = %s AND post_status = 'publish'
         ORDER BY post_date DESC LIMIT 12",
        $post_type
    ) );

    $recent_list = [];
    $instance = ZRCPT_Receipt::get_instance();
    foreach ( $recent as $r ) {
        // v3.6.0 — include each receipt's approval/send state so the History
        // list can show "Approved" / "Sent" badges and enable the right action.
        $state = method_exists( $instance, 'get_approval_state' )
            ? $instance->get_approval_state( (int) $r->ID )
            : [ 'approved' => false, 'sent' => false, 'can_send' => false ];
        $recent_list[] = [
            'id'        => $r->ID,
            'title'     => $r->post_title,
            'date'      => date( 'M j', strtotime( $r->post_date ) ),
            'address'   => get_post_meta( $r->ID, '_address_short', true ),
            'permalink' => get_permalink( $r->ID ),
            'approved'  => ! empty( $state['approved'] ),
            'sent'      => ! empty( $state['sent'] ),
            'can_send'  => ! empty( $state['can_send'] ),
            'approved_by'   => $state['approved_by'] ?? '',
            'approved_at'   => $state['approved_at'] ?? '',
            'approved_by_me'=> ! empty( $state['approved_by_me'] ),
            'sent_at'       => $state['sent_at'] ?? '',
            // v3.8.0 — "Redone — approve & re-send" badge support.
            'prev_sent_at'  => $state['prev_sent_at'] ?? '',
            // v3.9.0 — pending deletion request (null when none): the History
            // list shows the state and offers cancel to the requester/admin.
            'del_req'       => ( function () use ( $r, $instance ) {
                $req = method_exists( $instance, 'delete_request_of' ) ? $instance->delete_request_of( (int) $r->ID ) : null;
                if ( ! $req ) { return null; }
                return [
                    'by_name' => (string) ( $req['name'] ?? '?' ),
                    'at'      => ! empty( $req['at'] ) ? mysql2date( 'M j, Y g:i a', (string) $req['at'] ) : '',
                    'reason'  => (string) ( $req['reason'] ?? '' ),
                    'mine'    => ( (int) ( $req['user_id'] ?? 0 ) === get_current_user_id() ),
                ];
            } )(),
        ];
    }

    // Latest receipt date
    $latest_date = ! empty( $recent ) ? date( 'M j', strtotime( $recent[0]->post_date ) ) : '—';

    wp_send_json_success( [
        'total'       => $total,
        'this_month'  => $this_month,
        'latest_date' => $latest_date,
        'recent'      => $recent_list,
    ] );
} );

/**
 * Declare this module's legacy -> current rename map to the platform migration. Plugins
 * DECLARE; the theme's ZDZ_Rename_Migration performs the option/meta/post-type/app-id renames
 * in ONE place. A fresh Zorderz install has no legacy rows, so every entry no-ops. Data is
 * never seeded — only renamed if the old name is present. This is how the business's own
 * `zrcpt_receipt*` install upgrades cleanly.
 */
add_filter( 'zdz_rename_map', function ( $map ) {
    // Zorderz-era option key bump (no brand word); never seeded — renamed only if present.
    $map['options'] = array_merge( $map['options'] ?? array(), array(
        'zrcpt_receipt_options' => 'zrcpt_options',
    ) );
    // The receipt "mode" meta key is unchanged; any legacy product-named VALUE is
    // migrated to the tagged mode by the class on read (see ZRCPT_Receipt::receipt_mode()).
    $map['post_meta'] = array_merge( $map['post_meta'] ?? array(), array(
        '_receipt_mode' => '_receipt_mode',
    ) );
    // A tenant upgrading from a differently-named legacy receipt app maps its old
    // brand-named CPT slug and app-id to the current ones (ZRCPT_APP_ID / the
    // 'zrcpt_receipt' CPT) through this SAME filter from its own (private) pack, so
    // its receipt posts and user app-grants survive the rename. The public module
    // ships NO legacy product/brand id of its own.
    return $map;
} );
