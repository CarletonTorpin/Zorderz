<?php
/**
 * ZRCPT_Receipt — the Receipts app engine (generalized from the internal receipt generator).
 *
 * Generates a customer-facing installation/service receipt: look up the job by name /
 * document number / phone / email through the configured billing provider + CRM, attach the
 * install photos from the shared media store, render a neutral letterhead template, and
 * publish a token-gated public receipt page with a reviewer Approve-&-Send gate.
 *
 * GENERALIZATION (v1.1):
 *   - The single receipt mode is generic and product-tag-bound (MODE_TAGGED). The admin picks which
 *     Item Engine tag/subtype receipts apply to; NO product name is compiled in.
 *   - Unit counting binds to the Item Engine COUNTS CONTRACT (zrcpt_count_* helpers ->
 *     ZDZ_Item_Engine::count_phrase()/classify()); the old hardcoded 'vent/screen' heuristic
 *     degrades to a neutral all-line count on an empty catalog.
 *   - Letterhead / email identity (name, colours, sender, logo, address) come from
 *     ZDZ_Business_Profile; the receipt-writer bot handle is a setting (ZDZ_Core_Poe).
 *   - Document conventions (closing line, price rounding, reference code, receipt-link line)
 *     bind through the `zrcpt_document_convention` filter with neutral fallbacks (the Core
 *     Document Conventions service is not built yet — see app.php).
 *   - The image CDN base + app-domain URL rewrite come from ZDZ_Business_Profile
 *     (web.asset_cdn_host / web.app_domain); NO production hostname is compiled in.
 *   - Billing OAuth resolves through ZDZ_Core_FreshBooks / ZDZ_Token_Service (single-flight
 *     refresh); the plugin no longer POSTs the token endpoint itself.
 *
 * @package Zorderz\Receipts
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ZRCPT_Receipt {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    const VERSION      = '3.10.0';

    /** @var int last authoritative unit count computed by build_customer_block (diagnostics). */
    private $last_vent_count = 0;
    private static $share_wordlist = null; // share-token wordlist cache
    const POST_TYPE    = 'zrcpt_receipt';
    const OPTION_GROUP = 'zrcpt';
    const OPTION_KEY   = 'zrcpt_options';
    const NONCE_ACTION = 'zrcpt_nonce';
    const API_ENDPOINT = 'https://api.poe.com/v1/chat/completions';
    const API_TIMEOUT  = 300;

    /**
     * Receipt "modes". The default mode is bound to an admin-chosen Item Engine tag/subtype
     * — NO product name is compiled in (generalized from the old single product-named mode):
     *   - 'tagged'       : any completed job for the tenant's configured item tag (default;
     *                      an EMPTY tag means "any completed job", i.e. no restriction).
     *   - 'general'      : any completed job; supports before/after photo pairs.
     *   - 'property_mgmt': multi-unit job broken down per apartment/unit, so a property
     *                      manager has one receipt itemizing every unit for their insurer.
     * The mode drives (a) which billing reference/item filter is applied, (b) how photos are
     * grouped, and (c) the AI prompt + on-page rendering. Unknown/unset → 'tagged'.
     * See receipt_mode() / mode_config().
     */
    const MODE_TAGGED        = 'tagged';
    const MODE_GENERAL       = 'general';
    const MODE_PROPERTY_MGMT = 'property_mgmt';
    // (No product-named mode constant: any legacy stored value maps to MODE_TAGGED
    // via receipt_mode(), so no brand word is compiled in.)

    // Current, platform-registered app id (matches ZRCPT_APP_ID and the app config
    // 'id'). A tenant's brand-named legacy app id maps to this via zdz_rename_map
    // from its private pack, so app grants survive the rename.
    const APP_ID = 'receipts';

    /**
     * v3.6.9 — Server-authoritative app-access check (INV-1 / INV-10).
     *
     * Mirrors the theme's app-visibility rule (ZDZ_Plugin_API::get_user_app_configs):
     * manage_options -> allow; Safe Mode -> deny; app in zdz_denied_apps -> deny (even
     * owner); owner/admin role -> allow; else the app id must be in the user's
     * zdz_allowed_apps; if that meta is absent, fall back to the role's default app
     * set (ADD-only - never widens). This REPLACES the previous blanket
     * current_user_can('zdz_access_app') gate, which every custom role holds and
     * which therefore over-authorized roles that were never granted this app.
     * (A shared-device role granted this app in its default set still passes.)
     *
     * Evaluates the REAL current user (not a ZDZ_View_As emulation).
     *
     * TODO(theme-infra): swap the body for a shared
     * ZDZ_Plugin_API::user_can_access_app($uid, self::APP_ID) helper once the
     * consolidated theme pass adds it (same TODO as the Prep module).
     */
    public static function user_can_access( ?int $user_id = null ): bool {
        $user_id = $user_id ?? get_current_user_id();
        if ( ! $user_id ) {
            return false;
        }
        $app_id = apply_filters( 'zrcpt_app_id', self::APP_ID );

        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return false;
        }
        if ( get_user_meta( $user_id, 'zdz_safe_mode', true ) ) {
            return false;
        }
        $denied = get_user_meta( $user_id, 'zdz_denied_apps', true );
        if ( is_array( $denied ) && in_array( $app_id, $denied, true ) ) {
            return false;
        }
        $role = $user->roles[0] ?? '';
        if ( ZDZ_User_Roles::is_admin_role( $role ) ) {
            return true;
        }
        $allowed = get_user_meta( $user_id, 'zdz_allowed_apps', true );
        if ( is_array( $allowed ) ) {
            return in_array( $app_id, $allowed, true );
        }
        $defaults = ZDZ_User_Roles::get_default_apps_for_role( $role );
        if ( null === $defaults ) {
            return true;
        }
        return is_array( $defaults ) && in_array( $app_id, $defaults, true );
    }

    /**
     * v3.6.9 (H3) - Per-receipt ownership check. A receipt records its creator in
     * the _created_by meta at creation. Only that creator, or a site admin
     * (manage_options), may read the full PII detail of / approve / send / mutate
     * a given receipt - so one authorized user can no longer act on another's
     * receipt by post_id (e.g. email a stranger's receipt to that customer).
     *
     * Legacy receipts created before _created_by existed carry no owner meta;
     * for those we require manage_options. This fails safe.
     */
    public static function current_user_can_manage_receipt( int $post_id ): bool {
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }
        $owner = (int) get_post_meta( $post_id, '_created_by', true );
        if ( $owner <= 0 ) {
            return false;
        }
        return $owner === get_current_user_id();
    }

    /* =====================================================================
       v3.6.9 (C1) — SECRET SHARE LINKS (capability-URL word tokens)
       Design + rationale: claude/Secret-Share-Link-Design-2026-07-12.md
       ===================================================================== */

    /**
     * Curated share-token wordlist (EFF short wordlist, 1288 words, CC-BY).
     * Short (3-6 chars), concrete, unambiguous, voice/typing-friendly. Loaded
     * once; malformed entries are filtered so a corrupt file can't silently
     * shrink entropy below the floor.
     * @return string[]
     */
    public static function share_wordlist(): array {
        if ( null === self::$share_wordlist ) {
            $file = __DIR__ . '/data/share-wordlist.php';
            $list = is_readable( $file ) ? ( require $file ) : [];
            $list = array_values( array_filter( (array) $list, static function ( $w ) {
                return is_string( $w ) && preg_match( '/^[a-z]{3,6}$/', $w );
            } ) );
            self::$share_wordlist = $list;
        }
        return self::$share_wordlist;
    }

    /**
     * Generate a human-writable capability token: N real words, hyphen-joined,
     * chosen with the OS CSPRNG. Default 4 words over ~1288 words ≈ 41 bits —
     * infeasible to enumerate against a live, rate-limited endpoint while staying
     * short + easy to read aloud / retype. Returns '' if the list is too small to
     * be safe (caller must then refuse to publish an unprotected receipt).
     */
    public static function generate_share_token( int $words = 4 ): string {
        $words = max( 4, $words );
        $list  = self::share_wordlist();
        $n     = count( $list );
        if ( $n < 1000 ) {
            return '';
        }
        $picked = [];
        for ( $i = 0; $i < $words; $i++ ) {
            $picked[] = $list[ random_int( 0, $n - 1 ) ];
        }
        return implode( '-', $picked );
    }

    /** Normalize an incoming token for lookup: lowercase, ws/underscore->hyphen,
     *  keep a-z + hyphen, collapse repeats. Forgiving of copy/paste. */
    public static function normalize_share_token( string $t ): string {
        $t = strtolower( trim( $t ) );
        $t = preg_replace( '/[\s_]+/', '-', $t );
        $t = preg_replace( '/[^a-z-]/', '', (string) $t );
        $t = preg_replace( '/-+/', '-', (string) $t );
        return trim( (string) $t, '-' );
    }

    /** Ensure a receipt has a share token; mint once and reuse across regenerates
     *  so a customer's link stays stable. Returns the token, or '' on failure. */
    public static function ensure_share_token( int $post_id ): string {
        $existing = (string) get_post_meta( $post_id, '_share_token', true );
        if ( $existing !== '' ) {
            return $existing;
        }
        // Mint a token that no OTHER receipt already holds, so two receipts can
        // never share a link and cross-serve. Collisions are astronomically rare
        // (~41 bits), so this loop practically always succeeds on the first try.
        $token = '';
        for ( $try = 0; $try < 5; $try++ ) {
            $candidate = self::generate_share_token();
            if ( $candidate === '' ) {
                return ''; // wordlist too small — refuse to publish unprotected
            }
            if ( self::receipt_id_for_token( $candidate ) === 0 ) {
                $token = $candidate;
                break;
            }
        }
        if ( $token === '' ) {
            return '';
        }
        update_post_meta( $post_id, '_share_token', $token );
        return $token;
    }

    /**
     * v3.7.0: rotate a receipt's share token — mint a FRESH unique token and
     * overwrite _share_token, which immediately REVOKES the old
     * /receipt/<old-token> link (a leaked link stops resolving). Unlike
     * ensure_share_token() this does NOT early-return an existing token.
     * Returns the new token, or '' on failure (wordlist too small / no unique
     * candidate after several tries).
     */
    public static function regenerate_share_token( int $post_id ): string {
        $token = '';
        for ( $try = 0; $try < 6; $try++ ) {
            $candidate = self::generate_share_token();
            if ( $candidate === '' ) {
                return ''; // wordlist too small — refuse
            }
            $owner = self::receipt_id_for_token( $candidate );
            if ( $owner === 0 || $owner === (int) $post_id ) {
                $token = $candidate;
                break;
            }
        }
        if ( $token === '' ) {
            return '';
        }
        update_post_meta( (int) $post_id, '_share_token', $token );
        return $token;
    }

    /** The public capability URL for a receipt: https://site/receipt/<token>/
     *  (token-only; the address is not in the URL, so nothing is enumerable). */
    public static function receipt_share_url( int $post_id ): string {
        $token = self::ensure_share_token( $post_id );
        if ( $token === '' ) {
            return '';
        }
        return home_url( '/receipt/' . $token . '/' );
    }

    /** Resolve a receipt id from a token, constant-time. 0 on any miss. */
    public static function receipt_id_for_token( string $incoming ): int {
        $token = self::normalize_share_token( $incoming );
        if ( $token === '' || strpos( $token, '-' ) === false ) {
            return 0; // a real token is >= 2 hyphen-joined words
        }
        $q = new WP_Query( [
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
            'fields'         => 'ids',
            'meta_query'     => [ [ 'key' => '_share_token', 'value' => $token ] ],
        ] );
        if ( empty( $q->posts ) ) {
            return 0;
        }
        $post_id = (int) $q->posts[0];
        $stored  = (string) get_post_meta( $post_id, '_share_token', true );
        if ( ! hash_equals( $stored, $token ) ) {
            return 0;
        }
        return $post_id;
    }

    /**
     * v3.6.9 (C1) — Cutover timestamp. Receipts created BEFORE this are "legacy"
     * (their printed /receipt/<address> links keep working); receipts created
     * AFTER are token-only (address never resolves). Set once, on first load
     * after the 3.6.9 deploy, so there is a hard, race-light boundary.
     */
    public function maybe_set_token_cutover() {
        if ( get_option( 'zrcpt_token_cutover', '' ) === '' ) {
            update_option( 'zrcpt_token_cutover', gmdate( 'Y-m-d H:i:s' ) );
        }
    }

    /** Is this receipt a legacy (pre-cutover) one whose printed address URL must
     *  keep working? Lexicographic compare on the ISO GMT datetime. Before the
     *  cutover is set, treat all as legacy (fails safe: nothing breaks). */
    public static function receipt_is_legacy( $post ): bool {
        $cutover = (string) get_option( 'zrcpt_token_cutover', '' );
        if ( $cutover === '' ) {
            return true;
        }
        $created = is_object( $post ) ? (string) $post->post_date_gmt : '';
        if ( $created === '' ) {
            return true;
        }
        return ( $created <= $cutover );
    }

    /** Resolve a receipt id from a legacy address slug — ONLY for grandfathered
     *  pre-cutover receipts. New receipts return 0 here (not address-reachable). */
    public static function receipt_id_for_legacy_slug( string $path ): int {
        $slug = sanitize_title( $path );
        if ( $slug === '' ) {
            return 0;
        }
        $post = get_page_by_path( $slug, OBJECT, self::POST_TYPE );
        if ( ! $post || $post->post_status !== 'publish' ) {
            return 0;
        }
        if ( ! self::receipt_is_legacy( $post ) ) {
            return 0; // new receipts are token-only — never reachable by address
        }
        return (int) $post->ID;
    }

    /** Make get_permalink() for receipts return the token URL everywhere (email,
     *  FreshBooks link, admin history) — one filter instead of touching every
     *  call site. */
    public function filter_receipt_permalink( $url, $post ) {
        if ( is_object( $post ) && isset( $post->post_type ) && $post->post_type === self::POST_TYPE ) {
            $token_url = self::receipt_share_url( (int) $post->ID );
            if ( $token_url !== '' ) {
                return $token_url;
            }
        }
        return $url;
    }

    /** Keep receipts out of XML sitemaps (belt-and-suspenders; a non-public CPT
     *  is already excluded by core, but explicit is safer). */
    public function exclude_from_sitemaps( $post_types ) {
        unset( $post_types[ self::POST_TYPE ] );
        return $post_types;
    }

    /** The ONLY front-end route to a receipt: /receipt/<token-or-legacy-slug>/. */
    public function add_share_rewrite() {
        add_rewrite_rule(
            '^receipt/([^/]+)/?$',
            'index.php?zrcpt_receipt_path=$matches[1]',
            'top'
        );
    }
    public function add_share_query_var( $vars ) {
        $vars[] = 'zrcpt_receipt_path';
        return $vars;
    }

    /** Best-effort per-IP rate limit on the token endpoint (blunts guessing —
     *  matters most for the legacy address URLs, which carry no secret). Fail
     *  open: a real customer opening their link a few times is well under the cap. */
    private function share_rate_ok(): bool {
        $limit = (int) apply_filters( 'zrcpt_share_rate_limit', 30 );
        if ( $limit <= 0 ) {
            return true;
        }
        $ip = $this->client_ip();
        if ( $ip === '' ) {
            return true;
        }
        $key  = 'zrcpt_rl_' . md5( $ip );
        $hits = (int) get_transient( $key );
        if ( $hits >= $limit ) {
            return false;
        }
        set_transient( $key, $hits + 1, MINUTE_IN_SECONDS );
        return true;
    }

    /** 404 that never confirms a receipt exists (don't leak existence). */
    private function receipt_404() {
        status_header( 404 );
        nocache_headers();
        header( 'X-Robots-Tag: noindex', true );
        global $wp_query;
        if ( isset( $wp_query ) && is_object( $wp_query ) ) {
            $wp_query->set_404();
        }
        $tpl = get_query_template( '404' );
        if ( $tpl ) {
            include $tpl;
        }
        exit;
    }

    private function __construct() {
        // Custom Post Type
        add_action( 'init', [ $this, 'register_post_type' ] );

        // Admin
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );

        // Admin list columns
        add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', [ $this, 'receipt_columns' ] );
        add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', [ $this, 'receipt_column_content' ], 10, 2 );

        // v3.7.0: admin "Regenerate link" row action (revoke a leaked secret link)
        add_filter( 'post_row_actions', [ $this, 'receipt_row_actions' ], 10, 2 );
        add_action( 'admin_post_zrcpt_regenerate_link', [ $this, 'handle_regenerate_link' ] );
        add_action( 'admin_notices', [ $this, 'regenerate_link_notice' ] );

        // v3.9.0 — Manage Receipts admin table + deletion-request workflow.
        //   Admin-side (admin-post, manage_options + nonce each):
        add_action( 'admin_post_zrcpt_admin_trash_receipt',   [ $this, 'handle_admin_trash_receipt' ] );
        add_action( 'admin_post_zrcpt_admin_restore_receipt', [ $this, 'handle_admin_restore_receipt' ] );
        add_action( 'admin_post_zrcpt_admin_delete_forever',  [ $this, 'handle_admin_delete_forever' ] );
        add_action( 'admin_post_zrcpt_admin_decline_delreq',  [ $this, 'handle_admin_decline_delreq' ] );
        // v3.9.3 — manual invoice-link repair from the Manage Receipts table.
        add_action( 'admin_post_zrcpt_admin_sync_fb_link',    [ $this, 'handle_admin_sync_fb_link' ] );
        //   Widget-side (AJAX):
        add_action( 'wp_ajax_zrcpt_request_deletion',      [ $this, 'ajax_request_deletion' ] );
        add_action( 'wp_ajax_zrcpt_cancel_delete_request', [ $this, 'ajax_cancel_delete_request' ] );
        add_action( 'wp_ajax_zrcpt_admin_delete_receipt',  [ $this, 'ajax_admin_delete_receipt' ] );

        // Allow HEIC uploads
        add_filter( 'upload_mimes', [ $this, 'allow_heic_mimes' ] );

        // Template override — serve raw HTML for receipt pages.
        // Uses template_redirect at priority 1 (very early) to intercept BEFORE
        // the Zorderz theme's login gate can block public receipt access.
        add_action( 'template_redirect', [ $this, 'receipt_template' ], 1 );

        // Flush rewrite rules when the plugin version changes (handles updates).
        add_action( 'admin_init', [ $this, 'maybe_flush_rewrite_rules' ] );

        // v3.6.9 (C1) — secret share-link routing + de-index.
        add_action( 'init', [ $this, 'add_share_rewrite' ] );
        add_action( 'init', [ $this, 'maybe_set_token_cutover' ] );
        add_action( 'init', [ $this, 'maybe_flush_rewrite_rules' ], 99 ); // v3.6.9 (C1): flush on first front-end hit too (printed links)
        add_filter( 'query_vars', [ $this, 'add_share_query_var' ] );
        add_filter( 'post_type_link', [ $this, 'filter_receipt_permalink' ], 10, 2 );
        add_filter( 'wp_sitemaps_post_types', [ $this, 'exclude_from_sitemaps' ] );

        // Shortcode
        add_shortcode( 'zrcpt_receipt_gen', [ $this, 'render_shortcode' ] );

        // AJAX — photo upload
        add_action( 'wp_ajax_zrcpt_upload_photo', [ $this, 'ajax_upload_photo' ] );

        // AJAX — generate receipt
        add_action( 'wp_ajax_zrcpt_generate', [ $this, 'ajax_generate' ] );

        // v2.9.0 — Auto-Lookup AJAX endpoints + Test Connection diagnostics.
        add_action( 'wp_ajax_zrcpt_lookup',                 [ $this, 'ajax_lookup' ] );
        add_action( 'wp_ajax_zrcpt_pull_nutshell_install',  [ $this, 'ajax_pull_nutshell_install' ] );
        add_action( 'wp_ajax_zrcpt_test_fb',                [ $this, 'ajax_test_fb' ] );
        add_action( 'wp_ajax_zrcpt_test_ns',                [ $this, 'ajax_test_ns' ] );

        // v3.0.0 — Smart Lookup
        add_action( 'wp_ajax_zrcpt_smart_lookup', [ $this, 'ajax_smart_lookup' ] );

        // v3.1.0 — Photo-first: pull the tech's already-captured photos from the
        // shared media library and group them into capture sessions so the newest
        // set can be used as the installation set with no upload step.
        add_action( 'wp_ajax_zrcpt_match_media', [ $this, 'ajax_match_media' ] );

        // v3.6.0 — Approve & Send. The tech who generated the receipt must
        // PREVIEW it (scroll through the whole thing) and affirmatively approve
        // it — that click is their sign-off / providence that the receipt is
        // correct. Only an APPROVED receipt may be sent to the customer.
        //   • zrcpt_receipt_detail — returns one receipt's HTML + approval/send
        //     state to drive the preview-and-approve modal in History.
        //   • zrcpt_approve_receipt — records the signed-in approver, timestamp,
        //     IP, user agent, and a hash of the exact HTML they approved.
        //   • zrcpt_send_receipt — emails the verified customer (FB/Nutshell
        //     address) the link to their receipt; refuses unless approved.
        add_action( 'wp_ajax_zrcpt_receipt_detail',  [ $this, 'ajax_receipt_detail' ] );
        add_action( 'wp_ajax_zrcpt_approve_receipt', [ $this, 'ajax_approve_receipt' ] );
        add_action( 'wp_ajax_zrcpt_send_receipt',    [ $this, 'ajax_send_receipt' ] );
        // v3.6.4 — remove photo(s) from a receipt during Review & Approve.
        add_action( 'wp_ajax_zrcpt_remove_photos',   [ $this, 'ajax_remove_photos' ] );
        // v3.6.6 — drag-to-reorder photos during Review & Approve.
        add_action( 'wp_ajax_zrcpt_reorder_photos',  [ $this, 'ajax_reorder_photos' ] );

        // v2.9.0 — Trap 4: the ?zrcpt_from_cutter=1 handoff must bypass WP Engine's
        // full-page cache. Emit a Cache-Control: private, no-cache header whenever
        // the query param is present.
        add_action( 'send_headers', [ $this, 'maybe_bypass_page_cache' ] );

        // v2.9.0 — load the embedded FB/NS/HEIC clients.
        require_once __DIR__ . '/class-zrcpt-freshbooks.php';
        require_once __DIR__ . '/class-zrcpt-nutshell.php';
        require_once __DIR__ . '/class-zrcpt-heic.php';

        // v3.1.0 — bridge to the shared ZDZ_User_Media store (already-captured photos).
        require_once __DIR__ . '/class-zrcpt-media.php';

        // Migrate stale bot name from database
        $this->maybe_migrate_bot_name();
    }

    /**
     * Clear a legacy, product-named receipt-writer bot handle from stored options so a fresh
     * Zorderz install carries no vendor-specific bot name. The tenant sets their own handle in
     * settings; NO default handle is compiled in (ship-empty). Runs on load, writes only when
     * a legacy value is present.
     */
    private function maybe_migrate_bot_name() {
        $opts = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $opts ) || empty( $opts['bot_name'] ) ) {
            return;
        }
        // Legacy off-repo bot handles to clear ship EMPTY — a tenant supplies its
        // own set via this filter (or its private pack), so no vendor bot name is
        // ever compiled into the public module.
        $legacy = (array) apply_filters( 'zrcpt_legacy_bot_handles', [] );
        if ( $legacy && in_array( $opts['bot_name'], $legacy, true ) ) {
            $opts['bot_name'] = '';
            update_option( self::OPTION_KEY, $opts );
        }
    }

    /**
     * The receipt-writer bot handle: the tenant's configured handle, else a filter, else the
     * platform's general AI model (ZDZ_Core_Settings) so the feature still works. NO product
     * bot name is compiled in.
     */
    private function receipt_bot_handle(): string {
        $handle = (string) ( self::get_options()['bot_name'] ?? '' );
        $handle = (string) apply_filters( 'zrcpt_bot_handle', $handle );
        if ( $handle === '' && class_exists( 'ZDZ_Core_Settings' ) && method_exists( 'ZDZ_Core_Settings', 'get_ai_model' ) ) {
            $handle = (string) ZDZ_Core_Settings::get_ai_model();
        }
        return $handle;
    }

    /* =====================================================================
       CUSTOM POST TYPE
       ===================================================================== */

    public function register_post_type() {
        register_post_type( self::POST_TYPE, [
            'labels' => [
                'name'               => 'Installation Receipts',
                'singular_name'      => 'Installation Receipt',
                'add_new_item'       => 'Add New Receipt',
                'edit_item'          => 'Edit Receipt',
                'view_item'          => 'View Receipt',
                'all_items'          => 'All Receipts',
                'search_items'       => 'Search Receipts',
                'not_found'          => 'No receipts found.',
                'not_found_in_trash' => 'No receipts found in Trash.',
            ],
            'public'              => false, // v3.6.9 (C1): was true — killed enumeration
            // v3.9.1 — REQUIRED for the wp-admin menu to exist at all. show_ui
            // DEFAULTS to the value of `public`, so the C1 lockdown silently
            // flipped it false — WordPress only builds menus for show_ui=true
            // post types, so "Installation Receipts" (and Settings, and the
            // v3.7.0 Regenerate-link list, and v3.9.0 Manage Receipts) never
            // rendered. show_ui=true restores the ADMIN UI ONLY: the front
            // stays locked (public=false, publicly_queryable=false, rewrite=
            // false, query_var=false, show_in_rest=false) and the list screen
            // is capability-gated (capability_type 'page' → edit_pages).
            'show_ui'             => true,
            'show_in_menu'        => true,
            'menu_icon'           => 'dashicons-media-document',
            'menu_position'       => 25,
            'supports'            => [ 'title' ],
            'has_archive'         => false,
            'exclude_from_search' => true,
            'publicly_queryable'  => false, // v3.6.9 (C1): stops ?p=<id> / bare-slug
            'show_in_rest'        => false,
            'capability_type'     => 'page',
            'rewrite'             => false,  // v3.6.9 (C1): served via add_share_rewrite + token
            'query_var'           => false,
            'show_in_nav_menus'   => false,
        ] );
    }

    /* =====================================================================
       ADMIN — SETTINGS & LIST COLUMNS
       ===================================================================== */

    public function add_admin_menu() {
        // v3.9.0 — the receipt-aware management table (status, deletion
        // requests, trash/restore). The label carries a pending-request bubble
        // exactly like the WP comments-moderation one.
        $pending = $this->pending_delete_request_count();
        $bubble  = $pending > 0
            ? ' <span class="awaiting-mod count-' . $pending . '"><span class="pending-count">' . $pending . '</span></span>'
            : '';
        add_submenu_page(
            'edit.php?post_type=' . self::POST_TYPE,
            'Manage Receipts',
            'Manage Receipts' . $bubble,
            'manage_options',
            'zorderz-manage',
            [ $this, 'render_manage_page' ]
        );

        add_submenu_page(
            'edit.php?post_type=' . self::POST_TYPE,
            'Receipt Settings',
            'Settings',
            'manage_options',
            'zrcpt-settings',
            [ $this, 'render_settings_page' ]
        );

        // Dashboard page for Zorderz iframe bridge
        // Uses 'read' capability so non-admin TS roles (ts_sales, ts_operator) can access it.
        // Hidden from the admin menu (null parent) — accessed via direct URL or SPA bridge.
        add_submenu_page(
            null,
            'Generate Receipt',
            'Generate Receipt',
            'read',
            'zrcpt-dashboard',
            [ $this, 'render_dashboard_page' ]
        );
    }

    public function receipt_columns( $columns ) {
        $new = [];
        $new['cb']           = $columns['cb'];
        $new['title']        = 'Title';
        $new['ts_address']   = 'Address';
        $new['ts_date']      = 'Install Date';
        $new['ts_slug']      = 'URL Slug';
        $new['date']         = 'Created';
        return $new;
    }

    public function receipt_column_content( $column, $post_id ) {
        switch ( $column ) {
            case 'ts_address':
                echo esc_html( get_post_meta( $post_id, '_address_short', true ) );
                break;
            case 'ts_date':
                echo esc_html( get_post_meta( $post_id, '_install_date', true ) );
                break;
            case 'ts_slug':
                $slug = get_post_meta( $post_id, '_vanity_slug', true );
                if ( $slug ) {
                    $link = get_permalink( $post_id );
                    echo '<a href="' . esc_url( $link ) . '" target="_blank"><code>' . esc_html( $slug ) . '</code></a>';
                }
                break;
        }
    }

    /**
     * v3.7.0: add a "Regenerate link" row action to each receipt in the CPT list
     * so an authorized user can REVOKE a leaked secret link by rotating its token.
     */
    public function receipt_row_actions( $actions, $post ) {
        if ( ! ( $post instanceof \WP_Post ) || $post->post_type !== self::POST_TYPE ) {
            return $actions;
        }
        if ( ! self::user_can_access() || ! self::current_user_can_manage_receipt( (int) $post->ID ) ) {
            return $actions;
        }
        $url = wp_nonce_url(
            admin_url( 'admin-post.php?action=zrcpt_regenerate_link&post=' . (int) $post->ID ),
            'zrcpt_regenerate_link_' . (int) $post->ID
        );
        $actions['zrcpt_regenerate_link'] = sprintf(
            '<a href="%s" onclick="return confirm(&quot;Regenerate this secret link? The current link stops working immediately.&quot;);">Regenerate link</a>',
            esc_url( $url )
        );
        return $actions;
    }

    /**
     * v3.7.0: admin-post handler — verify nonce + access + per-receipt ownership,
     * rotate the token, redirect back to the receipt list with a status flag.
     */
    public function handle_regenerate_link() {
        $post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
        if ( ! $post_id || ! check_admin_referer( 'zrcpt_regenerate_link_' . $post_id ) ) {
            wp_die( 'Invalid request.' );
        }
        if ( ! self::user_can_access() || ! self::current_user_can_manage_receipt( $post_id ) ) {
            wp_die( 'You are not allowed to do that.' );
        }
        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== self::POST_TYPE ) {
            wp_die( 'Receipt not found.' );
        }
        $new    = self::regenerate_share_token( $post_id );
        $status = ( $new !== '' ) ? 'zrcpt_link_ok' : 'zrcpt_link_err';
        // v3.9.3 — a revoked token = a NEW receipt URL. The invoice's
        // "Installation Receipt - Link" line must follow immediately, or it
        // keeps pointing at the dead old link. Best-effort; never blocks.
        if ( $new !== '' ) {
            try { $this->fb_sync_receipt_link( $post_id ); } catch ( \Throwable $e ) {
                error_log( 'ZRCPT FB SYNC: post-revoke sync failed for post ' . $post_id . ': ' . $e->getMessage() );
            }
        }
        wp_safe_redirect( add_query_arg(
            [ 'post_type' => self::POST_TYPE, $status => 1 ],
            admin_url( 'edit.php' )
        ) );
        exit;
    }

    /** v3.7.0: confirmation notice after a link regenerate. */
    public function regenerate_link_notice() {
        if ( ! empty( $_GET['zrcpt_link_ok'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>Receipt secret link regenerated — the old link no longer works.</p></div>';
        } elseif ( ! empty( $_GET['zrcpt_link_err'] ) ) {
            echo '<div class="notice notice-error is-dismissible"><p>Could not regenerate the receipt link. Check the share word-list.</p></div>';
        }
    }

    public function register_settings() {
        register_setting( self::OPTION_GROUP, self::OPTION_KEY, [
            'sanitize_callback' => [ $this, 'sanitize_options' ],
            'default'           => self::defaults(),
        ] );

        add_settings_section( 'zrcpt_api', 'Poe API Configuration', function () {
            echo '<p>Get your API key at <a href="https://poe.com/api_key" target="_blank">poe.com/api_key</a>.</p>';
        }, 'zrcpt-settings' );

        add_settings_field( 'api_key', 'Poe API Key', function () {
            $val = esc_attr( self::get_options()['api_key'] );
            echo "<input type='password' name='" . self::OPTION_KEY . "[api_key]' value='{$val}' class='regular-text' autocomplete='off' />";
        }, 'zrcpt-settings', 'zrcpt_api' );

        add_settings_field( 'bot_name', 'Bot Handle', function () {
            $val = esc_attr( self::get_options()['bot_name'] );
            echo "<input type='text' name='" . self::OPTION_KEY . "[bot_name]' value='{$val}' class='regular-text' />";
            echo "<p class='description'>Exact bot/model handle on your AI gateway (optional; blank uses the platform's configured model).</p>";
        }, 'zrcpt-settings', 'zrcpt_api' );

        add_settings_field( 'cdn_base_url', 'Image CDN base URL', function () {
            $val = esc_attr( self::get_options()['cdn_base_url'] );
            echo "<input type='text' name='" . self::OPTION_KEY . "[cdn_base_url]' value='{$val}' class='regular-text' placeholder='https://cdn.example.com' />";
            echo "<p class='description'>Optional. If your site serves images through a CDN, enter its base URL and receipt photo URLs will be rewritten onto it. "
               . "Prefer setting this in Business Profile → Web (asset CDN host), which this field falls back to. "
               . "Leave blank to serve images from your site domain.</p>";
        }, 'zrcpt-settings', 'zrcpt_api' );
    }

    public function sanitize_options( $input ) {
        $cdn = trim( $input['cdn_base_url'] ?? '' );
        // Strip trailing slashes and ensure https://
        if ( $cdn ) {
            $cdn = rtrim( $cdn, '/' );
            if ( strpos( $cdn, '://' ) === false ) {
                $cdn = 'https://' . $cdn;
            }
        }
        return [
            'api_key'      => sanitize_text_field( $input['api_key'] ?? '' ),
            'bot_name'     => sanitize_text_field( $input['bot_name'] ?? '' ),
            'cdn_base_url' => esc_url_raw( $cdn ),
        ];
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        // Warn if pretty permalinks are not configured
        $permalink_structure = get_option( 'permalink_structure', '' );
        ?>
        <div class="wrap">
            <h1>Receipt Generator</h1>
            <?php if ( empty( $permalink_structure ) ) : ?>
                <div class="notice notice-error">
                    <p><strong>Pretty Permalinks Required</strong> — Receipt URLs are currently ugly
                    (<code>?zrcpt_receipt=slug</code>). Go to
                    <a href="<?php echo esc_url( admin_url( 'options-permalink.php' ) ); ?>">Settings &rarr; Permalinks</a>
                    and choose any structure other than "Plain" (we recommend <strong>Post name</strong>)
                    to get clean <code>/receipt/slug/</code> URLs.</p>
                </div>
            <?php endif; ?>
            <form method="post" action="options.php">
                <?php settings_fields( self::OPTION_GROUP ); do_settings_sections( 'zrcpt-settings' ); submit_button(); ?>
            </form>
            <hr />
            <p>Place <code>[zrcpt_receipt_gen]</code> on any page to show the form.</p>

            <hr />

            <h2>Credentials &amp; Connection Status</h2>
            <p>
                Auto-lookup uses the platform's shared billing (FreshBooks) and CRM (Nutshell)
                credentials, resolved through the theme's Core settings
                (<code>ZDZ_Core_Settings</code>) and refreshed by the kernel token service
                (single-flight OAuth). Configure them once in the Zorderz settings.
            </p>
            <p>
                <strong>Billing connection:</strong>
                <?php if ( ( class_exists( 'ZDZ_Core_Settings' ) && ( new ZRCPT_FreshBooks() )->is_ready() ) ) : ?>
                    <span style="color:#0a7a3a;font-weight:600;">✓ Connected — auto-lookup enabled</span>
                <?php else : ?>
                    <span style="color:#b32d2e;font-weight:600;">✗ Not configured — connect a billing provider in Zorderz settings to enable auto-lookup</span>
                <?php endif; ?>
            </p>

            <p>
                <strong>Photo library (auto-pull):</strong>
                <?php if ( class_exists( 'ZDZ_User_Media' ) && method_exists( 'ZDZ_User_Media', 'get_user_media' ) ) : ?>
                    <span style="color:#0a7a3a;font-weight:600;">✓ Available — the generator pulls each tech's already-captured photos automatically</span>
                <?php else : ?>
                    <span style="color:#b32d2e;font-weight:600;">✗ Not available — requires the Zorderz theme's shared media store (ZDZ_User_Media). The generator will fall back to manual photo upload.</span>
                <?php endif; ?>
                <br />
                <span class="description">
                    v3.1.0 is photo-first: the tech types a name / number / phone, and the
                    generator pulls the FreshBooks job, the Nutshell install notes, and the
                    installation photos they already captured (newest capture set = the
                    install). The install date is read from the photo's EXIF date. Manual
                    upload is an optional fallback.
                </span>
            </p>

            <p>
                <button type="button" class="button button-primary" id="zrcpt-test-fb-btn">Test FreshBooks</button>
                <button type="button" class="button button-primary" id="zrcpt-test-ns-btn" style="margin-left:8px;">Test Nutshell</button>
            </p>

            <div id="zrcpt-test-results" style="margin-top:1em;"></div>

            <style>
                .zrcpt-test-block { background:#fff; border:1px solid #ccd0d4; padding:12px 16px; margin-top:8px; border-radius:4px; font-family:-apple-system,BlinkMacSystemFont,sans-serif; }
                .zrcpt-test-block h4 { margin:0 0 8px 0; font-size:14px; }
                .zrcpt-test-block ul { margin:4px 0 0 0; padding-left:20px; }
                .zrcpt-test-block li { margin:2px 0; font-size:13px; }
                .zrcpt-test-ok { color:#0a7a3a; font-weight:600; }
                .zrcpt-test-fail { color:#b32d2e; font-weight:600; }
                .zrcpt-test-err { color:#666; font-size:12px; font-family:monospace; word-break:break-all; }
            </style>

            <script>
            (function(){
                var nonce = '<?php echo esc_js( wp_create_nonce( self::NONCE_ACTION ) ); ?>';
                var ajaxurl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
                var resultsEl = document.getElementById('zrcpt-test-results');

                function esc(s){ var d=document.createElement('div'); d.textContent=(s==null?'':String(s)); return d.innerHTML; }

                function run(btn, action, label) {
                    btn.disabled = true;
                    var orig = btn.textContent;
                    btn.textContent = 'Testing…';
                    var body = new FormData();
                    body.append('action', action);
                    body.append('nonce', nonce);
                    fetch(ajaxurl, { method:'POST', credentials:'same-origin', body:body })
                        .then(function(r){ return r.json(); })
                        .then(function(res){
                            btn.disabled = false;
                            btn.textContent = orig;
                            renderResult(label, res);
                        })
                        .catch(function(err){
                            btn.disabled = false;
                            btn.textContent = orig;
                            renderResult(label, { success:false, data:{ message:'Network error: '+err } });
                        });
                }

                function renderResult(label, res) {
                    var block = document.createElement('div');
                    block.className = 'zrcpt-test-block';
                    var html = '<h4>' + esc(label) + '</h4>';

                    if (!res || !res.success) {
                        html += '<p class="zrcpt-test-fail">✗ Failed</p>';
                        if (res && res.data && res.data.message) html += '<p class="zrcpt-test-err">' + esc(res.data.message) + '</p>';
                        block.innerHTML = html;
                        resultsEl.prepend(block);
                        return;
                    }

                    var d = res.data;
                    if (d.tests) {
                        html += '<ul>';
                        Object.keys(d.tests).forEach(function(k){
                            var t = d.tests[k];
                            var ok = t.ok ? '<span class="zrcpt-test-ok">✓</span>' : '<span class="zrcpt-test-fail">✗</span>';
                            html += '<li>' + ok + ' <strong>' + esc(t.label || k) + '</strong>';
                            if (!t.ok && t.error) html += '<div class="zrcpt-test-err">' + esc(t.error) + '</div>';
                            html += '</li>';
                        });
                        html += '</ul>';
                    } else if (d.result) {
                        var r = d.result;
                        var ok = r.ok ? '<span class="zrcpt-test-ok">✓ Connected</span>' : '<span class="zrcpt-test-fail">✗ Failed</span>';
                        html += '<p>' + ok + '</p>';
                        if (r.message) html += '<p>' + esc(r.message) + '</p>';
                        if (r.error) html += '<p class="zrcpt-test-err">' + esc(r.error) + '</p>';
                    }

                    if (d.sources) {
                        html += '<p style="margin-top:8px; font-size:12px; color:#666;"><em>Credentials resolved from:</em></p><ul>';
                        Object.keys(d.sources).forEach(function(k){
                            var s = d.sources[k];
                            if (s.present) {
                                html += '<li><code>' + esc(k) + '</code> ← <code>' + esc(s.prefix + k) + '</code></li>';
                            } else {
                                html += '<li><code>' + esc(k) + '</code> <span class="zrcpt-test-fail">not found</span></li>';
                            }
                        });
                        html += '</ul>';
                    }

                    block.innerHTML = html;
                    resultsEl.prepend(block);
                }

                document.getElementById('zrcpt-test-fb-btn').addEventListener('click', function(){ run(this, 'zrcpt_test_fb', 'FreshBooks'); });
                document.getElementById('zrcpt-test-ns-btn').addEventListener('click', function(){ run(this, 'zrcpt_test_ns', 'Nutshell'); });
            })();
            </script>

            <p style="margin-top:2em;">Plugin version: <code><?php echo esc_html( self::VERSION ); ?></code></p>
        </div>
        <?php
    }

    /* =====================================================================
       Zorderz DASHBOARD PAGE
       Renders the receipt form in a standalone admin page accessible by all
       TS roles. Detects ts_mobile=1 to hide WP Admin chrome when loaded
       inside the Zorderz SPA bottom sheet iframe.
       ===================================================================== */

    public function render_dashboard_page() {
        if ( ! is_user_logged_in() ) {
            wp_die( 'Please log in to use the receipt generator.' );
        }

        // Zorderz iframe bridge appends ?ts_mobile=1 to hide WP Admin chrome
        $is_mobile = isset( $_GET['ts_mobile'] ) && $_GET['ts_mobile'] === '1';

        if ( $is_mobile ) {
            echo '<style>
                #wpadminbar, #adminmenuwrap, #adminmenuback,
                #wpfooter, .update-nag, .notice { display: none !important; }
                #wpcontent { margin-left: 0 !important; padding-top: 0 !important; }
                html { margin-top: 0 !important; }
            </style>';
        }

        echo '<div class="wrap">';

        if ( ! $is_mobile ) {
            echo '<h1>Receipt Generator</h1>';
        }

        $opts = self::get_options();
        if ( empty( $opts['api_key'] ) && current_user_can( 'manage_options' ) ) {
            echo '<div class="notice notice-error"><p>Poe API key not configured. '
               . '<a href="' . esc_url( admin_url( 'admin.php?page=zrcpt-settings' ) ) . '">Set it up here</a>.'
               . '</p></div>';
        }

        $this->render_form();

        echo '</div>';
    }

    public function allow_heic_mimes( $mimes ) {
        $mimes['heic'] = 'image/heic';
        $mimes['heif'] = 'image/heif';
        return $mimes;
    }

    /* =====================================================================
       TEMPLATE OVERRIDE — serve raw HTML for receipt pages
       ===================================================================== */

    /**
     * Hooked to `template_redirect` at priority 1 (very early).
     *
     * Fires BEFORE the Zorderz theme can render its login gate, so
     * receipt pages are served as public stand-alone HTML pages — no theme
     * header, footer, or login wall. If no _receipt_html meta exists, we
     * fall through and let WordPress handle the request normally.
     */
    public function receipt_template() {
        $path = (string) get_query_var( 'zrcpt_receipt_path' );
        if ( $path === '' ) {
            // The CPT is non-public, so a singular receipt query should never
            // resolve — but if some legacy path still does, refuse to echo it
            // without a token rather than leak it.
            if ( is_singular( self::POST_TYPE ) ) {
                $this->receipt_404();
            }
            return;
        }

        // Blunt online guessing (matters most for legacy address URLs).
        if ( ! $this->share_rate_ok() ) {
            status_header( 429 );
            header( 'Retry-After: 60' );
            nocache_headers();
            header( 'X-Robots-Tag: noindex', true );
            echo 'Too many requests — please wait a minute and open your link again.';
            exit;
        }

        // 1) Word-token route (new, secure). 2) Legacy printed-address route
        //    (only pre-cutover receipts resolve here).
        $post_id = self::receipt_id_for_token( $path );
        if ( ! $post_id ) {
            $post_id = self::receipt_id_for_legacy_slug( $path );
        }
        if ( ! $post_id ) {
            $this->receipt_404();
        }

        $html = (string) get_post_meta( $post_id, '_receipt_html', true );
        if ( $html === '' ) {
            $this->receipt_404();
        }

        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        status_header( 200 );
        header( 'Content-Type: text/html; charset=utf-8' );
        // Layered de-index + capability-URL hygiene (see design doc):
        header( 'X-Robots-Tag: noindex, nofollow', true );
        header( 'Referrer-Policy: no-referrer', true );
        header( 'Cache-Control: private, no-store, max-age=0', true );
        nocache_headers();
        echo $html;
        exit;
    }

    /* =====================================================================
       REWRITE RULES — flush on version change
       ===================================================================== */

    /**
     * Flush rewrite rules when the plugin version changes.
     * This ensures /receipt/<slug>/ pretty permalinks work after updates,
     * not just on first activation.
     */
    public function maybe_flush_rewrite_rules() {
        $stored = get_option( 'zrcpt_version', '' );
        if ( $stored !== self::VERSION ) {
            $this->register_post_type();
            $this->add_share_rewrite();
            flush_rewrite_rules();
            update_option( 'zrcpt_version', self::VERSION );
        }
    }

    /* =====================================================================
       SHORTCODE
       ===================================================================== */

    public function render_shortcode( $atts ) {
        if ( ! is_user_logged_in() ) {
            return '<p style="text-align:center;padding:2rem;color:#888;">Please <a href="' . wp_login_url( get_permalink() ) . '">log in</a> to use the receipt generator.</p>';
        }
        if ( ! self::user_can_access() ) { // v3.6.9 (L1): only users granted this app see the generator UI
            return '<p style="text-align:center;padding:2rem;color:#888;">You do not have access to the receipt generator.</p>';
        }
        $opts = self::get_options();
        if ( empty( $opts['api_key'] ) && current_user_can( 'manage_options' ) ) {
            return '<p style="text-align:center;padding:2rem;color:#c00;">Poe API key not configured. <a href="' . admin_url( 'options-general.php?page=zorderz' ) . '">Set it up here</a>.</p>';
        }

        // v2.9.0 — enqueue the Auto-Lookup assets.
        wp_enqueue_style(
            'zrcpt-lookup-css',
            plugin_dir_url( __FILE__ ) . 'assets/css/lookup.css',
            [],
            self::VERSION
        );
        wp_enqueue_script(
            'zrcpt-lookup-js',
            plugin_dir_url( __FILE__ ) . 'assets/js/lookup.js',
            [],
            self::VERSION,
            true
        );

        // v2.9.0 — handoff reader. If ?zrcpt_from_cutter=1 is present, pass
        // estimate_id + customer_id to the front-end so the lookup auto-runs.
        $from_cutter = [];
        if ( ! empty( $_GET['zrcpt_from_cutter'] ) ) {
            $from_cutter = [
                'estimate_id' => isset( $_GET['estimate_id'] ) ? absint( $_GET['estimate_id'] ) : 0,
                'customer_id' => isset( $_GET['customer_id'] ) ? absint( $_GET['customer_id'] ) : 0,
                'source'      => 'prep',
            ];
        }

        wp_localize_script( 'zrcpt-lookup-js', 'tserLookupData', [
            'ajaxurl'    => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( self::NONCE_ACTION ),
            'fromCutter' => (object) $from_cutter, // object so JS sees {} when empty
        ] );

        ob_start();
        $this->render_lookup_bar();        // v2.9.0 new
        $this->render_form();              // existing manual-upload path — unchanged
        return ob_get_clean();
    }

    /**
     * v2.9.0 — Lookup bar rendered ABOVE the existing upload form.
     * Keeps the manual-upload path fully functional — this is an addition,
     * not a replacement.
     */
    private function render_lookup_bar() {
        ?>
        <div class="zrcpt-lookup" id="zrcpt-lookup">
            <div class="zrcpt-lookup-card-wrap">
                <h2 class="zrcpt-lookup-title">🔍 Find by estimate / invoice #</h2>
                <p class="zrcpt-lookup-hint">Type an estimate #, invoice #, customer name, or phone.</p>

                <div class="zrcpt-lookup-row">
                    <input type="text" id="zrcpt-lookup-input" placeholder="e.g. 5541 or Scott Meyer or 858-555-1212" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false">
                    <button type="button" id="zrcpt-lookup-btn">Find</button>
                </div>

                <div class="zrcpt-lookup-status" id="zrcpt-lookup-status"></div>
                <div class="zrcpt-lookup-error"  id="zrcpt-lookup-error"></div>

                <div class="zrcpt-lookup-cards"  id="zrcpt-lookup-cards"></div>

                <div class="zrcpt-lookup-confirmed" id="zrcpt-lookup-confirmed"></div>
                <div class="zrcpt-lookup-nutshell-hint" id="zrcpt-lookup-nutshell-hint"></div>
            </div>
        </div>
        <div class="zrcpt-lookup-divider">Or upload manually</div>
        <?php
    }

    /* =====================================================================
       FORM  (HTML + CSS + JS)
       ===================================================================== */

    private function render_form() {
        $nonce    = wp_create_nonce( self::NONCE_ACTION );
        $ajax_url = admin_url( 'admin-ajax.php' );
        ?>
<style>
.zrcpt{max-width:620px;margin:2rem auto;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#333}
.zrcpt *{box-sizing:border-box}
.zrcpt h2{color:#1e4d6e;font-size:1.55rem;font-weight:700;text-align:center;margin:0 0 .3rem}
.zrcpt .sub{text-align:center;color:#777;font-size:.88rem;margin:0 0 1.75rem}
.zrcpt-card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:2rem}
.zrcpt-field{margin-bottom:1.4rem}
.zrcpt-field label{display:block;color:#1e4d6e;font-weight:600;font-size:.9rem;margin-bottom:.3rem}
.zrcpt-field .note{font-size:.78rem;color:#999;margin-bottom:.35rem}
.zrcpt-field input[type=text],.zrcpt-field input[type=url],.zrcpt-field input[type=date]{width:100%;padding:.55rem .7rem;border:1px solid #ddd;border-radius:6px;font-size:.9rem}
.zrcpt-field input:focus{outline:none;border-color:#1e4d6e}
.zrcpt-drop{border:2px dashed #ccc;border-radius:8px;padding:1.4rem 1rem;text-align:center;cursor:pointer;background:#fafafa;transition:all .15s}
.zrcpt-drop:hover,.zrcpt-drop.over{border-color:#1e4d6e;background:#f0f6fa}
.zrcpt-drop .ico{font-size:1.6rem;margin-bottom:.2rem}
.zrcpt-drop p{margin:.15rem 0;font-size:.85rem;color:#777}
.zrcpt-drop .name{color:#333;font-weight:600}
.zrcpt-drop input[type=file]{display:none}
.zrcpt-thumbs{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.6rem}
.zrcpt-thumb{width:72px;height:72px;border-radius:6px;overflow:hidden;position:relative;border:1px solid #ddd;background:#f5f5f5}
.zrcpt-thumb img{width:100%;height:100%;object-fit:cover}
.zrcpt-thumb .rm{position:absolute;top:2px;right:2px;width:18px;height:18px;background:rgba(0,0,0,.55);color:#fff;border:none;border-radius:50%;font-size:11px;cursor:pointer;display:flex;align-items:center;justify-content:center;line-height:1}
.zrcpt-thumb.loading::after{content:'';position:absolute;inset:0;background:rgba(255,255,255,.7);display:flex;align-items:center;justify-content:center}
.zrcpt-btn{display:block;width:100%;padding:.7rem;background:#1e4d6e;color:#fff;font-size:1rem;font-weight:600;border:none;border-radius:25px;cursor:pointer;transition:background .15s;margin-top:.5rem}
.zrcpt-btn:hover{background:#163d58}
.zrcpt-btn:disabled{background:#a0b8c8;cursor:not-allowed}
.zrcpt-progress{display:none;text-align:center;padding:2.5rem 1.5rem}
.zrcpt-progress .spin{width:40px;height:40px;border:3px solid #e0e0e0;border-top-color:#1e4d6e;border-radius:50%;animation:tspin .8s linear infinite;margin:0 auto 1rem}
@keyframes tspin{to{transform:rotate(360deg)}}
.zrcpt-progress .st{font-size:.95rem;color:#333;font-weight:500}
.zrcpt-progress .sub{font-size:.82rem;color:#999;margin-top:.25rem}
.zrcpt-result{display:none;text-align:center;padding:2.5rem 1.5rem}
.zrcpt-result .ico{font-size:3rem;margin-bottom:.25rem}
.zrcpt-result h3{color:#1e4d6e;font-size:1.35rem;margin:.4rem 0 1rem;font-weight:700}
.zrcpt-result p{color:#666;font-size:.9rem;margin:.2rem 0}
.zrcpt-result .meta{text-align:left;background:#f8f9fa;border:1px solid #e8e8e8;border-radius:10px;padding:1.25rem 1.5rem;margin:0 0 1.25rem;font-size:.95rem;display:grid;grid-template-columns:auto 1fr;gap:.6rem 1rem;align-items:baseline}
.zrcpt-result .meta dt{font-weight:600;color:#1e4d6e;white-space:nowrap}
.zrcpt-result .meta dd{margin:0;color:#444;line-height:1.45}
.zrcpt-result .meta .permalink-row{grid-column:1/-1;border-top:1px solid #e8e8e8;padding-top:.6rem;margin-top:.2rem;font-size:.85rem;color:#888;word-break:break-all}
.zrcpt-result .meta .permalink-row a{color:#1e4d6e;text-decoration:none}
.zrcpt-result .meta .permalink-row a:hover{text-decoration:underline}
.zrcpt-result a.vbtn{display:inline-block;padding:.75rem 2rem;background:#1e4d6e;color:#fff;text-decoration:none;border-radius:25px;font-weight:600;font-size:1rem;transition:background .15s}
.zrcpt-result a.vbtn:hover{background:#163d58}
.zrcpt-result.err h3{color:#b00}
.zrcpt-result .retry{display:inline-block;margin-top:.75rem;padding:.5rem 1.2rem;background:#555;color:#fff;border:none;border-radius:25px;cursor:pointer;font-size:.85rem;font-weight:600}
.zrcpt-photo-count{font-size:.82rem;color:#1e4d6e;font-weight:600;margin-top:.4rem}
</style>

<div class="zrcpt" id="zrcpt-app">
    <h2>Receipt Generator</h2>
    <p class="sub">Upload your invoice and installation photos to create a branded receipt page</p>

    <div class="zrcpt-card">
        <!-- FORM -->
        <form id="zrcpt-form" enctype="multipart/form-data">
            <input type="hidden" name="action" value="zrcpt_generate" />
            <input type="hidden" name="_nonce" value="<?php echo esc_attr( $nonce ); ?>" />
            <input type="hidden" name="photo_data" id="zrcpt-photo-data" value="[]" />

            <div class="zrcpt-field">
                <label>Invoice / Estimate</label>
                <p class="note">PDF, JPG, PNG, WEBP, or HEIC</p>
                <div class="zrcpt-drop" id="zrcpt-inv-drop">
                    <div class="ico">&#128196;</div>
                    <p id="zrcpt-inv-label">Drop file here or click to browse</p>
                    <input type="file" name="invoice_file" id="zrcpt-inv-input" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif" />
                </div>
            </div>

            <div class="zrcpt-field">
                <label>Installation Photos</label>
                <p class="note">JPG, PNG, WEBP, or HEIC — uploaded to your media library</p>
                <div class="zrcpt-drop" id="zrcpt-photo-drop">
                    <div class="ico">&#128247;</div>
                    <p>Drop photos here or click to browse</p>
                    <input type="file" id="zrcpt-photo-input" accept=".jpg,.jpeg,.png,.webp,.heic,.heif" multiple />
                </div>
                <div class="zrcpt-thumbs" id="zrcpt-thumbs"></div>
                <div class="zrcpt-photo-count" id="zrcpt-photo-count"></div>
            </div>

            <div class="zrcpt-field">
                <label for="zrcpt-date">Installation Date</label>
                <input type="date" name="install_date" id="zrcpt-date" required />
            </div>

            <div class="zrcpt-field">
                <label for="zrcpt-inv-url">Invoice Link <span style="font-weight:400;color:#999">(optional)</span></label>
                <input type="url" name="invoice_url" id="zrcpt-inv-url" placeholder="https://example.com/receipt-link/" />
            </div>

            <button type="submit" class="zrcpt-btn" id="zrcpt-submit">Generate Receipt</button>
        </form>

        <!-- PROGRESS -->
        <div class="zrcpt-progress" id="zrcpt-progress">
            <div class="spin"></div>
            <div class="st" id="zrcpt-status">Sending to AI&hellip;</div>
            <div class="sub" id="zrcpt-substatus">This usually takes 30&ndash;90 seconds</div>
        </div>

        <!-- SUCCESS -->
        <div class="zrcpt-result" id="zrcpt-success">
            <div class="ico">&#9989;</div>
            <h3>Receipt Page Created</h3>
            <dl class="meta" id="zrcpt-meta"></dl>
            <a href="#" class="vbtn" id="zrcpt-link" target="_blank">View Receipt &rarr;</a>
        </div>

        <!-- ERROR -->
        <div class="zrcpt-result err" id="zrcpt-error">
            <div class="ico">&#10060;</div>
            <h3>Something went wrong</h3>
            <p id="zrcpt-errmsg"></p>
            <button class="retry" id="zrcpt-retry">Try Again</button>
        </div>
    </div>
</div>

<script>
(function(){
    var nonce   = '<?php echo esc_js( $nonce ); ?>';
    var ajaxUrl = '<?php echo esc_js( $ajax_url ); ?>';

    /* --- elements --- */
    var form      = document.getElementById('zrcpt-form');
    var invDrop   = document.getElementById('zrcpt-inv-drop');
    var invInput  = document.getElementById('zrcpt-inv-input');
    var invLabel  = document.getElementById('zrcpt-inv-label');
    var photoDrop = document.getElementById('zrcpt-photo-drop');
    var photoIn   = document.getElementById('zrcpt-photo-input');
    var thumbsEl  = document.getElementById('zrcpt-thumbs');
    var countEl   = document.getElementById('zrcpt-photo-count');
    var photoHid  = document.getElementById('zrcpt-photo-data');
    var progress  = document.getElementById('zrcpt-progress');
    var statusEl  = document.getElementById('zrcpt-status');
    var subEl     = document.getElementById('zrcpt-substatus');
    var successEl = document.getElementById('zrcpt-success');
    var errorEl   = document.getElementById('zrcpt-error');
    var errMsg    = document.getElementById('zrcpt-errmsg');
    var submitBtn = document.getElementById('zrcpt-submit');

    var photoData = [];      // [{url, id, thumb}]
    var uploading = 0;

    /* --- invoice drop --- */
    invDrop.addEventListener('click', function(){ invInput.click(); });
    wireDropzone(invDrop, function(files){ invInput.files = files; showInvFile(files[0]); });
    invInput.addEventListener('change', function(){ if(invInput.files.length) showInvFile(invInput.files[0]); });
    function showInvFile(f){
        var sz = f.size < 1048576
            ? (f.size / 1024).toFixed(0) + ' KB'
            : (f.size / 1048576).toFixed(1) + ' MB';
        invLabel.innerHTML = '<span class="name">' + esc(f.name) + '</span> (' + sz + ')';
    }

    /* --- photo drop --- */
    photoDrop.addEventListener('click', function(){ photoIn.click(); });
    wireDropzone(photoDrop, function(files){ handlePhotos(files); });
    photoIn.addEventListener('change', function(){ if(photoIn.files.length) handlePhotos(photoIn.files); });

    function wireDropzone(el, cb){
        el.addEventListener('dragover', function(e){ e.preventDefault(); el.classList.add('over'); });
        el.addEventListener('dragleave', function(){ el.classList.remove('over'); });
        el.addEventListener('drop', function(e){ e.preventDefault(); el.classList.remove('over'); if(e.dataTransfer.files.length) cb(e.dataTransfer.files); });
    }

    /* --- HEIC/HEIF → JPEG client-side conversion --- */
    function isHeic(file){
        var ext = (file.name || '').split('.').pop().toLowerCase();
        return ext === 'heic' || ext === 'heif';
    }
    function convertHeicToJpeg(file){
        return createImageBitmap(file).then(function(bmp){
            var c = document.createElement('canvas');
            c.width = bmp.width; c.height = bmp.height;
            c.getContext('2d').drawImage(bmp, 0, 0);
            if(bmp.close) bmp.close();
            return new Promise(function(resolve, reject){
                c.toBlob(function(blob){
                    if(!blob){ reject(new Error('Canvas conversion failed')); return; }
                    var newName = file.name.replace(/\.(heic|heif)$/i, '.jpg');
                    resolve(new File([blob], newName, {type:'image/jpeg'}));
                }, 'image/jpeg', 0.92);
            });
        });
    }

    /* --- photo upload --- */
    function handlePhotos(files){
        for(var i = 0; i < files.length; i++) processAndUpload(files[i]);
    }
    function processAndUpload(file){
        if(isHeic(file)){
            convertHeicToJpeg(file).then(function(jpgFile){
                uploadPhoto(jpgFile);
            }).catch(function(){
                /* Browser can't decode HEIC — upload raw, let server try */
                uploadPhoto(file);
            });
        } else {
            uploadPhoto(file);
        }
    }

    function uploadPhoto(file){
        var idx = photoData.length;
        var obj = { url: null, id: null, thumb: null };
        photoData.push(obj);
        updateCount();

        /* placeholder thumb */
        var el = document.createElement('div');
        el.className = 'zrcpt-thumb loading';
        el.innerHTML = '<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" />';
        thumbsEl.appendChild(el);

        uploading++;
        updateSubmitState();

        var fd = new FormData();
        fd.append('action', 'zrcpt_upload_photo');
        fd.append('_nonce', nonce);
        fd.append('photo', file);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', ajaxUrl);
        xhr.onload = function(){
            uploading--;
            updateSubmitState();
            try { var res = JSON.parse(xhr.responseText); } catch(e) { el.remove(); photoData.splice(idx, 1); updateCount(); return; }
            if(res.success){
                obj.url   = res.data.url;
                obj.id    = res.data.id;
                obj.thumb = res.data.thumbnail || res.data.url;
                el.className = 'zrcpt-thumb';
                el.innerHTML = '<img src="' + esc(obj.thumb) + '" /><button class="rm" type="button">&times;</button>';
                el.querySelector('.rm').addEventListener('click', function(){
                    el.remove();
                    var i = photoData.indexOf(obj);
                    if(i > -1) photoData.splice(i, 1);
                    updateCount();
                    syncHidden();
                });
                syncHidden();
            } else {
                el.remove();
                photoData.splice(idx, 1);
                updateCount();
            }
        };
        xhr.onerror = function(){ uploading--; updateSubmitState(); el.remove(); photoData.splice(idx, 1); updateCount(); };
        xhr.send(fd);
    }

    function syncHidden(){
        photoHid.value = JSON.stringify(photoData.filter(function(p){ return p.url; }));
    }
    function updateCount(){
        var n = photoData.filter(function(p){ return p.url; }).length;
        countEl.textContent = n ? n + ' photo' + (n > 1 ? 's' : '') + ' uploaded' : '';
    }
    function updateSubmitState(){
        submitBtn.disabled = uploading > 0;
        if(uploading > 0) submitBtn.textContent = 'Uploading photos\u2026';
        else submitBtn.textContent = 'Generate Receipt';
    }

    /* --- panels --- */
    function show(panel){
        form.style.display     = 'none';
        progress.style.display = 'none';
        successEl.style.display = 'none';
        errorEl.style.display   = 'none';
        if(panel) panel.style.display = 'block';
    }

    document.getElementById('zrcpt-retry').addEventListener('click', function(){
        show(null); form.style.display = 'block'; submitBtn.disabled = false; submitBtn.textContent = 'Generate Receipt';
    });

    /* --- progress steps --- */
    var steps = [
        ['Sending invoice to AI\u2026',     'Gemini 3.1 Pro is reading your invoice'],
        ['Analyzing vent details\u2026',     'Extracting sizes and types'],
        ['Verifying invoice data\u2026',     'Double-checking vent counts and address'],
        ['Building receipt page\u2026',      'Generating HTML with your photos'],
        ['Creating WordPress page\u2026',    'Setting up the vanity URL'],
        ['Almost there\u2026',              'Finalizing \u2014 this can take a minute or two'],
        ['Still working\u2026',             'Complex invoices may need extra processing time'],
    ];
    var stepTimer;
    function startSteps(){
        var i = 0; setStep(0);
        stepTimer = setInterval(function(){ i++; if(i < steps.length) setStep(i); else clearInterval(stepTimer); }, 25000);
    }
    function setStep(i){ statusEl.textContent = steps[i][0]; subEl.textContent = steps[i][1]; }

    /* --- submit --- */
    form.addEventListener('submit', function(e){
        e.preventDefault();

        if(!invInput.files.length){ invLabel.textContent = 'Please select an invoice file'; invLabel.style.color = '#b00'; return; }
        if(!document.getElementById('zrcpt-date').value) return;
        var ready = photoData.filter(function(p){ return p.url; });
        if(!ready.length){ countEl.textContent = 'Please upload at least one photo'; countEl.style.color = '#b00'; return; }

        syncHidden();
        submitBtn.disabled = true;
        show(progress);
        startSteps();

        var fd = new FormData(form);
        fd.set('action', 'zrcpt_generate');

        var xhr = new XMLHttpRequest();
        xhr.open('POST', ajaxUrl);
        xhr.timeout = 400000; /* 2 retries × 180s + overhead */
        xhr.onload = function(){
            clearInterval(stepTimer);
            try { var res = JSON.parse(xhr.responseText); } catch(ex) { show(errorEl); errMsg.textContent = 'Invalid server response.'; return; }
            if(res.success){
                var d = res.data;
                var vents = esc(d.vents).replace(/,\s*/g, '<br>');
                var fbHtml = '';
                if(d.fb_linked){
                    var docLabel = (d.fb_doc_type === 'estimate') ? 'Estimate' : 'Invoice';
                    fbHtml = '<dt>FreshBooks</dt><dd style="color:#16a34a;">&#10003; Receipt link added to ' + docLabel + ' #' + esc(d.fb_doc_number) + '</dd>';
                } else if(d.fb_error){
                    fbHtml = '<dt>FreshBooks</dt><dd style="color:#b00;">' + esc(d.fb_error) + '</dd>';
                }
                document.getElementById('zrcpt-meta').innerHTML =
                    '<dt>Address</dt><dd>' + esc(d.address) + '</dd>'
                  + '<dt>Date</dt><dd>' + esc(d.install_date) + '</dd>'
                  + '<dt>Vents</dt><dd>' + vents + '</dd>'
                  + '<dt>Photos</dt><dd>' + d.photo_count + ' images</dd>'
                  + fbHtml
                  + '<div class="permalink-row">&#128279; <a href="' + esc(d.permalink) + '">' + esc(d.permalink) + '</a></div>';
                document.getElementById('zrcpt-link').href = d.permalink;
                show(successEl);
            } else {
                show(errorEl);
                errMsg.textContent = res.data || 'Unknown error.';
            }
        };
        xhr.onerror = function(){ clearInterval(stepTimer); show(errorEl); errMsg.textContent = 'Network error.'; };
        xhr.ontimeout = function(){ clearInterval(stepTimer); show(errorEl); errMsg.textContent = 'Request timed out. Please try again.'; };
        xhr.send(fd);
    });

    function esc(s){ var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
})();
</script>
        <?php
    }

    /* =====================================================================
       AJAX — PHOTO UPLOAD (individual photos → media library)
       ===================================================================== */

    public function ajax_upload_photo() {

        $this->zrcpt_clean_output_buffer();
        if ( ! is_user_logged_in() || ! wp_verify_nonce( $_POST['_nonce'] ?? '', self::NONCE_ACTION ) ) {
            wp_send_json_error( 'Not authorized.', 403 );
        }
        if ( ! self::user_can_access() ) { // v3.6.9 (M2): require real app-access, not just any logged-in user
            wp_send_json_error( 'Not authorized.', 403 );
        }

        if ( empty( $_FILES['photo']['tmp_name'] ) ) {
            wp_send_json_error( 'No file received.' );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $ext = strtolower( pathinfo( $_FILES['photo']['name'], PATHINFO_EXTENSION ) );
        $tmp_path = $_FILES['photo']['tmp_name'];
        $base_name = pathinfo( $_FILES['photo']['name'], PATHINFO_FILENAME );

        // v3.6.2 — read the photo's forensic capture provenance (EXIF capture
        // time + GPS) from the ORIGINAL file, BEFORE Imagick strips it below, so
        // the shared-library mirror keeps the real "when/where" the shot was
        // taken (the same provenance ts-camera records). Best-effort: a photo
        // with no EXIF simply mirrors with a null captured_at.
        $exif_provenance = $this->read_exif_provenance( $tmp_path );

        $max_dim = 2048;   // Max width or height — keeps files web-friendly
        $quality = 85;     // JPEG quality — good balance of size vs clarity

        // Use Imagick for: HEIC conversion, and resizing any large image
        $is_heic = in_array( $ext, [ 'heic', 'heif' ], true );
        $needs_resize = ( $is_heic || in_array( $ext, [ 'jpg', 'jpeg', 'png', 'webp' ], true ) )
                        && class_exists( 'Imagick' );

        if ( $needs_resize ) {
            try {
                $im = new \Imagick();
                $im->readImage( $tmp_path );

                // Fix rotation FIRST (reads EXIF orientation, physically rotates pixels)
                $im->autoOrient();

                $im->setImageFormat( 'jpeg' );
                $im->setImageCompressionQuality( $quality );
                $im->stripImage(); // Safe to remove EXIF now — pixels are correctly oriented

                $w = $im->getImageWidth();
                $h = $im->getImageHeight();
                if ( $w > $max_dim || $h > $max_dim ) {
                    $im->thumbnailImage( $max_dim, $max_dim, true );
                }

                $tmp = wp_tempnam( 'photo.jpg' );
                $im->writeImage( $tmp );
                $im->destroy();

                $new_name = $base_name . '.jpg';
                $att_id = media_handle_sideload(
                    [ 'name' => $new_name, 'tmp_name' => $tmp ],
                    0, 'Installation photo'
                );
                if ( is_wp_error( $att_id ) ) {
                    @unlink( $tmp );
                    wp_send_json_error( $att_id->get_error_message() );
                }
            } catch ( \Exception $e ) {
                if ( $is_heic ) {
                    // HEIC conversion failed — don't upload raw HEIC (browsers can't display it)
                    wp_send_json_error(
                        'Could not convert HEIC photo on this server. '
                        . 'Please convert your photo to JPEG before uploading, '
                        . 'or try using Safari/Chrome which can auto-convert HEIC.'
                    );
                }
                // Non-HEIC: Imagick failed — fall back to raw upload
                $att_id = media_handle_upload( 'photo', 0, [ 'post_title' => 'Installation photo' ] );
                if ( is_wp_error( $att_id ) ) {
                    wp_send_json_error( $att_id->get_error_message() );
                }
            }
        } else {
            $att_id = media_handle_upload( 'photo', 0, [ 'post_title' => 'Installation photo' ] );
            if ( is_wp_error( $att_id ) ) {
                wp_send_json_error( $att_id->get_error_message() );
            }
        }

        // v3.6.2 — mirror this upload into the SHARED media library
        // (ZDZ_User_Media) as a company-wide "For Everybody" photo, tagged with
        // the receipt's customer (resolved from the FreshBooks/Nutshell job the
        // tech selected). This gives the company a durable, attributed record of
        // every install photo a tech uploads here — not just a private file on
        // one receipt. Best-effort and non-fatal: if the shared store isn't
        // present, or anything fails, the receipt upload itself still succeeds.
        $library_media_id = $this->mirror_upload_to_library( $att_id, $exif_provenance );

        wp_send_json_success( [
            'url'        => wp_get_attachment_url( $att_id ),
            'thumbnail'  => wp_get_attachment_image_url( $att_id, 'thumbnail' ),
            'id'         => $att_id,
            // The ZDZ_User_Media row id (0 when not mirrored), so the UI can
            // confirm "saved to the shared library" when it lands.
            'library_id' => (int) $library_media_id,
        ] );
    }

    /**
     * v3.6.2 — Read forensic capture provenance (EXIF DateTimeOriginal + GPS)
     * from an image file. Used to preserve the real capture time/location when
     * mirroring a receipt upload into the shared library, BEFORE we strip EXIF
     * for the web-friendly attachment. Returns ['captured_at'=>?string 'Y-m-d
     * H:i:s', 'gps_lat'=>?float, 'gps_lng'=>?float]; any field may be null.
     *
     * @param string $path Absolute path to the original uploaded image.
     * @return array{captured_at:?string,gps_lat:?float,gps_lng:?float}
     */
    private function read_exif_provenance( string $path ): array {
        $out = [ 'captured_at' => null, 'gps_lat' => null, 'gps_lng' => null ];

        if ( ! function_exists( 'exif_read_data' ) || ! is_readable( $path ) ) {
            return $out;
        }
        // exif_read_data only reads JPEG/TIFF; HEIC/PNG/WEBP simply yield false.
        $exif = @exif_read_data( $path );
        if ( ! is_array( $exif ) ) {
            return $out;
        }

        // Capture time: prefer DateTimeOriginal, then DateTimeDigitized, then DateTime.
        foreach ( [ 'DateTimeOriginal', 'DateTimeDigitized', 'DateTime' ] as $k ) {
            if ( ! empty( $exif[ $k ] ) ) {
                // EXIF format is "Y:m:d H:i:s"; normalize to MySQL datetime.
                $ts = strtotime( str_replace( ':', '-', substr( (string) $exif[ $k ], 0, 10 ) ) . substr( (string) $exif[ $k ], 10 ) );
                if ( $ts ) { $out['captured_at'] = gmdate( 'Y-m-d H:i:s', $ts ); break; }
            }
        }

        // GPS, when present (degrees/minutes/seconds rationals → decimal).
        if ( ! empty( $exif['GPSLatitude'] ) && ! empty( $exif['GPSLongitude'] ) ) {
            $lat = $this->exif_gps_to_decimal( $exif['GPSLatitude'], $exif['GPSLatitudeRef'] ?? 'N' );
            $lng = $this->exif_gps_to_decimal( $exif['GPSLongitude'], $exif['GPSLongitudeRef'] ?? 'E' );
            if ( $lat !== null ) $out['gps_lat'] = $lat;
            if ( $lng !== null ) $out['gps_lng'] = $lng;
        }

        return $out;
    }

    /** Convert an EXIF GPS coordinate (array of "num/den" rationals + ref) to a signed decimal. */
    private function exif_gps_to_decimal( $coord, string $ref ): ?float {
        if ( ! is_array( $coord ) || count( $coord ) < 3 ) return null;
        $parts = [];
        foreach ( array_slice( $coord, 0, 3 ) as $piece ) {
            if ( is_string( $piece ) && strpos( $piece, '/' ) !== false ) {
                list( $n, $d ) = array_map( 'floatval', explode( '/', $piece, 2 ) );
                $parts[] = $d ? ( $n / $d ) : 0.0;
            } else {
                $parts[] = (float) $piece;
            }
        }
        $dec = $parts[0] + ( $parts[1] / 60 ) + ( $parts[2] / 3600 );
        $ref = strtoupper( substr( trim( $ref ), 0, 1 ) );
        if ( $ref === 'S' || $ref === 'W' ) $dec = -$dec;
        return round( $dec, 7 );
    }

    /**
     * v3.6.2 — Save a just-uploaded attachment into the shared media library
     * (ZDZ_User_Media) as a company-wide "For Everybody" photo, tagged with the
     * receipt's customer. Returns the new ZDZ_User_Media row id, or 0 if the
     * store is unavailable or the save failed (never throws — the caller treats
     * mirroring as a best-effort bonus, not a requirement).
     *
     * Customer context comes from the request the widget sends alongside the
     * file (the FreshBooks/Nutshell job the tech selected): `customer_name`,
     * `customer_id`. We record it on the row's title/description AND in
     * meta_json.receipt so the photo is discoverable by customer later.
     *
     * @param int   $att_id          WP attachment id of the (web-friendly) upload.
     * @param array $exif_provenance Result of read_exif_provenance() on the original.
     * @return int  ZDZ_User_Media row id, or 0.
     */
    private function mirror_upload_to_library( int $att_id, array $exif_provenance ): int {
        if ( $att_id <= 0
            || ! class_exists( 'ZDZ_User_Media' )
            || ! method_exists( 'ZDZ_User_Media', 'save' ) ) {
            return 0;
        }

        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) return 0;

        // Customer tag from the selected job (sent with the upload by widget.js).
        $customer_name = isset( $_POST['customer_name'] )
            ? sanitize_text_field( wp_unslash( (string) $_POST['customer_name'] ) ) : '';
        $customer_id = isset( $_POST['customer_id'] )
            ? sanitize_text_field( wp_unslash( (string) $_POST['customer_id'] ) ) : '';
        $customer_name = trim( $customer_name );

        $file_url  = (string) wp_get_attachment_url( $att_id );
        if ( $file_url === '' ) return 0;
        $thumb_url = (string) ( wp_get_attachment_image_url( $att_id, 'large' ) ?: $file_url );
        $filename  = wp_basename( get_attached_file( $att_id ) ?: $file_url );

        // A human-readable, customer-tagged title so the photo reads sensibly in
        // the Media library and in any "photos for <customer>" search later.
        $title = $customer_name !== ''
            ? sprintf( 'Installation — %s', $customer_name )
            : 'Installation photo';
        $description = $customer_name !== ''
            ? sprintf( 'Installation photo uploaded via the Receipt app for %s.', $customer_name )
            : 'Installation photo uploaded via the Receipt app.';

        // meta_json — keep the same envelope shape other apps use, under a
        // dedicated `receipt` key (additive; no schema/contract change). This is
        // also where the customer association lives durably.
        $meta = [
            'receipt' => array_filter( [
                'customer'    => $customer_name,
                'customer_id' => $customer_id,
                'uploaded_at' => current_time( 'mysql' ),
                'source'      => 'zorderz',
            ], static function ( $v ) { return $v !== '' && $v !== null; } ),
        ];

        $save_args = [
            'user_id'          => $user_id,
            'file_url'         => $file_url,
            'thumbnail_url'    => $thumb_url,
            'filename'         => $filename,
            'media_type'       => 'photo',
            'source_app'       => 'zorderz',
            'source_ref'       => $customer_id !== '' ? ( 'customer:' . $customer_id ) : 'receipt-upload',
            'title'            => $title,
            'description'      => $description,
            // The key request: receipt uploads are company-wide, not private.
            // The Media library's "Everyone" toggle maps to privacy='public'.
            'privacy'          => 'public',
            'wp_attachment_id' => $att_id,
            'meta'             => $meta,
        ];

        // Carry forensic capture provenance when we have it (read-only at ingest,
        // exactly like ts-camera). When absent, the store records upload time.
        if ( ! empty( $exif_provenance['captured_at'] ) ) {
            $save_args['captured_at'] = $exif_provenance['captured_at'];
        }
        if ( $exif_provenance['gps_lat'] !== null && $exif_provenance['gps_lng'] !== null ) {
            $save_args['gps_lat'] = $exif_provenance['gps_lat'];
            $save_args['gps_lng'] = $exif_provenance['gps_lng'];
        }

        try {
            // ZDZ_User_Media::save() returns the inserted row (with 'id') on
            // success, or null on failure.
            $row = ZDZ_User_Media::save( $save_args );
            return ( is_array( $row ) && ! empty( $row['id'] ) ) ? (int) $row['id'] : 0;
        } catch ( \Throwable $e ) {
            error_log( '[zorderz] mirror_upload_to_library failed: ' . $e->getMessage() );
            return 0;
        }
    }

    /* =====================================================================
       AJAX — GENERATE RECEIPT
       ===================================================================== */

    public function ajax_generate() {

        // v3.3.8 — DEPLOY PROOF. This line fires on every generate. If you don't
        // see "ZRCPT VERSION 3.3.14 ajax_generate ENTER" in debug.log right after a
        // generate, WP Engine is still serving a cached older build (clear
        // opcache / "Clear all caches", or deactivate→reactivate the plugin).
        error_log( 'ZRCPT VERSION ' . self::VERSION . ' ajax_generate ENTER' );

        $this->zrcpt_clean_output_buffer();
        if ( ! is_user_logged_in() || ! wp_verify_nonce( $_POST['_nonce'] ?? '', self::NONCE_ACTION ) ) {
            wp_send_json_error( 'Security check failed.', 403 );
        }
        // v3.6.0 — hold generate to the same dual-RBAC bar as the other endpoints.
        // This is the endpoint that decides what becomes the stored customer email,
        // so authorization (not just a CSRF nonce) matters here.
        if ( ! self::user_can_access() ) { // v3.6.9 (H1): real app-access, not blanket zdz_access_app
            wp_send_json_error( 'You are not allowed to do that.', 403 );
        }

        /* --- Validate --- */
        $install_date_raw = sanitize_text_field( $_POST['install_date'] ?? '' );
        $invoice_url      = esc_url_raw( $_POST['invoice_url'] ?? '' );
        $photo_json       = stripslashes( $_POST['photo_data'] ?? '[]' );
        $photo_items      = json_decode( $photo_json, true );
        if ( ! is_array( $photo_items ) ) $photo_items = [];

        // v3.1.0 — receipt mode (tagged today; future modes gated in receipt_mode()).
        $mode = $this->receipt_mode( sanitize_text_field( $_POST['mode'] ?? '' ) );

        // v3.1.0 — Photos selected from the shared library (already captured by
        // the tech). These come as attachment IDs only. We mark them as library
        // photos so we DON'T re-parent them onto the receipt post — they live in
        // the user's media library and must stay in "My Photos".
        $media_ids_json = stripslashes( $_POST['media_ids'] ?? '' );
        $media_ids      = $media_ids_json ? json_decode( $media_ids_json, true ) : [];
        if ( is_array( $media_ids ) ) {
            foreach ( $media_ids as $mid ) {
                $mid = (int) $mid;
                if ( $mid > 0 ) {
                    $photo_items[] = [ 'id' => $mid, 'library' => true ];
                }
            }
        }

        if ( empty( $install_date_raw ) ) wp_send_json_error( 'Installation date is required.' );
        if ( empty( $photo_items ) ) {
            wp_send_json_error( 'No photos selected. Choose a photo set from your library, or upload photos manually.' );
        }

        // v3.0.0: Lookup data from smart lookup — makes invoice file optional.
        $lookup_json      = stripslashes( $_POST['lookup_data'] ?? '' );
        $lookup_data      = $lookup_json ? json_decode( $lookup_json, true ) : null;
        $has_lookup       = is_array( $lookup_data ) && ! empty( $lookup_data['number'] );
        $has_invoice_file = ! empty( $_FILES['invoice_file']['tmp_name'] );

        if ( ! $has_invoice_file && ! $has_lookup ) wp_send_json_error( 'Find a job via lookup first (or upload an invoice file).' );

        // v3.3.0 — INVOICE-ONLY GATE (server-side).
        // The set of FreshBooks documents this receipt is built from (one, or
        // several when combining). Every one MUST be an invoice — estimates are
        // proposals and can never become a receipt. This gate runs server-side
        // on every lookup-driven generate, independent of the front-end UI, so a
        // mis-built or stale client can't slip an estimate through. (The `type`
        // value itself originates from the FreshBooks search results echoed via
        // the client; a future hardening could re-fetch each doc to re-verify its
        // type server-side, but for this internal tool the gate on the returned
        // type is sufficient.)
        $invoice_set_json = stripslashes( $_POST['invoice_set'] ?? '' );
        $invoice_set      = $invoice_set_json ? json_decode( $invoice_set_json, true ) : [];
        if ( ! is_array( $invoice_set ) ) $invoice_set = [];

        $invoice_numbers_json = stripslashes( $_POST['invoice_numbers'] ?? '' );
        $invoice_numbers      = $invoice_numbers_json ? json_decode( $invoice_numbers_json, true ) : [];
        if ( ! is_array( $invoice_numbers ) ) $invoice_numbers = [];

        if ( $has_lookup ) {
            // Build the type list to validate. Prefer the explicit set; fall back
            // to the single lookup_data doc.
            $types_to_check = [];
            if ( ! empty( $invoice_set ) ) {
                foreach ( $invoice_set as $doc ) {
                    $types_to_check[] = strtolower( (string) ( $doc['type'] ?? '' ) );
                }
            } else {
                $types_to_check[] = strtolower( (string) ( $lookup_data['type'] ?? '' ) );
            }
            foreach ( $types_to_check as $t ) {
                if ( $t !== 'invoice' ) {
                    wp_send_json_error(
                        'Receipts can only be generated from invoices, not estimates. '
                        . 'Invoice the job in FreshBooks, then generate the receipt.'
                    );
                }
            }
        }

        // Normalize the source invoice numbers (digits) for provenance + the
        // already-receipted cross-check. Falls back to the single lookup number.
        $source_invoice_numbers = [];
        foreach ( $invoice_numbers as $n ) {
            $d = preg_replace( '/[^0-9]/', '', (string) $n );
            if ( $d !== '' ) $source_invoice_numbers[] = $d;
        }
        if ( empty( $source_invoice_numbers ) && $has_lookup ) {
            $d = preg_replace( '/[^0-9]/', '', (string) ( $lookup_data['number'] ?? '' ) );
            if ( $d !== '' ) $source_invoice_numbers[] = $d;
        }
        $source_invoice_numbers = array_values( array_unique( $source_invoice_numbers ) );

        /* --- Format date --- */
        $ts = strtotime( $install_date_raw );
        if ( ! $ts ) wp_send_json_error( 'Invalid date.' );
        $day = (int) date( 'j', $ts );
        $suf = date( 'S', mktime( 0, 0, 0, 1, $day ) );
        $install_date = date( 'F ', $ts ) . $day . $suf . date( ', Y', $ts );

        /* --- Collect photo URLs --- */
        $photo_urls    = [];
        $photo_ids     = [];   // uploaded photos (safe to re-parent to the receipt)
        $library_ids   = [];   // library photos (must NOT be re-parented)

        foreach ( $photo_items as $item ) {
            $url = '';

            // Prefer server-side lookup by attachment ID (most reliable)
            if ( ! empty( $item['id'] ) ) {
                $att_id = (int) $item['id'];
                $file   = get_post_meta( $att_id, '_wp_attached_file', true );
                if ( $file ) {
                    $uploads = wp_upload_dir();
                    // Build URL from the base upload URL + relative file path
                    $url = trailingslashit( $uploads['baseurl'] ) . $file;
                }
                if ( ! $url ) {
                    // Fallback to wp_get_attachment_url
                    $url = wp_get_attachment_url( $att_id );
                }
                if ( ! empty( $item['library'] ) ) {
                    $library_ids[] = $att_id;
                } else {
                    $photo_ids[] = $att_id;
                }
            }

            // If no URL from ID lookup, use client-provided URL
            if ( empty( $url ) && ! empty( $item['url'] ) ) {
                $url = $item['url'];
            }

            if ( empty( $url ) ) continue;

            // Normalize: relative → absolute
            if ( strpos( $url, '/' ) === 0 ) {
                $url = rtrim( home_url(), '/' ) . $url;
            }

            // Normalize: force HTTPS
            $url = preg_replace( '/^http:\/\//', 'https://', $url );

            $photo_urls[] = $url;
        }

        if ( empty( $photo_urls ) ) {
            wp_send_json_error(
                'Could not resolve any photo URLs from your uploads. '
                . 'Please try re-uploading your installation photos. '
                . '(Debug: ' . count( $photo_items ) . ' photo items received, '
                . 'raw data: ' . substr( $photo_json, 0, 300 ) . ')'
            );
        }

        /* --- Read & prepare invoice file --- */
        $file_b64  = null;
        $file_mime = null;
        $file_ext  = null;
        $orig_name = '';
        $tmp_path  = null;

        if ( $has_invoice_file ) {
        $file      = $_FILES['invoice_file'];
        $orig_name = sanitize_file_name( $file['name'] );
        $file_ext  = strtolower( pathinfo( $orig_name, PATHINFO_EXTENSION ) );
        $tmp_path  = $file['tmp_name'];

        $allowed = [ 'pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'heif' ];
        if ( ! in_array( $file_ext, $allowed, true ) ) wp_send_json_error( 'Unsupported file type.' );
        if ( $file['size'] > 15 * 1024 * 1024 )        wp_send_json_error( 'File too large (max 15 MB).' );

        // Compress image invoices to reduce API payload (max 1600px, JPEG quality 80)
        $img_exts = [ 'jpg', 'jpeg', 'png', 'webp', 'heic', 'heif' ];
        if ( in_array( $file_ext, $img_exts, true ) && class_exists( 'Imagick' ) ) {
            try {
                $im = new \Imagick();
                $im->readImage( $tmp_path );
                $im->autoOrient();  // Fix rotation before anything else
                $im->setImageFormat( 'jpeg' );
                $im->setImageCompressionQuality( 80 );
                $im->stripImage();
                $w = $im->getImageWidth();
                $h = $im->getImageHeight();
                if ( $w > 1600 || $h > 1600 ) {
                    $im->thumbnailImage( 1600, 1600, true );
                }
                $compressed = $tmp_path . '-compressed.jpg';
                $im->writeImage( $compressed );
                $im->destroy();
                $file_b64 = base64_encode( file_get_contents( $compressed ) );
                @unlink( $compressed );
                $file_ext  = 'jpeg';
                $orig_name = pathinfo( $orig_name, PATHINFO_FILENAME ) . '.jpg';
            } catch ( \Exception $e ) {
                // Compression failed — use original
                $file_b64 = base64_encode( file_get_contents( $tmp_path ) );
            }
        } else {
            $file_b64 = base64_encode( file_get_contents( $tmp_path ) );
        }

        $mime_map = [
            'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png', 'webp' => 'image/webp', 'heic' => 'image/heic', 'heif' => 'image/heif',
        ];
        $file_mime = $mime_map[ $file_ext ] ?? $file['type'];

        } elseif ( $has_lookup ) {
            // ── NEW PATH: Lookup-based (no file upload) ─────────────────
            // Build a text invoice summary from the lookup data so the bot
            // receives a "file" it can parse. The bot expects an attachment.
            $inv_lines = [];
            $inv_lines[] = '=== INVOICE FROM FRESHBOOKS (auto-lookup) ===';
            $inv_lines[] = '';
            $inv_lines[] = 'Document: ' . ucfirst( $lookup_data['type'] ?? 'invoice' ) . ' #' . ( $lookup_data['number'] ?? '' );
            if ( ! empty( $lookup_data['customer_name'] ) )  $inv_lines[] = 'Customer: ' . $lookup_data['customer_name'];
            $detail = $lookup_data['customer_detail'] ?? [];
            if ( ! empty( $detail['address'] ) )  $inv_lines[] = 'Address:  ' . $detail['address'];
            if ( ! empty( $detail['phone'] ) )    $inv_lines[] = 'Phone:    ' . $detail['phone'];
            if ( ! empty( $detail['email'] ) )    $inv_lines[] = 'Email:    ' . $detail['email'];
            if ( ! empty( $lookup_data['reference'] ) ) $inv_lines[] = 'Reference: ' . $lookup_data['reference'];
            if ( ! empty( $lookup_data['amount'] ) && $lookup_data['amount'] !== '0.00' ) {
                $inv_lines[] = 'Amount:   $' . $lookup_data['amount'];
            }
            $inv_lines[] = '';
            if ( ! empty( $lookup_data['lines'] ) ) {
                $inv_lines[] = '=== LINE ITEMS ===';
                foreach ( $lookup_data['lines'] as $ln ) {
                    $desc = is_array( $ln ) ? ( $ln['name'] ?? $ln['description'] ?? '' ) : (string) $ln;
                    $qty  = is_array( $ln ) ? ( $ln['qty'] ?? '1' ) : '1';
                    $cost = is_array( $ln ) ? ( $ln['unit_cost']['amount'] ?? $ln['amount']['amount'] ?? '' ) : '';
                    if ( $desc !== '' ) {
                        $inv_lines[] = '  • ' . $desc . ( $cost ? " — \${$cost} x {$qty}" : '' );
                    }
                }
            }
            $inv_text = implode( "\n", $inv_lines );

            $file_b64  = base64_encode( $inv_text );
            $file_mime = 'text/plain';
            $file_ext  = 'txt';
            $orig_name = 'Invoice-' . ( $lookup_data['number'] ?? 'unknown' ) . '.txt';
            $tmp_path  = null; // No physical file — skip hi-res upload

            if ( ! empty( $lookup_data['invoice_url'] ) && empty( $invoice_url ) ) {
                $invoice_url = esc_url_raw( $lookup_data['invoice_url'] );
            }
        }

        /* --- Build message text --- */
        // Rewrite photo URLs onto the configured image CDN so the receipt HTML uses
        // CDN-served images when a business fronts its uploads with one. The CDN host and
        // the set of "alternate" domains an upload URL might carry both come from
        // ZDZ_Business_Profile — NO production hostname is compiled in. The CDN base ships
        // EMPTY; with nothing configured this is a no-op and site-domain URLs are used.
        $cdn_base_url   = $this->cdn_base_url();
        $bot_photo_urls = $photo_urls;

        if ( $cdn_base_url ) {
            $site_url  = rtrim( home_url(), '/' );
            $alt_hosts = $this->photo_alt_origins( $site_url );
            $origins   = array_merge( array( $site_url ), $alt_hosts );
            $bot_photo_urls = array_map( function ( $url ) use ( $cdn_base_url, $origins ) {
                foreach ( $origins as $origin ) {
                    if ( $origin !== '' && strpos( $url, $origin ) === 0 ) {
                        return $cdn_base_url . substr( $url, strlen( $origin ) );
                    }
                }
                return $url;
            }, $photo_urls );
        }

        $mode_cfg = $this->mode_config( $mode );

        $msg = '';
        // Mode header — tells the bot what kind of receipt this is. Today this is
        // always the tagged install; future modes (before/after, per-unit) change
        // only this header + the photo framing, not the pipeline.
        $msg .= "Receipt type: {$mode_cfg['label']}\n";
        $msg .= "Document heading: {$mode_cfg['doc_heading']}\n";
        $msg .= "Installed: {$install_date}\n";
        // NOTE: the "Invoice link:" line is added LATER (after the share link is
        // resolved from FreshBooks), not here — see v3.3.13 fix below. Adding it
        // here used the OLD/empty $invoice_url, so the bot's button fell back to
        // "#" and never loaded for the customer.

        // v3.0.0: If we have lookup data, inject the customer block.
        // v3.2.0: also detect supplemental (non-screen) materials and inject the
        // Knowledge-Vault-backed materials block.
        $install_notes_arr = [];
        // v3.3.5 — AUTHORITATIVE LINE ITEMS, fetched server-side.
        // The client's lookup_data['lines'] is unreliable: when the lookup was
        // served by Prep's delegated search, the match objects carry NO line
        // items (Prep returns type/number/customer only). That left the prompt
        // with no quantities → "1 unit". So we re-fetch each source
        // invoice's full lines here, from FreshBooks directly (fb_find_document
        // uses include[]=lines), and use THOSE. Falls back to the client lines
        // only if the server fetch yields nothing (e.g. FreshBooks unreachable).
        $authoritative_lines = [];
        $primary_invoice_id  = 0; // first invoice's FreshBooks id — for the share link
        // v3.6.6 — capture the FreshBooks id of EVERY source invoice (keyed by
        // its number) so a combo receipt can resolve a customer share link for
        // each and link the receipt back onto all of them — not just the primary.
        $invoice_ids = []; // number(string) => fb invoice id(int)
        foreach ( $source_invoice_numbers as $inv_no ) {
            $found = $this->fb_find_document( $inv_no );
            if ( $found && ! empty( $found['doc'] ) ) {
                $this_id = (int) ( $found['doc']['invoiceid'] ?? $found['doc']['id'] ?? 0 );
                if ( $this_id > 0 ) { $invoice_ids[ (string) $inv_no ] = $this_id; }
                if ( ! $primary_invoice_id ) {
                    $primary_invoice_id = $this_id;
                }
                if ( ! empty( $found['doc']['lines'] ) && is_array( $found['doc']['lines'] ) ) {
                    foreach ( $found['doc']['lines'] as $ln ) {
                        $authoritative_lines[] = $ln;
                    }
                }
            }
        }
        if ( empty( $authoritative_lines ) && $has_lookup ) {
            $authoritative_lines = $lookup_data['lines'] ?? [];
        }

        // v3.3.9 — Belt-and-suspenders: if the loop above didn't resolve a
        // primary invoice id (e.g. source_invoice_numbers came through empty),
        // try once more from the single lookup number so the share-link block
        // below still runs. This is the same id fb_link_receipt resolves later,
        // surfaced earlier so the receipt HTML itself gets the customer link.
        if ( $primary_invoice_id === 0 && $has_lookup ) {
            $fallback_no = preg_replace( '/[^0-9]/', '', (string) ( $lookup_data['number'] ?? '' ) );
            if ( $fallback_no !== '' ) {
                $found = $this->fb_find_document( $fallback_no );
                if ( $found && ! empty( $found['doc'] ) ) {
                    $primary_invoice_id = (int) ( $found['doc']['invoiceid'] ?? $found['doc']['id'] ?? 0 );
                    if ( $primary_invoice_id > 0 ) { $invoice_ids[ (string) $fallback_no ] = $primary_invoice_id; }
                    if ( empty( $authoritative_lines ) && ! empty( $found['doc']['lines'] ) && is_array( $found['doc']['lines'] ) ) {
                        $authoritative_lines = $found['doc']['lines'];
                    }
                    if ( empty( $source_invoice_numbers ) ) {
                        $source_invoice_numbers = [ $fallback_no ];
                    }
                }
            }
        }

        // v3.3.11 — Compute the AUTHORITATIVE unit count here (from the
        // server-fetched FreshBooks lines) and rebuild the text "invoice" we send
        // the bot so the count is IMPOSSIBLE to miss. Why: the Poe bot's text
        // path (parse_invoice_from_text) regexes a count out of this text and, on
        // failure, asks Gemini to ESTIMATE it FROM PRICING — and our vent lines
        // are $0.00 (flat-rate job), so it guesses ~1. We pre-empt that by leading
        // the text with an explicit, machine-readable total in the exact phrasing
        // the bot already recognizes ("N total ... units") plus a tagged
        // line a bot-side fix can read directly.
        $auth_vent_count = $this->compute_vent_count( $authoritative_lines );
        if ( $auth_vent_count > 0 && $has_lookup ) {
            $file_b64  = base64_encode( $this->build_bot_invoice_text( $lookup_data, $authoritative_lines, $auth_vent_count ) );
            $file_mime = 'text/plain';
            $file_ext  = 'txt';
            if ( empty( $orig_name ) || substr( $orig_name, -4 ) !== '.txt' ) {
                $orig_name = 'Invoice-' . ( $lookup_data['number'] ?? 'lookup' ) . '.txt';
            }
        }

        // v3.3.7 — Resolve the CUSTOMER-FACING share link for the primary invoice
        // and use it as the invoice link on the receipt (overriding any internal
        // /#/invoice/ URL the client may have sent). This is the logged-out
        // homeowner URL the FreshBooks "Share via Link" produces.
        if ( $primary_invoice_id > 0 ) {
            $share = $this->fb_get_share_link( $primary_invoice_id );
            if ( $share !== '' ) {
                $invoice_url = $share;
            }
        }

        // v3.3.13 — THE FIX: add the "Invoice link:" line to the bot message HERE,
        // AFTER the share link is resolved — not up top where $invoice_url was
        // still empty. The bot reads this line for the receipt's "view invoice"
        // button; before this, the working /#/link/eyJ… URL never reached the bot
        // and the button fell back to "#" (dead). We pass the URL verbatim (NOT
        // through esc_url_raw, which strips the "#/link/" fragment that makes the
        // FreshBooks share URL work). We also send it under an explicit tag so a
        // bot that ignores "Invoice link:" can still pick it up unambiguously.
        if ( $invoice_url !== '' && strpos( $invoice_url, '#/invoice/' ) === false ) {
            // Only emit a customer-facing link when it's the share URL (or a
            // Zorderz/host URL) — never the internal staff /#/invoice/ view.
            $msg .= "Invoice link: {$invoice_url}\n";
            $msg .= "ZRCPT_INVOICE_URL: {$invoice_url}\n";
        }

        // v3.6.6 — COMBO JOBS: resolve a customer-facing share link for EVERY
        // source invoice and send them all as ZRCPT_INVOICE_URLS so the
        // receipt shows one "View invoice #N" button per invoice (the bot
        // template already renders this list). Before this, only the primary
        // invoice's link reached the bot, so a 2-invoice combo (e.g. Via Del
        // Cerro #15495 + #15516) showed just one FreshBooks link. We reuse the
        // primary's already-resolved $invoice_url to avoid a duplicate API call.
        if ( count( $source_invoice_numbers ) > 1 ) {
            $invoice_link_pairs = []; // "num=url"
            foreach ( $source_invoice_numbers as $inv_no ) {
                $num = (string) $inv_no;
                $iid = $invoice_ids[ $num ] ?? 0;
                $url = '';
                // Reuse the primary's resolved share link; fetch the rest.
                if ( $iid > 0 && $iid === $primary_invoice_id && $invoice_url !== '' ) {
                    $url = $invoice_url;
                } elseif ( $iid > 0 ) {
                    $url = $this->fb_get_share_link( $iid );
                }
                if ( $url !== '' && strpos( $url, '#/invoice/' ) === false ) {
                    $invoice_link_pairs[] = $num . '=' . $url;
                }
            }
            if ( count( $invoice_link_pairs ) > 1 ) {
                // Only emit the multi-link tag when we actually resolved 2+ links;
                // otherwise the single ZRCPT_INVOICE_URL above is correct.
                $msg .= "ZRCPT_INVOICE_URLS: " . implode( ' | ', $invoice_link_pairs ) . "\n";
            }
            error_log( sprintf(
                'ZRCPT COMBO LINKS: invoices=[%s] resolved_links=%d',
                implode( ',', $source_invoice_numbers ), count( $invoice_link_pairs )
            ) );
        }

        // v3.3.9 — CONTENT DIAGNOSTICS (pre-bot). One greppable line that proves
        // whether the data feeding the receipt is actually correct, instead of us
        // inferring from timestamps. If authoritative_lines is 0 here, the
        // server-side fetch loop above did NOT run (empty invoice numbers) or
        // FreshBooks returned no lines — that's the "1 unit" cause.
        error_log( sprintf(
            'ZRCPT GEN PRE: invoice_numbers=[%s] authoritative_lines=%d primary_invoice_id=%d has_lookup=%s invoice_url=%s',
            implode( ',', $source_invoice_numbers ),
            count( $authoritative_lines ),
            $primary_invoice_id,
            $has_lookup ? 'yes' : 'no',
            ( $invoice_url !== '' ? $invoice_url : '(none)' )
        ) );

        // v3.3.12 — Server-side Nutshell notes for material detection. A supplemental catalog item
        // 4" circular vents (and similar product detail) live in the NUTSHELL lead
        // notes, not the FreshBooks invoice lines. Previously install_notes came
        // ONLY from the client ($_POST), so if the front-end didn't pre-pull them,
        // the materials detector never saw the supplemental-item detail. Now: if the client
        // sent none, we fetch them here server-side (same as we do for line items)
        // — and we keep the FULL lead notes (not just install-day) so product
        // mentions anywhere in the lead are available to the detector.
        $all_lead_notes = [];   // full lead notes text, for material detection
        if ( $has_lookup ) {
            $install_notes_json = stripslashes( $_POST['install_notes'] ?? '' );
            $install_notes_arr  = $install_notes_json ? json_decode( $install_notes_json, true ) : [];
            if ( ! is_array( $install_notes_arr ) ) $install_notes_arr = [];

            // Server-side fetch when the client didn't supply notes, OR to enrich
            // material detection with the full note set regardless.
            if ( class_exists( 'ZRCPT_Nutshell' ) ) {
                try {
                    $ns = new ZRCPT_Nutshell();
                    if ( $ns->is_ready() ) {
                        $detail = $lookup_data['customer_detail'] ?? [];
                        $lead = $ns->find_lead_for_customer( [
                            'name'            => $lookup_data['customer_name'] ?? '',
                            'email'           => $detail['email'] ?? '',
                            'phone'           => $detail['phone'] ?? '',
                            'estimate_number' => $lookup_data['number'] ?? '',
                        ] );
                        if ( is_array( $lead ) ) {
                            // Full notes (any text) → material-detection haystack.
                            foreach ( (array) ( $lead['notes'] ?? [] ) as $ln ) {
                                $body = is_array( $ln ) ? ( $ln['content'] ?? $ln['note'] ?? '' ) : (string) $ln;
                                if ( $body !== '' ) { $all_lead_notes[] = $body; }
                            }
                            // If client sent no install notes, derive them now so
                            // the customer block still shows install-day context.
                            if ( empty( $install_notes_arr ) ) {
                                $derived = $ns->find_install_notes_for_lead( $lead );
                                if ( is_array( $derived ) ) { $install_notes_arr = $derived; }
                            }
                        }
                    }
                } catch ( \Throwable $e ) {
                    error_log( 'ZRCPT Nutshell server-side notes fetch failed: ' . $e->getMessage() );
                }
            }

            // v3.5.0 — FRESHBOOKS-ONLY FALLBACK. Plenty of jobs have a
            // FreshBooks invoice but NO matching Nutshell lead (online orders,
            // walk-ins, leads logged under a different name). Before this, the
            // customer block then simply had no install-notes section and the
            // bot lost the detail that was sitting in plain sight ON the
            // invoice ("(4) units. White Color.", a reference code,
            // "Tax and Installation Included."). Now, when Nutshell yielded
            // nothing, we read the invoice itself: its substantive line text +
            // reference + the computed unit count become the install details,
            // under an honest "read from the FreshBooks invoice" header.
            $notes_source = 'nutshell';
            if ( empty( $install_notes_arr ) ) {
                $derived_inv = $this->derive_install_notes_from_invoice( $lookup_data, $authoritative_lines );
                if ( ! empty( $derived_inv ) ) {
                    $install_notes_arr = $derived_inv;
                    $notes_source      = 'invoice';
                    error_log( 'ZRCPT notes: no Nutshell install notes — derived '
                        . count( $derived_inv ) . ' install detail line(s) from the FreshBooks document.' );
                }
            }

            $cust_block = $this->build_customer_block(
                $lookup_data,
                $authoritative_lines,
                $install_notes_arr,
                $notes_source
            );
            $msg .= "\n" . $cust_block . "\n";

            // v3.3.0 — Combined-invoice framing. When several invoices were
            // merged, the line items above already include all of them; tell the
            // bot to present ONE complete installation covering the full scope,
            // not separate per-invoice sections. The customer sees completed
            // work, not our billing logistics.
            if ( count( $source_invoice_numbers ) > 1 ) {
                $msg .= "\n=== Combined invoices ===\n";
                $msg .= "This receipt covers " . count( $source_invoice_numbers ) . " invoices for the same "
                      . "property (#" . implode( ', #', $source_invoice_numbers ) . "). The line items above "
                      . "are the COMBINED scope. Present a SINGLE, complete installation receipt covering all "
                      . "of this work together — do not split it into per-invoice sections or mention invoice "
                      . "numbers/billing logistics. Emphasize the totality of what was installed.\n";
            }
        }

        // v3.2.0 — Supplemental materials (extra catalog items detected via the Item Engine,
        // caulk) documented in the abbreviated code-approved style, sourced from
        // a knowledge source via the zrcpt_materials_context filter. Reads from the
        // invoice/estimate line items + the install notes. Returns '' (no-op) when
        // nothing relevant is detected or the vault is unavailable.
        // v3.3.5: detect from the SERVER-FETCHED lines (not the unreliable
        // client lines) so the terra-cotta/round vents are seen even on the
        // delegated-search path.
        // v3.3.12 — feed the FULL Nutshell lead notes into the detector's extra
        // text (in addition to install notes + invoice lines), so product detail
        // that only appears in a lead note — e.g. a named supplemental item —
        // is detected and its KV specs pulled. The reference/description fields
        // are appended as before.
        $supp_extra = trim(
            ( $has_lookup ? ( ( $lookup_data['reference'] ?? '' ) . ' ' . ( $lookup_data['description'] ?? '' ) . ' ' . ( $lookup_data['line_text'] ?? '' ) ) : '' )
            . ' ' . implode( ' ', $all_lead_notes )
        );
        $supp_block = $this->build_supplemental_materials_block(
            $authoritative_lines,
            $install_notes_arr,
            $supp_extra
        );
        if ( $supp_block !== '' ) {
            $msg .= $supp_block;
            error_log( 'ZRCPT materials: supplemental block ADDED (lead_notes=' . count( $all_lead_notes )
                . ', install_notes=' . count( $install_notes_arr ) . ').' );
        } else {
            error_log( 'ZRCPT materials: no supplemental block (nothing detected) — lead_notes='
                . count( $all_lead_notes ) . ', install_notes=' . count( $install_notes_arr ) . '.' );
        }

        // v3.1.0 — Photo provenance framing. When the photos came from the shared
        // library (the tech's own captures), tell the bot these are the verified
        // installation photos for THIS job, and how the set was chosen, so it
        // treats them as the after/install images. Future modes use the same
        // hook to describe before/after pairs or per-unit groupings.
        $from_library = ! empty( $library_ids );
        if ( $from_library ) {
            $msg .= "\n=== Installation photos (from the technician's own captures) ===\n";
            $msg .= "These " . count( $photo_urls ) . " photo(s) are the installation/after photos for this job, "
                  . "selected as the most recent capture set for this customer. Use them as the completed-work images.\n";
        } else {
            $msg .= "\n=== Installation photos (manually uploaded) ===\n";
        }

        $msg .= "\nPhotos:\n" . implode( "\n", $bot_photo_urls );

        /* --- Call Poe API --- */
        $response = $this->call_poe_bot( $msg, $file_b64, $file_mime, $orig_name, $file_ext );
        if ( is_wp_error( $response ) ) {
            $err = $response->get_error_message();
            // If the error mentions photo URLs, append the URLs we sent for debugging
            if ( stripos( $err, 'photo URL' ) !== false || stripos( $err, 'photo url' ) !== false ) {
                $err .= "\n\n[Debug] Photo URLs sent (" . count( $photo_urls ) . "): " . implode( ', ', $photo_urls );
            }
            wp_send_json_error( $err );
        }

        /* --- Parse bot response --- */
        $receipt = $this->parse_receipt_response( $response );
        if ( is_wp_error( $receipt ) ) wp_send_json_error( $receipt->get_error_message() );

        $html = base64_decode( $receipt['html_base64'] );
        if ( ! $html ) wp_send_json_error( 'Failed to decode receipt HTML.' );

        // v3.3.9 — AUTHORITATIVE COUNT OVERRIDE. The Poe bot sometimes writes
        // "This home has 1 unit" even when we hand it the correct total
        // in the prompt (the headline count lives in the bot's template and it
        // doesn't always substitute our number). We KNOW the right count from the
        // FreshBooks line items ($this->last_vent_count, computed in
        // build_customer_block). So we deterministically rewrite the headline in
        // the returned HTML and the vent_summary, rather than trusting the bot.
        // No-op when we have no authoritative count (e.g. manual-file path).
        // Prefer build_customer_block's total; fall back to the count we computed
        // from $authoritative_lines earlier (covers any path where the customer
        // block wasn't built but we still fetched lines).
        $authoritative_count = (int) $this->last_vent_count;
        if ( $authoritative_count <= 0 && ! empty( $auth_vent_count ) ) {
            $authoritative_count = (int) $auth_vent_count;
        }
        if ( $authoritative_count > 0 ) {
            $html = $this->force_vent_count_in_html( $html, $authoritative_count );
            $receipt['vent_summary'] = $this->force_vent_count_in_text(
                $receipt['vent_summary'] ?? '', $authoritative_count
            );
            // Re-encode so the stored _receipt_html matches the corrected HTML.
            $receipt['html_base64'] = base64_encode( $html );
        }

        /* --- Create / UPDATE WordPress page --- */
        $slug  = sanitize_title( $receipt['vanity_slug'] );
        $title = 'Installation Receipt — ' . $receipt['address_short'];

        // v3.3.6: regenerating the same invoice UPDATES its existing receipt in
        // place (same URL) instead of spawning a new post each time. Pass the
        // source invoice numbers so create_receipt_page can find the prior
        // receipt by invoice (one invoice → one receipt) rather than by the
        // bot's vanity slug, which varies between runs and caused a NEW page
        // (…-2, …-3) every regeneration.
        $page = $this->create_receipt_page( $slug, $html, $title, $receipt, $source_invoice_numbers );
        if ( is_wp_error( $page ) ) wp_send_json_error( $page->get_error_message() );

        $post_id = $page['post_id'];

        /* --- v3.8.0 — REDO housekeeping (truthful state after a regenerate) ---
         * A regenerate REPLACES the receipt content at the same post/URL. Any
         * prior approval was for the OLD content, and any prior "Sent" was the
         * OLD version reaching the customer. Leaving those flags in place made
         * History claim a redone receipt was already "Sent" — so nobody knew
         * the corrected version still needed approval + a re-send (the exact
         * confusion in the field on invoice #15558 / post 2343). Now, when an
         * UPDATE changes the content:
         *   • the approval is invalidated (same rule as photo remove/reorder;
         *     the send path already refuses stale approvals — this makes the
         *     UI say so up front instead of at send time);
         *   • _sent_at is archived to _prev_sent_at (shown as "Previously
         *     sent …") and the current-version send markers are cleared. The
         *     full _send_log audit history is untouched.
         */
        if ( ! empty( $page['updated'] ) ) {
            $new_hash      = hash( 'sha256', $html );
            $approved_hash = (string) get_post_meta( $post_id, '_approved_html_hash', true );
            $was_approved  = ( (string) get_post_meta( $post_id, '_approved_at', true ) !== '' );
            if ( $was_approved && ( $approved_hash === '' || ! hash_equals( $approved_hash, $new_hash ) ) ) {
                $this->invalidate_approval( $post_id );
                error_log( 'ZRCPT REDO: post_id=' . $post_id . ' — prior approval invalidated (content changed).' );
            }
            $sent_at = (string) get_post_meta( $post_id, '_sent_at', true );
            if ( $sent_at !== '' ) {
                update_post_meta( $post_id, '_prev_sent_at', $sent_at );
                delete_post_meta( $post_id, '_sent_at' );
                delete_post_meta( $post_id, '_sent_to' );
                delete_post_meta( $post_id, '_sent_by_user_id' );
                delete_post_meta( $post_id, '_sent_by_name' );
                error_log( 'ZRCPT REDO: post_id=' . $post_id . ' — previous send (' . $sent_at . ') archived; the redo needs approve + re-send.' );
            }
        }

        /* --- Associate photos with the receipt page ---
         * Uploaded photos (manual fallback) are re-parented to the receipt so
         * they're tidied under it. LIBRARY photos are NOT re-parented — they
         * belong to the tech's media library ("My Photos") and must stay there;
         * we only record which library media this receipt used, so the link is
         * traceable without moving anything. */
        foreach ( $photo_ids as $pid ) {
            wp_update_post( [ 'ID' => $pid, 'post_parent' => $post_id ] );
        }
        if ( ! empty( $library_ids ) ) {
            update_post_meta( $post_id, '_source_media_ids', array_values( array_unique( $library_ids ) ) );
        }

        // Record the mode this receipt was generated in (tagged today).
        update_post_meta( $post_id, '_receipt_mode', $mode );

        /* --- v3.6.0 — Persist the VERIFIED customer email for later sending ---
         * The Approve → Send flow happens later (in History), so we snapshot the
         * customer's contact here. SECURITY: the address we will later email is
         * re-fetched HERE, SERVER-SIDE, straight from FreshBooks (and Nutshell as
         * a fallback) keyed only by the customer/lead id — we deliberately do NOT
         * trust any email the browser sent in $lookup_data, because that blob is
         * client-controlled and could be tampered with to redirect the receipt to
         * an attacker's inbox. Only the customer_id (an integer we re-validate) is
         * taken as a hint; the email itself comes from the trusted source of
         * record. The stored email is NEVER returned to the browser: the person
         * approving doesn't see or edit it; the system uses it. */
        $verified_email = '';
        $verified_src   = 'none';
        $cust_id_hint   = 0;
        if ( is_array( $lookup_data ) ) {
            $cust_id_hint = (int) ( $lookup_data['customer_id'] ?? 0 );
        }

        // 1) Authoritative: re-fetch the client from FreshBooks by id.
        if ( $cust_id_hint > 0 && class_exists( 'ZRCPT_FreshBooks' ) ) {
            try {
                $fb_v = new ZRCPT_FreshBooks();
                if ( $fb_v->is_ready() ) {
                    $client_v = $fb_v->get_client( $cust_id_hint );
                    $fb_email = is_array( $client_v ) ? sanitize_email( (string) ( $client_v['email'] ?? '' ) ) : '';
                    if ( $fb_email !== '' && is_email( $fb_email ) ) {
                        $verified_email = $fb_email;
                        $verified_src   = 'freshbooks';
                    }
                }
            } catch ( \Throwable $e ) {
                error_log( 'ZRCPT: FreshBooks email re-fetch failed for client ' . $cust_id_hint . ': ' . $e->getMessage() );
            }
        }

        // 2) Fallback: the Nutshell lead for this customer (also server-side).
        if ( $verified_email === '' && class_exists( 'ZRCPT_Nutshell' ) ) {
            try {
                $ns_v = new ZRCPT_Nutshell();
                if ( method_exists( $ns_v, 'is_ready' ) && $ns_v->is_ready() && method_exists( $ns_v, 'find_lead_for_customer' ) ) {
                    // Name is only a search key; the email returned is from the CRM.
                    $name_key = is_array( $lookup_data ) ? (string) ( $lookup_data['customer_name'] ?? '' ) : '';
                    $lead_v = $ns_v->find_lead_for_customer( [ 'name' => $name_key ] );
                    $ns_email = '';
                    if ( is_array( $lead_v ) ) {
                        // Lead email may live under a few shapes depending on the client.
                        $ns_email = (string) ( $lead_v['email']
                            ?? ( is_array( $lead_v['emails'] ?? null ) ? ( $lead_v['emails'][0] ?? '' ) : '' )
                            ?? '' );
                    }
                    $ns_email = sanitize_email( $ns_email );
                    if ( $ns_email !== '' && is_email( $ns_email ) ) {
                        $verified_email = $ns_email;
                        $verified_src   = 'nutshell';
                    }
                }
            } catch ( \Throwable $e ) {
                error_log( 'ZRCPT: Nutshell email re-fetch failed: ' . $e->getMessage() );
            }
        }

        if ( $verified_email !== '' ) {
            update_post_meta( $post_id, '_customer_email', $verified_email );
            update_post_meta( $post_id, '_customer_email_source', $verified_src );
        } else {
            // No verified email from the source of record. Record that fact so the
            // Send step refuses cleanly — we never invent or prompt for an address.
            delete_post_meta( $post_id, '_customer_email' );
            update_post_meta( $post_id, '_customer_email_source', 'none' );
        }

        // Customer NAME is display-only (greeting); a sanitized client value is fine.
        if ( is_array( $lookup_data ) && ! empty( $lookup_data['customer_name'] ) ) {
            update_post_meta( $post_id, '_customer_name', sanitize_text_field( (string) $lookup_data['customer_name'] ) );
        }

        // v3.3.0 — Provenance: record EVERY source invoice this receipt was built
        // from, as its own meta row (so the cross-check '=' query finds merged
        // receipts too, and so a single invoice maps to its receipt for the
        // one-invoice-one-receipt rule). Stored as a LIST deliberately — a future
        // "split one invoice across multiple receipts" needs no schema change.
        if ( ! empty( $source_invoice_numbers ) ) {
            delete_post_meta( $post_id, '_fb_doc_numbers' );
            foreach ( $source_invoice_numbers as $num ) {
                add_post_meta( $post_id, '_fb_doc_numbers', $num, false );
            }
            // Also set the legacy single-value meta to the primary for back-compat
            // with anything that reads _fb_doc_number directly.
            update_post_meta( $post_id, '_fb_doc_number', $source_invoice_numbers[0] );
        }

        /* --- Upload hi-res invoice image --- */
        if ( $tmp_path ) {
        $hires = $this->make_hires_invoice( $tmp_path, $file_ext, $orig_name );
        if ( $hires && ! is_wp_error( $hires ) ) {
            $media_id = $this->upload_to_media_library( $hires['path'], $hires['name'], $post_id );
            if ( ! is_wp_error( $media_id ) ) {
                update_post_meta( $post_id, '_invoice_media_id', $media_id );
            }
        }
        }

        /* --- FreshBooks Integration (v2.8.0) --- */
        // v3.6.6 — pass ALL source invoice numbers so a combo receipt writes its
        // link back onto EVERY invoice it was built from (not just the primary).
        $fb_result = $this->fb_link_receipt(
            $post_id,
            $page['permalink'],
            $orig_name,
            $receipt,
            $source_invoice_numbers
        );

        // v3.3.9 — CONTENT DIAGNOSTICS (post-create). The single line that
        // answers "did this actually work?": which post was written, its final
        // URL (should be the clean /receipt/{address}/ canonical), whether it was
        // an update or a new post, the computed vent count (expect 63 for inv
        // 15424 — compare against the bot's "N units" headline), and the
        // resolved customer share link. Grep the log for `ZRCPT GEN SUMMARY`.
        error_log( sprintf(
            'ZRCPT GEN SUMMARY: post_id=%d updated=%s permalink=%s vent_count_computed=%d bot_vent_summary=%s share_link=%s fb_linked=%s',
            $post_id,
            ! empty( $page['updated'] ) ? 'yes' : 'no(new)',
            $page['permalink'],
            (int) $this->last_vent_count,
            ( $receipt['vent_summary'] ?? '(none)' ),
            ( $fb_result['share_link'] ?? get_post_meta( $post_id, '_fb_share_link', true ) ?: '(none)' ),
            $fb_result['linked'] ? 'yes' : ( 'no:' . ( $fb_result['error'] ?: 'unknown' ) )
        ) );

        wp_send_json_success( [
            'permalink'     => $page['permalink'],
            'post_id'       => $post_id,
            'address'       => $receipt['address_short'],
            'install_date'  => $receipt['install_date'],
            'vents'         => $receipt['vent_summary'] ?? '',
            'photo_count'   => count( $photo_urls ),
            'vanity_slug'   => $receipt['vanity_slug'],
            'fb_linked'     => $fb_result['linked'],
            'fb_doc_type'   => $fb_result['doc_type'],
            'fb_doc_number' => $fb_result['doc_number'],
            'fb_error'      => $fb_result['error'],
            // v3.8.0 — so the success view can say, honestly, whether this
            // REPLACED an existing receipt and that NOTHING has been emailed
            // yet (approve → send is always a separate, deliberate step).
            'updated'       => ! empty( $page['updated'] ),
            'state'         => $this->get_approval_state( $post_id ),
        ] );
    }

    /* =====================================================================
       POE API CLIENT
       ===================================================================== */

    private function call_poe_bot( $message_text, $file_b64, $file_mime, $filename, $file_ext ) {

        $opts     = self::get_options();
        $api_key  = $opts['api_key'];
        $bot_name = $this->receipt_bot_handle();

        if ( empty( $api_key ) ) return new \WP_Error( 'no_key', 'API key not configured.' );

        $content = [ [ 'type' => 'text', 'text' => $message_text ] ];

        $img_exts = [ 'jpg', 'jpeg', 'png', 'webp', 'heic', 'heif' ];
        if ( in_array( $file_ext, $img_exts, true ) ) {
            $content[] = [ 'type' => 'image_url', 'image_url' => [ 'url' => "data:{$file_mime};base64,{$file_b64}" ] ];
        } else {
            $content[] = [ 'type' => 'file', 'file' => [ 'filename' => $filename, 'file_data' => "data:{$file_mime};base64,{$file_b64}" ] ];
        }

        $body = wp_json_encode( [
            'model'    => $bot_name,
            'stream'   => false,
            'messages' => [ [ 'role' => 'user', 'content' => $content ] ],
        ] );

        if ( false === $body ) {
            return new \WP_Error( 'json', 'Failed to encode API request. The invoice file may be too large.' );
        }

        $payload_mb = round( strlen( $body ) / 1048576, 1 );

        // Retry up to 2 times on transient errors
        $max_attempts = 2;
        $last_error   = null;

        for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {

            $r = wp_remote_post( self::API_ENDPOINT, [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                    'HTTP-Referer'  => home_url(),
                    'X-Title'       => 'Zorderz Receipt',
                ],
                'body'                => $body,
                'timeout'             => self::API_TIMEOUT,
                'limit_response_size' => 5 * 1024 * 1024,
            ] );

            if ( is_wp_error( $r ) ) {
                $last_error = new \WP_Error( 'req', 'API request failed: ' . $r->get_error_message() );
                sleep( 2 );
                continue;
            }

            $code     = wp_remote_retrieve_response_code( $r );
            $res_body = wp_remote_retrieve_body( $r );

            if ( $code === 401 ) return new \WP_Error( 'auth', 'Invalid API key. Check Installation Receipts → Settings.' );
            if ( $code === 429 ) return new \WP_Error( 'rate', 'Rate limit hit. Wait a moment and retry.' );

            // Retry on 400/500 errors (transient API issues)
            if ( $code >= 400 ) {
                $last_error = new \WP_Error( 'http',
                    "API error (HTTP {$code}, attempt {$attempt}/{$max_attempts}, payload {$payload_mb} MB): "
                    . substr( $res_body, 0, 300 )
                );
                if ( $attempt < $max_attempts ) {
                    sleep( 3 );
                    continue;
                }
                return $last_error;
            }

            // Success
            $data = json_decode( $res_body, true );
            if ( empty( $data['choices'][0]['message']['content'] ) ) {
                return new \WP_Error( 'empty', "Empty response from API (HTTP {$code}, payload {$payload_mb} MB)." );
            }

            return $data['choices'][0]['message']['content'];
        }

        return $last_error ?: new \WP_Error( 'unknown', 'API call failed after retries.' );
    }

    /**
     * v3.0.0 — Text-only Poe API call for lookup-based receipts.
     */
    private function call_poe_bot_text_only( $message_text ) {
        $opts     = self::get_options();
        $api_key  = $opts['api_key'];
        $bot_name = $this->receipt_bot_handle();

        if ( empty( $api_key ) ) {
            return new \WP_Error( 'no_key', 'API key not configured.' );
        }

        $body = wp_json_encode( [
            'model'    => $bot_name,
            'stream'   => false,
            'messages' => [ [ 'role' => 'user', 'content' => $message_text ] ],
        ] );

        if ( false === $body ) {
            return new \WP_Error( 'json', 'Failed to encode API request.' );
        }

        $r = wp_remote_post( self::API_ENDPOINT, [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'HTTP-Referer'  => home_url(),
                'X-Title'       => 'Zorderz Receipt',
            ],
            'body'                => $body,
            'timeout'             => self::API_TIMEOUT,
            'limit_response_size' => 5 * 1024 * 1024,
        ] );

        if ( is_wp_error( $r ) ) {
            return new \WP_Error( 'req', 'API request failed: ' . $r->get_error_message() );
        }

        $code     = wp_remote_retrieve_response_code( $r );
        $res_body = wp_remote_retrieve_body( $r );

        if ( $code === 401 ) return new \WP_Error( 'auth', 'Invalid API key.' );
        if ( $code === 429 ) return new \WP_Error( 'rate', 'Rate limit hit. Wait a moment.' );
        if ( $code >= 400 ) {
            return new \WP_Error( 'http', "API error (HTTP {$code}): " . substr( $res_body, 0, 300 ) );
        }

        $data = json_decode( $res_body, true );
        if ( empty( $data['choices'][0]['message']['content'] ) ) {
            return new \WP_Error( 'empty', "Empty response from API (HTTP {$code})." );
        }

        return $data['choices'][0]['message']['content'];
    }

    /**
     * v3.3.11 — Sum the unit quantities from FreshBooks line items,
     * using the SAME rule as build_customer_block: count per-vent unit lines
     * (name/desc mentions "vent" or "screen"), excluding the flat "installation
     * of the primary product…" labor line, discounts, the location line, and the receipt-link
     * line. Returns the integer total (0 if none).
     */
    private function compute_vent_count( $lines ): int {
        if ( ! is_array( $lines ) ) { return 0; }
        $total = 0;
        foreach ( $lines as $ln ) {
            if ( ! is_array( $ln ) ) { continue; }
            $name  = trim( (string) ( $ln['name'] ?? '' ) );
            $desc  = trim( (string) ( $ln['description'] ?? '' ) );
            $qty   = isset( $ln['qty'] ) ? (string) $ln['qty'] : '';
            $label = $this->line_label( $name, $desc );
            if ( $label === '' ) { continue; }
            if ( ! $this->line_is_vent_unit( $label ) ) { continue; }
            // v3.5.0 — effective count: QTY × any count embedded in the line's
            // own text ("(4) units…" with QTY 1 counts as 4).
            $total += $this->line_effective_count( $name, $desc, $qty );
        }
        return $total;
    }

    /**
     * v3.5.0 — Shared line-item helpers. compute_vent_count() and
     * build_customer_block() previously each carried a copy of the label/
     * filter/count logic ("using the SAME rule as…" by convention only); they
     * now share these so the two can never drift again.
     */
    private function line_label( string $name, string $desc ): string {
        $label = $name !== '' ? $name : $desc;
        if ( $name !== '' && $desc !== '' && $desc !== $name ) {
            $label = $name . ' — ' . $desc;
        }
        return $label;
    }

    /**
     * Is this line a countable product/service UNIT (not a metadata / labor / adjustment line)?
     *
     * Generalized from the old 'vent|screen' substring test to the Item Engine COUNTS
     * CONTRACT: a line counts when it classifies to a countable kind (and, if a receipt item
     * tag is configured, to that tag or a child of it). Metadata / adjustment / flat-labor
     * lines are always excluded: discounts, refunds, fees, tips, the receipt-link line, the
     * location line, tax / "installation included" lines, and a flat "installation of …" labor
     * line. With an EMPTY catalog the classifier returns nothing, so we fall back to "any
     * non-metadata line is a unit" — the neutral behaviour that keeps a fresh install working
     * with NO taxonomy. NO product word is hardcoded.
     */
    private function line_is_countable_unit( string $label ): bool {
        $hay = strtolower( $label );
        // Never a unit: metadata / adjustment (ledger) / flat-labor lines. These mirror the
        // document-convention $0 metadata lines plus the ledger entry kinds and the labor line.
        $exclusions = (array) apply_filters( 'zrcpt_noncount_line_tokens', [
            'discount', 'refund', 'credit', 'fee', 'tip', 'gratuity',
            'receipt', 'location', 'tax', 'installation included',
            'installation of', 'install of', 'labor', 'labour',
        ] );
        foreach ( $exclusions as $tok ) {
            $tok = strtolower( (string) $tok );
            if ( $tok !== '' && strpos( $hay, $tok ) !== false ) {
                return false;
            }
        }
        // Catalog-driven: does this line classify to a countable kind (matching the tag if set)?
        if ( zrcpt_catalog_has_kinds() ) {
            $kind = zrcpt_count_classify( $label );
            if ( $kind === '' ) {
                return false;
            }
            $tag = zrcpt_receipt_item_tag();
            if ( $tag !== '' ) {
                return ( $kind === $tag || strpos( $kind, $tag ) === 0 );
            }
            return true;
        }
        // Neutral fallback (empty catalog): any non-metadata line is a countable unit.
        return $hay !== '';
    }

    /** @deprecated Legacy alias for line_is_countable_unit(); retained for BC. */
    private function line_is_vent_unit( string $label ): bool {
        return $this->line_is_countable_unit( $label );
    }

    /**
     * v3.5.0 — THE QTY-FIELD TRAP (invoice 15476): shop invoices often carry
     * the real unit count in the line TEXT — "(4) units. White Color."
     * — while the FreshBooks QTY field is 1 (one flat-priced bundle). Counting
     * QTY alone yields 1, and because our count is enforced as authoritative
     * all the way into force_vent_count_in_html(), the receipt then proudly
     * says "This home has 1 unit" for a 4-unit job.
     *
     * This parses a unit count out of the line's own text. Patterns, in
     * priority order (first hit wins; name checked before description):
     *   1. "(4) Gable Vents"        — parenthesized bare integer (the shop's
     *                                 own convention; size/percent/money
     *                                 parens like (AS), (50%), ($600), (14x6)
     *                                 never match — digits only).
     *   2. "qty 4" / "quantity: 4"  — explicit quantity wording in the text.
     *   3. "4 Gable Vents"          — leading bare count; NEVER a size: the
     *                                 next word must not be a unit (in/inch/
     *                                 ft/mm/…) or another number ("4 6x6…").
     *   4. "4x …" / "… x4"          — multiplier shorthand; dimensions like
     *                                 "14x6" are rejected (digit on the other
     *                                 side of the x).
     * Returns 0 when no convincing count is present.
     */
    private function parse_count_from_text( string $name, string $desc ): int {
        $texts = [ trim( $name ), trim( $desc ) ];

        // 1. Parenthesized bare integer.
        foreach ( $texts as $t ) {
            if ( $t !== '' && preg_match( '/\(\s*(\d{1,3})\s*\)/', $t, $m ) ) {
                return (int) $m[1];
            }
        }
        // 2. Explicit qty/quantity wording.
        foreach ( $texts as $t ) {
            if ( $t !== '' && preg_match( '/\bq(?:uantity|ty)\.?\s*[:#]?\s*(\d{1,3})\b/i', $t, $m ) ) {
                return (int) $m[1];
            }
        }
        // 3. Leading bare count ("4 Gable Vents") — next token must not be a
        //    unit word or another number.
        foreach ( $texts as $t ) {
            if ( $t !== '' && preg_match( '/^\s*(\d{1,3})\s+(\S+)/', $t, $m ) ) {
                $next = strtolower( trim( $m[2], '.,()"\'' ) );
                $units = [ 'in', 'inch', 'inches', 'ft', 'foot', 'feet', 'cm', 'mm', 'by', 'gauge', 'x' ];
                if ( $next !== ''
                    && ! in_array( $next, $units, true )
                    && ! preg_match( '/^\d/', $next )
                    && ! preg_match( '/^[x×]/iu', $next ) ) {
                    return (int) $m[1];
                }
            }
        }
        // 4. Multiplier shorthand ("4x …" / "… x4"); dimensions rejected.
        foreach ( $texts as $t ) {
            if ( $t === '' ) { continue; }
            if ( preg_match( '/\b(\d{1,3})\s*[x×](?!\s*\d)/iu', $t, $m ) ) {
                return (int) $m[1];
            }
            if ( preg_match( '/(?<![0-9x×])[x×]\s*(\d{1,3})\b/iu', $t, $m ) ) {
                return (int) $m[1];
            }
        }
        return 0;
    }

    /**
     * v3.5.0 — Effective unit count for one line: the parsed-from-text count
     * (when present) times the QTY field (when > 1; a QTY of 2 on a "(4) …"
     * pack line means two packs = 8 units). No text count → plain QTY.
     */
    private function line_effective_count( string $name, string $desc, $qty_raw ): int {
        $qty    = is_numeric( $qty_raw ) ? (int) $qty_raw : 0;
        $text_n = $this->parse_count_from_text( $name, $desc );
        if ( $text_n > 0 ) {
            return $qty > 1 ? $qty * $text_n : $text_n;
        }
        return max( 0, $qty );
    }

    /**
     * Build the text "invoice" we attach for the receipt-writer bot, leading with an explicit,
     * unambiguous total so the bot never has to guess the count from pricing. The authoritative
     * count is stated two ways: a tagged ZRCPT_COUNT_MARKER line a bot-side fix can read
     * directly, and a prose line using the item's OWN unit noun (COUNTS CONTRACT — never a
     * hardcoded product word). The unit noun comes from the Item Engine (or the neutral
     * fallback) so this text carries no product name.
     */
    private function build_bot_invoice_text( array $lookup_data, array $lines, int $vent_count ): string {
        $out = [];
        $out[] = '=== JOB DOCUMENT (auto-lookup from the billing provider) ===';
        $out[] = '';
        // The authoritative count, stated two ways the bot understands.
        $tag    = zrcpt_receipt_item_tag();
        $phrase = zrcpt_count_phrase( $tag, $vent_count ); // e.g. "63 screens" / "63 items"
        $out[] = ZRCPT_COUNT_MARKER . ': ' . $vent_count;
        $out[] = $phrase . ' completed for this job. This is the authoritative count from the '
               . 'billing line-item quantities — use THIS number, do not estimate from pricing.';
        $out[] = '';
        $out[] = 'Document: ' . ucfirst( (string) ( $lookup_data['type'] ?? 'invoice' ) ) . ' #' . ( $lookup_data['number'] ?? '' );
        if ( ! empty( $lookup_data['customer_name'] ) ) { $out[] = 'Customer: ' . $lookup_data['customer_name']; }
        $detail = $lookup_data['customer_detail'] ?? [];
        if ( ! empty( $detail['address'] ) ) { $out[] = 'Address: ' . $detail['address']; }
        if ( ! empty( $detail['phone'] ) )   { $out[] = 'Phone: ' . $detail['phone']; }
        if ( ! empty( $detail['email'] ) )   { $out[] = 'Email: ' . $detail['email']; }
        if ( ! empty( $lookup_data['reference'] ) ) { $out[] = 'Reference: ' . $lookup_data['reference']; }
        $out[] = '';
        $out[] = '=== LINE ITEMS ===';
        foreach ( $lines as $ln ) {
            if ( ! is_array( $ln ) ) {
                $s = trim( (string) $ln );
                if ( $s !== '' ) { $out[] = '  • ' . $s; }
                continue;
            }
            $name = trim( (string) ( $ln['name'] ?? '' ) );
            $desc = trim( (string) ( $ln['description'] ?? '' ) );
            $qty  = isset( $ln['qty'] ) ? (string) $ln['qty'] : '';
            $label = $this->line_label( $name, $desc );
            if ( $label === '' ) { continue; }
            $line = '  • ' . $label;
            // v3.5.0 — when the line text carries the real unit count
            // ("(4) units…", qty 1), state the effective count inline
            // so the bot never re-derives the wrong number from the bare qty.
            $eff = $this->line_effective_count( $name, $desc, $qty );
            if ( $qty !== '' && $qty !== '0' ) {
                if ( $eff > 0 && is_numeric( $qty ) && $eff !== (int) $qty ) {
                    $line .= '  (qty: ' . $qty . ' — the line text specifies ' . $eff . ' units; count it as ' . $eff . ')';
                } else {
                    $line .= '  (qty: ' . $qty . ')';
                }
            }
            $out[] = $line;
        }
        return implode( "\n", $out );
    }

    /**
     * The unit-noun regex fragment used to find the bot's count headline. Built from the
     * configured unit noun (Item Engine per-item noun, or the neutral fallback) — NO product
     * word is hardcoded. Matches both the plural and singular forms.
     */
    private function unit_noun_pattern(): string {
        $plural   = zrcpt_unit_noun( true );
        $singular = zrcpt_unit_noun( false );
        $stem     = preg_quote( rtrim( $plural, 's' ), '/' );
        $forms    = array_unique( array_filter( [ preg_quote( $plural, '/' ), preg_quote( $singular, '/' ), $stem . 's?' ] ) );
        return '(?:' . implode( '|', $forms ) . ')';
    }

    /**
     * Force the authoritative unit count into the bot's HTML headline.
     *
     * The bot's headline is some variation of "This home has N <units>" (the number is often
     * wrapped, e.g. "<strong>1</strong> screens"). We replace the number immediately preceding
     * the configured unit noun with the authoritative count from the billing line items,
     * targeting ONLY the "<number> <unit-noun>" pattern so we never touch counts that mean
     * something else (photos, certifications, etc.). Idempotent and safe: no match → unchanged.
     * The unit noun is catalog-driven, so no product word is compiled in.
     *
     * @param string $html  Decoded receipt HTML.
     * @param int    $count Authoritative unit count.
     */
    private function force_unit_count_in_html( string $html, int $count ): string {
        if ( $count <= 0 || $html === '' ) { return $html; }

        $noun    = $this->unit_noun_pattern();
        $pattern = '/(<(?:strong|b|span)[^>]*>\s*)?\b\d[\d,]*(\s*<\/(?:strong|b|span)>)?(\s*' . $noun . ')/i';

        $replaced = preg_replace_callback(
            $pattern,
            function ( $m ) use ( $count ) {
                $open  = $m[1] ?? '';
                $close = $m[2] ?? '';
                $tail  = $m[3] ?? '';
                return $open . $count . $close . $tail;
            },
            $html,
            -1,
            $n
        );

        if ( $replaced === null ) { return $html; } // regex failure → keep original
        if ( $n === 0 ) {
            error_log( 'ZRCPT force_unit_count: no "N <unit>" headline found to correct (wanted ' . $count . ').' );
            return $html;
        }
        error_log( 'ZRCPT force_unit_count: corrected ' . $n . ' headline occurrence(s) to ' . $count . '.' );
        return $replaced;
    }

    /** Same correction for a plain-text summary like "1 screens (…)". */
    private function force_unit_count_in_text( string $text, int $count ): string {
        if ( $count <= 0 || $text === '' ) { return $text; }
        $out = preg_replace( '/\b\d[\d,]*(\s*' . $this->unit_noun_pattern() . ')/i', $count . '$1', $text, 1, $n );
        return ( $out !== null && $n > 0 ) ? $out : $text;
    }

    /** @deprecated Legacy aliases retained for BC. */
    private function force_vent_count_in_html( string $html, int $count ): string { return $this->force_unit_count_in_html( $html, $count ); }
    private function force_vent_count_in_text( string $text, int $count ): string { return $this->force_unit_count_in_text( $text, $count ); }

    private function parse_receipt_response( $content ) {
        // Accept the neutral in-repo marker AND any legacy markers a tenant supplies via
        // filter (ships EMPTY — no product-named marker literal in the public module), so a
        // receipt written by either a Zorderz-templated bot or a business's existing bot parses.
        $markers = array_merge( [ 'ZRCPT_RECEIPT_JSON' ], (array) apply_filters( 'zrcpt_legacy_receipt_markers', [] ) );
        $open = $close = ''; $s = false; $e = false;
        foreach ( $markers as $mk ) {
            $s = strpos( $content, '<!--' . $mk );
            $e = strpos( $content, $mk . '-->' );
            if ( false !== $s && false !== $e ) {
                $open = '<!--' . $mk;
                break;
            }
        }
        if ( false === $s || false === $e || $open === '' ) {
            return new \WP_Error( 'parse', 'Could not find receipt data in bot response.' );
        }
        $json_str = trim( substr( $content, $s + strlen( $open ), $e - $s - strlen( $open ) ) );
        $data = json_decode( $json_str, true );
        if ( ! $data ) return new \WP_Error( 'json', 'Failed to parse receipt JSON.' );
        foreach ( [ 'address_short', 'vanity_slug', 'install_date', 'html_base64' ] as $f ) {
            if ( empty( $data[ $f ] ) ) return new \WP_Error( 'field', "Missing field: {$f}" );
        }
        return $data;
    }

    /* =====================================================================
       FILE PROCESSING
       ===================================================================== */

    private function make_hires_invoice( $path, $ext, $orig_name ) {
        if ( $ext === 'pdf' && class_exists( 'Imagick' ) ) {
            try {
                $im = new \Imagick();
                $im->setResolution( 300, 300 );
                $im->readImage( $path . '[0]' );
                $im->setImageFormat( 'png' );
                $out = $path . '-hires.png';
                $im->writeImage( $out );
                $im->destroy();
                return [ 'path' => $out, 'name' => pathinfo( $orig_name, PATHINFO_FILENAME ) . '-hires.png' ];
            } catch ( \Exception $e ) { return null; }
        }
        if ( in_array( $ext, [ 'heic', 'heif' ], true ) && class_exists( 'Imagick' ) ) {
            try {
                $im = new \Imagick();
                $im->readImage( $path );
                $im->autoOrient();  // Fix rotation
                $im->setImageFormat( 'jpeg' );
                $im->setImageCompressionQuality( 95 );
                $out = $path . '-hires.jpg';
                $im->writeImage( $out );
                $im->destroy();
                return [ 'path' => $out, 'name' => pathinfo( $orig_name, PATHINFO_FILENAME ) . '-hires.jpg' ];
            } catch ( \Exception $e ) { return null; }
        }
        return [ 'path' => $path, 'name' => $orig_name ];
    }

    /* =====================================================================
       WORDPRESS INTEGRATION
       ===================================================================== */

    private function create_receipt_page( $slug, $html, $title, $meta, $invoice_numbers = [] ) {
        // v3.3.6 — Find the existing receipt to UPDATE, preferring the INVOICE
        // match (one invoice → one receipt) so a regenerate refreshes the SAME
        // page/URL. The bot's vanity slug varies run-to-run, so matching by slug
        // alone spawned a new post (…-2, …-3) every time — the stale-URL problem.
        $existing_id  = 0;
        $existing_post = null;

        // 1) By source invoice number (authoritative).
        foreach ( (array) $invoice_numbers as $inv_no ) {
            $hit = $this->receipt_for_invoice( $inv_no );
            if ( $hit && ! empty( $hit['post_id'] ) ) {
                $existing_id = (int) $hit['post_id'];
                break;
            }
        }
        // 2) v3.3.9 — By CANONICAL ADDRESS (the real fix for "the page never
        //    updates"). The very first receipt for an address was made by old
        //    code that never stored _fb_doc_number(s), so step (1) can't see it
        //    and every regenerate spawns a NEW post at a suffixed slug while the
        //    canonical /receipt/{address}/ URL stays frozen on that first post.
        //    Here we find the receipt at the clean, UNSUFFIXED address slug (and,
        //    as a secondary signal, any receipt whose _address_short matches),
        //    preferring the OLDEST such post — that's the canonical page the
        //    customer's link points at. We then update THAT post in place.
        $address_short = (string) ( $meta['address_short'] ?? '' );
        $canonical_slug = $this->canonical_address_slug( $address_short, $slug );
        if ( ! $existing_id && $canonical_slug !== '' ) {
            $existing_id = $this->find_receipt_by_address( $canonical_slug, $address_short );
        }

        // 3) Fallback: by the bot's vanity slug (legacy; varies run-to-run).
        if ( ! $existing_id ) {
            $by_slug = get_posts( [
                'post_type'      => self::POST_TYPE,
                'name'           => $slug,
                'posts_per_page' => 1,
                'post_status'    => 'any',
            ] );
            if ( ! empty( $by_slug ) ) { $existing_id = (int) $by_slug[0]->ID; }
        }

        if ( $existing_id ) {
            $existing_post = get_post( $existing_id );
        }

        $post_data = [
            'post_title'   => $title,
            'post_content' => '',
            'post_status'  => 'publish',
            'post_type'    => self::POST_TYPE,
        ];

        if ( $existing_id && $existing_post ) {
            // UPDATE in place. v3.3.9 — RECLAIM the clean canonical address slug
            // when possible so regenerations converge on /receipt/{address}/
            // instead of being stuck on whatever (possibly suffixed) slug this
            // post happens to have. We only rename to $canonical_slug if that slug
            // is free or already owned by THIS post; otherwise we keep the
            // existing slug (never steal another post's URL). If no canonical slug
            // could be derived, keep the existing slug exactly as before.
            $post_data['ID'] = $existing_id;
            $target_slug = $existing_post->post_name;
            if ( $canonical_slug !== '' && $this->slug_is_free_or_owned( $canonical_slug, $existing_id ) ) {
                $target_slug = $canonical_slug;
            }
            $post_data['post_name'] = $target_slug;
            $post_id = wp_update_post( $post_data, true );
        } else {
            // No existing receipt → create one at the clean canonical address slug
            // (falls back to the bot's vanity slug only if we couldn't derive one).
            $post_data['post_name'] = ( $canonical_slug !== '' ) ? $canonical_slug : $slug;
            $post_id = wp_insert_post( $post_data, true );
        }
        if ( is_wp_error( $post_id ) ) return $post_id;

        update_post_meta( $post_id, '_receipt_html', $html );
        // v3.6.9 (H3): stamp the creator ONCE so ownership survives regenerates
        // (create_receipt_page reuses the canonical post for the same job).
        if ( ! get_post_meta( $post_id, '_created_by', true ) ) {
            update_post_meta( $post_id, '_created_by', get_current_user_id() );
        }
        self::ensure_share_token( $post_id ); // v3.6.9 (C1): every new receipt gets a word-link
        update_post_meta( $post_id, '_address_short', $meta['address_short'] ?? '' );
        update_post_meta( $post_id, '_install_date', $meta['install_date'] ?? '' );
        update_post_meta( $post_id, '_vanity_slug', $meta['vanity_slug'] ?? '' );

        // v3.3.9 — Backfill invoice-number meta onto the (possibly old) canonical
        // post so the next regenerate matches it by invoice in step (1) and the
        // dedupe stays stable even if the address changes spelling later.
        if ( ! empty( $invoice_numbers ) ) {
            $have = (array) get_post_meta( $post_id, '_fb_doc_numbers', false );
            foreach ( (array) $invoice_numbers as $inv_no ) {
                $d = preg_replace( '/[^0-9]/', '', (string) $inv_no );
                if ( $d !== '' && ! in_array( $d, $have, true ) ) {
                    add_post_meta( $post_id, '_fb_doc_numbers', $d, false );
                }
            }
        }

        return [ 'post_id' => $post_id, 'permalink' => get_permalink( $post_id ), 'updated' => (bool) $existing_id ];
    }

    /**
     * v3.3.9 — Build the clean, canonical address slug for a receipt, e.g.
     * "123 Example St." → "123-example-st" (plus zip if the vanity slug
     * carried one). We anchor on the bot's vanity slug when it already looks like
     * an address slug (it usually encodes the same address + zip), falling back
     * to sanitizing the human address. Returns '' if nothing usable.
     */
    private function canonical_address_slug( string $address_short, string $vanity_slug ): string {
        // The vanity slug from the bot is typically the address already (e.g.
        // "123-example-st-90210"); strip any numeric dedupe suffix WordPress
        // may have appended on a prior insert (…-2, …-3) — but DON'T strip a zip.
        $vs = sanitize_title( $vanity_slug );
        // Remove a trailing "-<n>" only when it's a small dedupe counter (1–2
        // digits) AND the slug has other parts before it (so we never nuke a zip).
        $vs = preg_replace( '/-(?:[1-9]|[1-9][0-9])$/', '', $vs );
        if ( $vs !== '' ) {
            return $vs;
        }
        return sanitize_title( $address_short );
    }

    /**
     * v3.3.9 — Find the canonical receipt post for an address: the OLDEST receipt
     * whose post_name is the canonical slug (or starts with it + a dedupe
     * suffix), or whose _address_short meta matches. Returns the post id or 0.
     * Preferring the oldest means we converge on the very first receipt — the URL
     * the customer's FreshBooks link and any bookmarks already point at.
     */
    private function find_receipt_by_address( string $canonical_slug, string $address_short ): int {
        if ( $canonical_slug === '' ) { return 0; }

        // 1) Exact canonical slug, or canonical slug + WP dedupe suffix.
        $candidates = get_posts( [
            'post_type'      => self::POST_TYPE,
            'post_status'    => [ 'publish', 'private', 'draft' ],
            'posts_per_page' => 50,
            'orderby'        => 'ID',
            'order'          => 'ASC', // oldest first → the canonical page
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ] );
        $slug_re = '/^' . preg_quote( $canonical_slug, '/' ) . '(?:-[0-9]+)?$/';
        foreach ( $candidates as $pid ) {
            $p = get_post( $pid );
            if ( $p && preg_match( $slug_re, $p->post_name ) ) {
                return (int) $pid;
            }
        }

        // 2) Secondary: match by _address_short meta (oldest first).
        if ( $address_short !== '' ) {
            $by_addr = get_posts( [
                'post_type'      => self::POST_TYPE,
                'post_status'    => [ 'publish', 'private', 'draft' ],
                'posts_per_page' => 1,
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_query'     => [
                    [ 'key' => '_address_short', 'value' => $address_short, 'compare' => '=' ],
                ],
            ] );
            if ( ! empty( $by_addr ) ) { return (int) $by_addr[0]; }
        }

        return 0;
    }

    /**
     * v3.3.9 — True if $slug is unused, or is already used only by $owner_id.
     * Prevents stealing another receipt's URL when reclaiming the canonical slug.
     */
    private function slug_is_free_or_owned( string $slug, int $owner_id ): bool {
        $holders = get_posts( [
            'post_type'      => self::POST_TYPE,
            'name'           => $slug,
            'post_status'    => [ 'publish', 'private', 'draft' ],
            'posts_per_page' => 2,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ] );
        if ( empty( $holders ) ) { return true; }
        if ( count( $holders ) === 1 && (int) $holders[0] === $owner_id ) { return true; }
        return false;
    }

    private function upload_to_media_library( $file_path, $filename, $parent = 0 ) {
        if ( ! function_exists( 'media_handle_sideload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $tmp = wp_tempnam( $filename );
        copy( $file_path, $tmp );
        $att_id = media_handle_sideload( [ 'name' => $filename, 'tmp_name' => $tmp ], $parent );
        if ( is_wp_error( $att_id ) ) @unlink( $tmp );
        return $att_id;
    }

    /* =====================================================================
       HELPERS
       ===================================================================== */

    private static function defaults() {
        return [ 'api_key' => '', 'bot_name' => '', 'cdn_base_url' => '' ];
    }

    public static function get_options() {
        return wp_parse_args( get_option( self::OPTION_KEY, [] ), self::defaults() );
    }

    /* =====================================================================
       RECEIPT MODE
       The default mode ('tagged') is bound to an admin-chosen Item Engine
       tag/subtype — no product name is compiled in. A request may pass `mode`;
       we validate it against the known set, migrate the legacy product-named
       value, and fall back to the tagged mode.
       ===================================================================== */

    public function receipt_mode( $requested = '' ) {
        $requested = is_string( $requested ) ? trim( $requested ) : '';
        // Any legacy product-named value is unknown here and falls through to the
        // tagged default below, so no product word is compiled in for the migration.
        $known = [ self::MODE_TAGGED, self::MODE_GENERAL, self::MODE_PROPERTY_MGMT ];
        if ( in_array( $requested, $known, true ) ) {
            // The tagged mode is always allowed; the richer layouts stay dormant behind a
            // filter until a future release enables them.
            if ( $requested === self::MODE_TAGGED ) return $requested;
            $enabled = apply_filters( 'zrcpt_enabled_modes', [ self::MODE_TAGGED ] );
            return in_array( $requested, (array) $enabled, true ) ? $requested : self::MODE_TAGGED;
        }
        return self::MODE_TAGGED;
    }

    /**
     * Per-mode configuration consumed by the lookup filter, the photo grouping, and the AI
     * prompt/rendering. Keeping these declarative means "add a mode" is "add an entry here".
     *
     * The tagged mode's label/heading/filter come from the admin-chosen Item Engine tag; an
     * EMPTY tag yields a neutral "Completed Job" with NO item filter, so a fresh install works
     * without any catalog. NO product name is hardcoded.
     */
    public function mode_config( $mode ) {
        $mode = $this->receipt_mode( $mode );

        // Resolve a human label for the configured item tag from the Item Engine, if any.
        $tag       = zrcpt_receipt_item_tag();
        $tag_label = '';
        if ( $tag !== '' && class_exists( 'ZDZ_Item_Engine' ) && method_exists( 'ZDZ_Item_Engine', 'get' ) ) {
            $item = ZDZ_Item_Engine::get( $tag );
            if ( is_array( $item ) ) {
                $tag_label = (string) ( $item['display_name'] ?? $item['subtype'] ?? '' );
            }
        }
        $tagged_label   = $tag_label !== '' ? ( $tag_label . ' Job' ) : __( 'Completed Job', 'zorderz' );
        $tagged_heading = $tag_label !== '' ? ( $tag_label . ' Receipt' ) : __( 'Completed Work', 'zorderz' );

        $configs = [
            self::MODE_TAGGED => [
                'label'        => $tagged_label,
                'tag_filter'   => $tag,   // item tag/subtype the billing lookup is restricted to ('' = none)
                'photo_layout' => 'install_set', // newest session = the completed work
                'doc_heading'  => $tagged_heading,
            ],
            self::MODE_GENERAL => [
                'label'        => __( 'General Job (Before / After)', 'zorderz' ),
                'tag_filter'   => '',
                'photo_layout' => 'before_after', // pair the two newest sessions
                'doc_heading'  => __( 'Completed Work', 'zorderz' ),
            ],
            self::MODE_PROPERTY_MGMT => [
                'label'        => __( 'Property Management (Per-Unit)', 'zorderz' ),
                'tag_filter'   => '',
                'photo_layout' => 'per_unit', // group sessions/photos by unit
                'doc_heading'  => __( 'Property Service Report', 'zorderz' ),
            ],
        ];
        return $configs[ $mode ] ?? $configs[ self::MODE_TAGGED ];
    }

    /* =====================================================================
       IMAGE CDN (crosswalk 03-B31) — base + origins from ZDZ_Business_Profile.
       NO production hostname is compiled in. The CDN base ships EMPTY (a no-op).
       ===================================================================== */

    /** The configured image-CDN base URL, or '' when none. Business Profile → option fallback. */
    private function cdn_base_url(): string {
        $cdn = '';
        if ( class_exists( 'ZDZ_Business_Profile' ) ) {
            $cdn = (string) ZDZ_Business_Profile::get( 'web.asset_cdn_host', '' );
        }
        if ( $cdn === '' ) {
            $opts = self::get_options();
            $cdn  = (string) ( $opts['cdn_base_url'] ?? '' );
        }
        $cdn = trim( $cdn );
        if ( $cdn !== '' ) {
            $cdn = rtrim( $cdn, '/' );
            if ( strpos( $cdn, '://' ) === false ) {
                $cdn = 'https://' . $cdn;
            }
        }
        return (string) apply_filters( 'zrcpt_cdn_base_url', $cdn );
    }

    /**
     * Alternate origins an upload URL might legitimately carry besides home_url() — the
     * business's declared app/marketing domains from ZDZ_Business_Profile. Used only to
     * rewrite those onto the CDN base; NO hostname is compiled in. Returns absolute origins.
     *
     * @param string $site_url the current home_url() (already slash-trimmed)
     * @return string[]
     */
    private function photo_alt_origins( string $site_url ): array {
        $out = [];
        if ( class_exists( 'ZDZ_Business_Profile' ) ) {
            foreach ( [ 'web.app_domain', 'web.marketing_domain' ] as $path ) {
                $host = (string) ZDZ_Business_Profile::get( $path, '' );
                if ( $host === '' ) {
                    continue;
                }
                if ( strpos( $host, '://' ) === false ) {
                    $host = 'https://' . $host;
                }
                $host = rtrim( $host, '/' );
                if ( $host !== '' && $host !== $site_url && ! in_array( $host, $out, true ) ) {
                    $out[] = $host;
                }
            }
        }
        return (array) apply_filters( 'zrcpt_photo_alt_origins', $out, $site_url );
    }

    /* =====================================================================
       FRESHBOOKS INVOICE INTEGRATION (v2.8.0)
       Uses the platform shared billing credentials (ZDZ_Core_Settings), with a read-only legacy fallback
       from the theme Core settings.
       After a receipt page is created, finds the matching FreshBooks
       invoice/estimate and appends an "Installation Receipt - Link"
       line item with $0.00 cost pointing to the receipt URL.
       ===================================================================== */

    /**
     * Legacy option prefixes (read-only fallback) for billing credentials.
     * Order: Core first, then legacy prefixes (read-only fallback).
     */
    const FB_PREFIXES = [ 'tsec_', 'tsl_', 'tsa_', 'ts_surveys_', 'ts_core_' ];

    /**
     * Get a billing option from Core, with a read-only legacy prefix fallback.
     */
    private function fb_get_shared_option( $key ) {
        foreach ( self::FB_PREFIXES as $prefix ) {
            $val = get_option( $prefix . $key, '' );
            if ( ! empty( $val ) ) return $val;
        }
        return '';
    }

    /**
     * Decrypt AES-256-CBC encrypted values (matches the legacy AES-256-CBC option pattern).
     */
    private function fb_decrypt( $value ) {
        if ( empty( $value ) ) return '';
        $decoded = base64_decode( $value );
        if ( strpos( $decoded, '::' ) === false ) return $value; // Not encrypted (plaintext)
        list( $iv, $cipher ) = explode( '::', $decoded, 2 );
        $key = substr( hash( 'sha256', wp_salt( 'auth' ) ), 0, 32 );
        $dec = openssl_decrypt( $cipher, 'AES-256-CBC', $key, 0, $iv );
        return $dec !== false ? $dec : '';
    }

    /* --- FreshBooks credential getters --- */

    private function fb_get_access_token()  { return $this->fb_get_shared_option( 'fb_access_token' ); }
    private function fb_get_refresh_token() { return $this->fb_get_shared_option( 'fb_refresh_token' ); }
    private function fb_get_account_id()    { return $this->fb_get_shared_option( 'fb_account_id' ); }
    private function fb_get_client_id()     { return $this->fb_get_shared_option( 'fb_client_id' ); }

    private function fb_get_client_secret() {
        foreach ( self::FB_PREFIXES as $prefix ) {
            $raw = get_option( $prefix . 'fb_client_secret', '' );
            if ( empty( $raw ) ) continue;
            $dec = $this->fb_decrypt( $raw );
            if ( ! empty( $dec ) ) return $dec;
            return $raw; // May be stored as plaintext by older version
        }
        return '';
    }

    /**
     * Check if FreshBooks credentials are available from any sibling plugin.
     */
    private function fb_has_credentials() {
        return ! empty( $this->fb_get_access_token() ) && ! empty( $this->fb_get_account_id() );
    }

    /* --- Token refresh (syncs back to all sibling prefixes) --- */

    private function fb_refresh_access_token() {
        $client_id     = $this->fb_get_client_id();
        $client_secret = $this->fb_get_client_secret();
        $refresh_token = $this->fb_get_refresh_token();

        if ( empty( $refresh_token ) || empty( $client_id ) ) return false;

        $resp = wp_remote_post( 'https://api.freshbooks.com/auth/oauth/token', [
            'timeout' => 30,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'grant_type'    => 'refresh_token',
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'refresh_token' => $refresh_token,
            ] ),
        ] );

        if ( is_wp_error( $resp ) ) return false;

        $data = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( empty( $data['access_token'] ) ) return false;

        $new_access  = $data['access_token'];
        $new_refresh = $data['refresh_token'] ?? $refresh_token;

        // Sync to all known sibling prefixes whose Client ID matches (or is empty)
        foreach ( self::FB_PREFIXES as $prefix ) {
            $sibling_id = get_option( $prefix . 'fb_client_id', '' );
            if ( empty( $sibling_id ) || $sibling_id === $client_id ) {
                update_option( $prefix . 'fb_access_token',  $new_access );
                update_option( $prefix . 'fb_refresh_token', $new_refresh );
            }
        }

        return $new_access;
    }

    /* --- HTTP helpers (with auto token refresh on 401) --- */

    private function fb_request( $method, $url, $data = null ) {
        $access_token = $this->fb_get_access_token();
        if ( empty( $access_token ) ) {
            error_log( 'ZRCPT FreshBooks: No access token available.' );
            return null;
        }

        $args = [
            'method'  => $method,
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
                'Api-Version'   => 'alpha',
            ],
        ];
        if ( $data ) {
            $args['body'] = wp_json_encode( $data );
        }

        $resp = wp_remote_request( $url, $args );
        if ( is_wp_error( $resp ) ) {
            error_log( 'ZRCPT FreshBooks HTTP error: ' . $resp->get_error_message() );
            return null;
        }

        $code = wp_remote_retrieve_response_code( $resp );

        // Auto-refresh on 401
        if ( $code === 401 ) {
            $new_token = $this->fb_refresh_access_token();
            if ( $new_token ) {
                $args['headers']['Authorization'] = 'Bearer ' . $new_token;
                $resp = wp_remote_request( $url, $args );
                if ( is_wp_error( $resp ) ) return null;
                $code = wp_remote_retrieve_response_code( $resp );
            } else {
                error_log( 'ZRCPT FreshBooks: Token expired and refresh failed.' );
                return null;
            }
        }

        if ( $code >= 400 ) {
            error_log( "ZRCPT FreshBooks {$method} {$url} → HTTP {$code}: " . substr( wp_remote_retrieve_body( $resp ), 0, 300 ) );
            return null;
        }

        return json_decode( wp_remote_retrieve_body( $resp ), true );
    }

    /* --- Invoice / Estimate search --- */

    /**
     * Search FreshBooks for an invoice or estimate by document number.
     *
     * Tries invoices first (most common for completed installations),
     * then falls back to estimates.
     *
     * @param string $number  The invoice/estimate number to search for.
     * @return array|null  [ 'type' => 'invoice'|'estimate', 'doc' => ... ] or null.
     */
    private function fb_find_document( $number ) {
        $account_id = $this->fb_get_account_id();
        if ( empty( $account_id ) || empty( $number ) ) return null;

        $base = "https://api.freshbooks.com/accounting/account/{$account_id}";

        // Try invoice first. include[]=direct_links carries the customer share
        // token (#/link/{token}) — the link a logged-out homeowner can open.
        $url  = $base . '/invoices/invoices?search[invoice_number]=' . rawurlencode( $number ) . '&include[]=lines&include[]=direct_links';
        $resp = $this->fb_request( 'GET', $url );
        $invoices = $resp['response']['result']['invoices'] ?? [];
        if ( ! empty( $invoices ) ) {
            return [ 'type' => 'invoice', 'doc' => $invoices[0] ];
        }

        // Fallback: try estimate
        $url  = $base . '/estimates/estimates?search[estimate_number]=' . rawurlencode( $number ) . '&include[]=lines';
        $resp = $this->fb_request( 'GET', $url );
        $estimates = $resp['response']['result']['estimates'] ?? [];
        if ( ! empty( $estimates ) ) {
            return [ 'type' => 'estimate', 'doc' => $estimates[0] ];
        }

        return null;
    }

    /**
     * Build the customer-facing FreshBooks share URL from a doc's direct_links.
     * `https://my.freshbooks.com/#/link/{token}` opens for a logged-out viewer
     * (the homeowner) — unlike `#/invoice/{id}`, which is the internal view.
     * Returns '' when no share token is present.
     */
    /**
     * Get the CUSTOMER-FACING "Share via Link" URL for an invoice.
     *
     * This is the link that opens for a logged-OUT viewer (the homeowner) —
     * https://my.freshbooks.com/#/link/{token}. It is generated/returned by a
     * dedicated (undocumented) accounting endpoint that the FreshBooks web UI's
     * "More Actions → Share via Link" uses:
     *
     *   GET /accounting/account/{acct}/invoices/invoices/{id}/share_link?share_method=share_link
     *
     * The same GET both generates the link (marking the invoice Sent, the
     * documented side effect) and returns the existing one — so it is safe to
     * call on every regenerate. Requires the user:invoices:write scope (we have
     * it). The response carries a `share_link` field with the FULL URL.
     *
     * IMPORTANT: this is NOT the invoice's `direct_links[].token`, which is an
     * internal reference that 404s as a /#/link/ URL. We only fall back to that
     * (then to the internal /#/invoice/ view) if the share_link call fails.
     *
     * @param int|string $invoice_id FreshBooks invoice id (invoiceid).
     * @param array       $doc        The invoice doc (for fallback only).
     * @param string      $type       'invoice' (fallback only).
     * @return string The share URL, or '' if nothing could be resolved.
     */
    private function fb_get_share_link( $invoice_id, array $doc = [], string $type = 'invoice' ): string {
        $account_id = $this->fb_get_account_id();
        $invoice_id = (int) $invoice_id;

        if ( $account_id && $invoice_id > 0 ) {
            $url = "https://api.freshbooks.com/accounting/account/{$account_id}"
                 . "/invoices/invoices/{$invoice_id}/share_link?share_method=share_link";
            $resp = $this->fb_request( 'GET', $url );

            // The value may be the full URL or a bare token, and may sit at the
            // top level or inside the standard accounting response.result wrapper.
            $candidates = [];
            if ( is_array( $resp ) ) {
                $candidates[] = $resp['share_link'] ?? null;
                $candidates[] = $resp['response']['result']['share_link']['share_link'] ?? null;
                $candidates[] = $resp['response']['result']['share_link'] ?? null;
                $candidates[] = $resp['response']['result']['client_view_link'] ?? null;
            }
            foreach ( $candidates as $c ) {
                if ( is_string( $c ) && $c !== '' ) {
                    // Full URL already → use as-is. Bare token → wrap it.
                    if ( strpos( $c, 'http' ) === 0 || strpos( $c, '#/link/' ) !== false ) {
                        return $c;
                    }
                    return "https://my.freshbooks.com/#/link/{$c}";
                }
            }
            // v3.3.8 — Log the FULL decoded response (truncated), not just the
            // top-level keys. The previous keys-only line couldn't reveal where
            // the link actually sits when it's nested. With the whole envelope in
            // the log we can lock the exact field path in the candidates[] list
            // above on the next real run.
            error_log( 'ZRCPT share_link: endpoint returned no share_link for invoice #' . $invoice_id
                . ' — resp keys: ' . ( is_array( $resp ) ? implode( ',', array_keys( $resp ) ) : gettype( $resp ) )
                . ' — full resp: ' . substr( wp_json_encode( $resp ), 0, 1500 ) );
        }

        // v3.3.12 — The direct_links[].token fallback was REMOVED. It produces a
        // /#/link/{token} URL that is a KNOWN-DEAD customer link (e.g.
        // 3wMtiTbkqQyMCzPQ → "Something went wrong"); emitting it is worse than
        // emitting nothing, because the receipt then shows a button that 404s for
        // the homeowner. The share_link endpoint above is confirmed working (its
        // JWT /#/link/eyJ… URL opens for a logged-out viewer), so we rely on it.
        //
        // Last resort ONLY: the internal account view. This is staff-only and
        // will NOT open for the homeowner — but it is not a broken-customer-link
        // trap, and the receipt code can choose to suppress the button when the
        // URL is the internal /#/invoice/ form. We log so this case is visible.
        if ( $invoice_id > 0 ) {
            error_log( 'ZRCPT share_link: endpoint did not return a link for invoice #' . $invoice_id
                . ' — falling back to the internal /#/invoice/ view (staff-only). '
                . 'If you see this, capture the "full resp" line above so the field path can be fixed.' );
            return "https://my.freshbooks.com/#/invoice/{$invoice_id}";
        }
        return '';
    }

    /**
     * Compare two receipt URLs ignoring noise (whitespace, trailing slash,
     * http/https). Used to decide whether an invoice's receipt-link line is
     * CURRENT or STALE.
     */
    public static function fb_receipt_url_equals( $a, $b ): bool {
        $norm = static function ( $u ) {
            $u = strtolower( trim( (string) $u ) );
            $u = preg_replace( '#^https?://#', '', $u );
            return rtrim( $u, '/' );
        };
        return $norm( $a ) !== '' && $norm( $a ) === $norm( $b );
    }

    /**
     * Ensure the "Installation Receipt - Link" line on a FreshBooks invoice or
     * estimate points at $receipt_url.
     *
     * v3.9.3 — VERIFY-AND-REFRESH, not merely add-if-absent. The old
     * "idempotency" check bailed out the moment ANY line named 'Installation
     * Receipt - Link' existed — without ever comparing its URL. That was safe
     * only while one invoice's receipt URL could never change; two features
     * broke that invariant: admin DELETE + regenerate mints a NEW post with a
     * NEW share token (the invoice #15583 field failure — the invoice kept a
     * link to the trashed receipt while three consecutive generates logged
     * "already exists" and skipped), and the v3.7.0 "Regenerate link" token
     * revoke changes the URL of the SAME post. Now:
     *   • line exists and URL matches      → no write, log "already current"
     *   • line exists with a DIFFERENT URL → rewrite that line in place
     *     (every matching line, if duplicates exist), log "REFRESHED"
     *   • no line                          → append, exactly as before
     * Passing $receipt_url = '' BLANKS the line's description (used when a
     * receipt is deleted with no successor, so the invoice never carries a
     * dead link); with '' and no existing line, nothing is added.
     * Preserves all other lines verbatim (lineid, taxes, etc.).
     *
     * @param string $doc_type    'invoice' or 'estimate'.
     * @param int    $doc_id      FreshBooks document ID.
     * @param string $receipt_url The receipt page permalink ('' = blank the line).
     * @return bool  True on success (including no-op), false on failure.
     */
    /**
     * The label for the $0 receipt-link line written onto the billing document (Document
     * Convention D20). Bound through the `zrcpt_document_convention` filter; the neutral
     * default carries no product/brand word.
     */
    private function receipt_link_label(): string {
        $label = (string) zrcpt_document_convention( 'receipt_link_label', 'Installation Receipt - Link' );
        return $label !== '' ? $label : 'Installation Receipt - Link';
    }

    /**
     * Does a billing line name identify the receipt-link line? Matches the configured label AND
     * the legacy label, so lines written before a convention change still resolve.
     */
    private function is_receipt_link_line( string $name ): bool {
        $name = trim( $name );
        if ( $name === '' ) {
            return false;
        }
        $known = array_unique( array_filter( [ $this->receipt_link_label(), 'Installation Receipt - Link' ] ) );
        return in_array( $name, $known, true );
    }

    private function fb_add_receipt_link( $doc_type, $doc_id, $receipt_url ) {
        $account_id = $this->fb_get_account_id();
        if ( empty( $account_id ) || empty( $doc_id ) ) {
            error_log( 'ZRCPT fb_add_receipt_link: missing account_id=' . ( $account_id ?: 'EMPTY' ) . ' or doc_id=' . ( $doc_id ?: 'EMPTY' ) );
            return false;
        }

        $endpoint = ( $doc_type === 'estimate' ) ? 'estimates/estimates' : 'invoices/invoices';
        $doc_key  = ( $doc_type === 'estimate' ) ? 'estimate' : 'invoice';
        $base     = "https://api.freshbooks.com/accounting/account/{$account_id}";

        // GET current document with its lines (include[]=lines ensures lines come back)
        $url  = "{$base}/{$endpoint}/{$doc_id}?include[]=lines";
        $resp = $this->fb_request( 'GET', $url );
        $doc  = $resp['response']['result'][ $doc_key ] ?? null;
        if ( ! $doc ) {
            error_log( "ZRCPT fb_add_receipt_link: GET failed for {$doc_type} {$doc_id}" );
            return false;
        }

        // v3.9.3 — inspect every existing receipt-link line and decide.
        $lines       = $doc['lines'] ?? [];
        $have_line   = false;   // any 'Installation Receipt - Link' line exists
        $needs_write = false;   // at least one such line carries a different URL
        foreach ( $lines as $line ) {
            if ( ! $this->is_receipt_link_line( (string) ( $line['name'] ?? '' ) ) ) { continue; }
            $have_line = true;
            $current   = (string) ( $line['description'] ?? '' );
            $is_current = ( $receipt_url === '' )
                ? ( trim( $current ) === '' )
                : self::fb_receipt_url_equals( $current, $receipt_url );
            if ( ! $is_current ) { $needs_write = true; }
        }

        if ( $have_line && ! $needs_write ) {
            error_log( "ZRCPT FreshBooks: Receipt link already CURRENT on {$doc_type} #{$doc_id} — no write." );
            return true;
        }
        if ( ! $have_line && $receipt_url === '' ) {
            return true; // nothing to blank
        }

        // Build updated lines array — preserve existing lines with writable
        // fields, REWRITING every receipt-link line's description to the
        // target URL (or to '' when blanking).
        $updated_lines = [];
        $stale_seen    = '';
        $writable_keys = [ 'lineid', 'type', 'name', 'description', 'qty', 'unit_cost',
                           'taxName1', 'taxAmount1', 'taxName2', 'taxAmount2' ];
        foreach ( $lines as $line ) {
            $preserved = [];
            foreach ( $writable_keys as $k ) {
                if ( isset( $line[ $k ] ) ) {
                    $preserved[ $k ] = $line[ $k ];
                }
            }
            if ( $this->is_receipt_link_line( (string) ( $preserved['name'] ?? '' ) ) ) {
                if ( $stale_seen === '' ) { $stale_seen = (string) ( $preserved['description'] ?? '' ); }
                $preserved['description'] = $receipt_url;
            }
            $updated_lines[] = $preserved;
        }

        // No receipt-link line at all → append one (original behavior).
        if ( ! $have_line ) {
            $updated_lines[] = [
                'type'        => 0,
                'name'        => $this->receipt_link_label(),
                'description' => $receipt_url,
                'qty'         => '1',
                'unit_cost'   => [ 'amount' => '0.00', 'code' => 'USD' ],
            ];
        }

        // PUT the updated document
        $put_url = "{$base}/{$endpoint}/{$doc_id}";
        $resp = $this->fb_request( 'PUT', $put_url, [
            $doc_key => [ 'lines' => $updated_lines ],
        ] );

        if ( $resp ) {
            if ( $have_line && $receipt_url === '' ) {
                error_log( "ZRCPT FreshBooks: Receipt link CLEARED on {$doc_type} #{$doc_id} (was: {$stale_seen})." );
            } elseif ( $have_line ) {
                error_log( "ZRCPT FreshBooks: Receipt link REFRESHED on {$doc_type} #{$doc_id} (was: {$stale_seen} → now: {$receipt_url})." );
            } else {
                error_log( "ZRCPT FreshBooks: Receipt link added to {$doc_type} #{$doc_id}." );
            }
            return true;
        }

        return false;
    }

    /**
     * Extract invoice/estimate number from a filename.
     *
     * Handles common naming patterns:
     *   "Invoice 15217.pdf"        → 15217
     *   "Estimate 5431.pdf"        → 5431
     *   "Invoice 15217-4.pdf"      → 15217
     *   "INV-15217.pdf"            → 15217
     *   "EST-5431.pdf"             → 5431
     *   "15217.pdf"                → 15217
     *
     * @param string $filename  The uploaded file's original name.
     * @return string  The extracted document number, or empty string.
     */
    private function extract_document_number( $filename ) {
        $name = pathinfo( $filename, PATHINFO_FILENAME );

        // "Invoice 15217" / "Estimate 5431" / "Invoice 15217-4"
        if ( preg_match( '/(?:invoice|estimate|est|inv)[#\s._-]*(\d{3,})/i', $name, $m ) ) {
            return $m[1];
        }

        // "INV-15217" / "EST-5431"
        if ( preg_match( '/^(?:INV|EST)[#\s._-]*(\d{3,})/i', $name, $m ) ) {
            return $m[1];
        }

        // Just a number: "15217.pdf"
        if ( preg_match( '/^(\d{3,})(?:[_-]\d+)?$/', $name, $m ) ) {
            return $m[1];
        }

        // Number at end: "doc-15217"
        if ( preg_match( '/(\d{3,})(?:-\d+)?$/', $name, $m ) ) {
            return $m[1];
        }

        return '';
    }

    /**
     * Link a receipt to its FreshBooks invoice/estimate.
     *
     * Orchestrates the full FreshBooks integration:
     *   1. Extract document number from uploaded filename.
     *   2. Search FreshBooks for matching invoice or estimate.
     *   3. Add "Installation Receipt - Link" line item.
     *   4. Store the FreshBooks document reference in post meta.
     *
     * Non-blocking: failures are logged but never prevent receipt creation.
     *
     * @param int    $post_id       The receipt WordPress post ID.
     * @param string $receipt_url   The receipt page permalink.
     * @param string $invoice_name  The uploaded invoice filename.
     * @param array  $receipt_data  Parsed receipt data from the AI bot.
     * @return array  Status array: [ 'linked' => bool, 'doc_type' => ..., 'doc_number' => ..., 'error' => ... ]
     */
    public function fb_link_receipt( $post_id, $receipt_url, $invoice_name, $receipt_data = [], $all_numbers = [] ) {
        $result = [
            'linked'     => false,
            'doc_type'   => '',
            'doc_number' => '',
            'error'      => '',
            'share_link' => '', // v3.3.9 — populated on success for diagnostics
        ];

        // Check if FreshBooks credentials are available
        if ( ! $this->fb_has_credentials() ) {
            $result['error'] = 'FreshBooks not connected — receipt link not added.';
            error_log( 'ZRCPT FreshBooks: No credentials available, skipping invoice link.' );
            return $result;
        }

        // v3.6.6 — Build the list of invoice/estimate numbers to link. For a
        // combo receipt the caller passes EVERY source invoice number so the
        // receipt link is written back onto ALL of them; otherwise we fall back
        // to the single number derived from the filename / bot response.
        $numbers = [];
        foreach ( (array) $all_numbers as $n ) {
            $d = preg_replace( '/[^0-9]/', '', (string) $n );
            if ( $d !== '' ) { $numbers[] = $d; }
        }
        if ( empty( $numbers ) ) {
            $doc_number = $this->extract_document_number( $invoice_name );
            if ( empty( $doc_number ) && ! empty( $receipt_data['invoice_number'] ) ) {
                $doc_number = preg_replace( '/[^0-9]/', '', $receipt_data['invoice_number'] );
            }
            if ( empty( $doc_number ) && ! empty( $receipt_data['estimate_number'] ) ) {
                $doc_number = preg_replace( '/[^0-9]/', '', $receipt_data['estimate_number'] );
            }
            if ( ! empty( $doc_number ) ) { $numbers[] = $doc_number; }
        }
        $numbers = array_values( array_unique( $numbers ) );

        if ( empty( $numbers ) ) {
            $result['error'] = 'Could not determine invoice/estimate number from filename "' . $invoice_name . '".';
            error_log( 'ZRCPT FreshBooks: No document number found in filename: ' . $invoice_name );
            return $result;
        }

        // Link the receipt onto EACH invoice. The first successfully-linked doc
        // is the "primary" and populates the legacy single-value meta + the
        // returned share link (back-compat); every linked doc id/number is also
        // stored as a list.
        $linked_ids     = [];
        $linked_numbers = [];
        $share_links    = []; // number => share link url
        $errors         = [];
        $primary_set    = false;

        foreach ( $numbers as $doc_number ) {
            $found = $this->fb_find_document( $doc_number );
            if ( ! $found || empty( $found['doc'] ) ) {
                $errors[] = "#{$doc_number} not found";
                error_log( "ZRCPT FreshBooks: Document #{$doc_number} not found." );
                continue;
            }
            $type = $found['type'];
            $fb_doc_id = $found['doc'][ ( $type === 'estimate' ) ? 'estimateid' : 'invoiceid' ]
                      ?? $found['doc']['id']
                      ?? '';
            if ( empty( $fb_doc_id ) ) {
                $errors[] = "#{$doc_number} id unresolved";
                continue;
            }

            $linked = $this->fb_add_receipt_link( $type, $fb_doc_id, $receipt_url );
            if ( ! $linked ) {
                $errors[] = "#{$doc_number} link failed";
                error_log( "ZRCPT FreshBooks: Failed to add receipt link to {$type} #{$doc_number}." );
                continue;
            }

            $linked_ids[]     = (string) $fb_doc_id;
            $linked_numbers[] = (string) $doc_number;

            // Resolve this invoice's customer-facing share link.
            $sl = $this->fb_get_share_link( $fb_doc_id, $found['doc'], $type );
            if ( $sl !== '' ) { $share_links[ (string) $doc_number ] = $sl; }

            // First linked doc = primary (legacy single-value meta + return).
            if ( ! $primary_set ) {
                $primary_set          = true;
                $result['linked']     = true;
                $result['doc_type']   = $type;
                $result['doc_number'] = (string) $doc_number;
                update_post_meta( $post_id, '_fb_doc_type',   $type );
                update_post_meta( $post_id, '_fb_doc_id',     $fb_doc_id );
                update_post_meta( $post_id, '_fb_doc_number', $doc_number );
                if ( $sl !== '' ) {
                    update_post_meta( $post_id, '_fb_share_link', $sl );
                    $result['share_link'] = $sl;
                }
            }

            error_log( "ZRCPT FreshBooks: Successfully linked receipt (post #{$post_id}) to {$type} #{$doc_number} (ID: {$fb_doc_id})." );
        }

        // v3.6.6 — store the full list of linked doc ids + the per-invoice share
        // links so a combo receipt's provenance and "view invoice" links are
        // complete (not just the primary). Keeps the existing _fb_doc_numbers
        // provenance list — written by the generate handler — authoritative.
        if ( ! empty( $linked_ids ) ) {
            update_post_meta( $post_id, '_fb_doc_ids', array_values( array_unique( $linked_ids ) ) );
        }
        if ( ! empty( $share_links ) ) {
            update_post_meta( $post_id, '_fb_share_links', $share_links );
        }

        if ( ! $result['linked'] ) {
            $result['error'] = 'Could not link any invoice: ' . ( $errors ? implode( '; ', $errors ) : 'unknown error' );
        } elseif ( ! empty( $errors ) ) {
            // Linked at least one, but not all — surface the partial failure.
            $result['error'] = 'Some invoices were not linked: ' . implode( '; ', $errors );
        }

        error_log( sprintf(
            'ZRCPT FB LINK SUMMARY: post_id=%d requested=[%s] linked=[%s] share_links=%d',
            $post_id, implode( ',', $numbers ), implode( ',', $linked_numbers ), count( $share_links )
        ) );

        return $result;
    }

    /* =====================================================================
       v3.9.3 — INVOICE-LINK SYNC (the "link on the invoice is 100% openable"
       guarantee). One rule: whatever changes a receipt's URL — or which
       receipt an invoice should point at — immediately re-syncs the
       invoice's "Installation Receipt - Link" line. Sync points:
         • every generate (fb_link_receipt, now verify-and-refresh)
         • "Regenerate link" token revoke  → fb_sync_receipt_link()
         • Restore from Trash (guarded)    → fb_sync_receipt_link()
         • Trash: successor receipt exists → sync the successor;
                  no successor            → BLANK the line (no dead links)
         • Manage Receipts "Sync FB link" row action (manual repair)
       All best-effort: FreshBooks trouble is logged, never blocks the
       receipt operation itself.
       ===================================================================== */

    /**
     * Re-point every linked FreshBooks document at THIS receipt's current URL.
     * Thin wrapper over fb_link_receipt() (which is verify-and-refresh as of
     * v3.9.3), fed from the receipt's stored provenance.
     *
     * @return array fb_link_receipt()-shaped result (linked/doc/error/…).
     */
    public function fb_sync_receipt_link( int $post_id ): array {
        $numbers = (array) get_post_meta( $post_id, '_fb_doc_numbers', false );
        if ( empty( $numbers ) ) {
            $single = (string) get_post_meta( $post_id, '_fb_doc_number', true );
            if ( $single !== '' ) { $numbers = [ $single ]; }
        }
        if ( empty( $numbers ) ) {
            return [ 'linked' => false, 'doc_type' => '', 'doc_number' => '', 'error' => 'No linked invoice numbers on this receipt.', 'share_link' => '' ];
        }
        $url = get_permalink( $post_id );
        error_log( 'ZRCPT FB SYNC: post_id=' . $post_id . ' → re-pointing [' . implode( ',', $numbers ) . '] at ' . $url );
        return $this->fb_link_receipt( $post_id, $url, '', [], $numbers );
    }

    /**
     * Blank the receipt-link line on the given invoice/estimate numbers (used
     * when a receipt is deleted with NO successor — an invoice must never
     * carry a link that 404s). Best-effort per document.
     */
    private function fb_clear_receipt_link_for_numbers( array $numbers ): void {
        foreach ( array_unique( array_filter( array_map( 'strval', $numbers ) ) ) as $n ) {
            try {
                $found = $this->fb_find_document( $n );
                if ( ! $found || empty( $found['doc'] ) ) { continue; }
                $type = $found['type'];
                $id   = $found['doc'][ ( $type === 'estimate' ) ? 'estimateid' : 'invoiceid' ]
                      ?? $found['doc']['id'] ?? '';
                if ( $id ) { $this->fb_add_receipt_link( $type, $id, '' ); }
            } catch ( \Throwable $e ) {
                error_log( 'ZRCPT FB SYNC: clear failed for #' . $n . ': ' . $e->getMessage() );
            }
        }
    }

    /**
     * After a receipt is trashed: every invoice it was linked to must either
     * point at its SUCCESSOR receipt (if one exists, published) or carry NO
     * link at all. Called from trash_receipt(); best-effort.
     */
    private function fb_sync_after_trash( int $post_id ): void {
        $numbers = (array) get_post_meta( $post_id, '_fb_doc_numbers', false );
        if ( empty( $numbers ) ) {
            $single = (string) get_post_meta( $post_id, '_fb_doc_number', true );
            if ( $single !== '' ) { $numbers = [ $single ]; }
        }
        $orphaned = [];
        $synced_successors = [];
        foreach ( $numbers as $n ) {
            $successor = $this->receipt_for_invoice( $n ); // trash excluded by its query
            if ( $successor && ! empty( $successor['post_id'] ) && (int) $successor['post_id'] !== $post_id ) {
                $sid = (int) $successor['post_id'];
                if ( ! in_array( $sid, $synced_successors, true ) ) {
                    $synced_successors[] = $sid;
                    try { $this->fb_sync_receipt_link( $sid ); } catch ( \Throwable $e ) {
                        error_log( 'ZRCPT FB SYNC: successor sync failed for post ' . $sid . ': ' . $e->getMessage() );
                    }
                }
            } else {
                $orphaned[] = (string) $n;
            }
        }
        if ( ! empty( $orphaned ) ) {
            error_log( 'ZRCPT FB SYNC: trash of post ' . $post_id . ' leaves no receipt for [' . implode( ',', $orphaned ) . '] — blanking their invoice link lines.' );
            $this->fb_clear_receipt_link_for_numbers( $orphaned );
        }
    }

    /* =====================================================================
       v2.9.0 — AUTO-LOOKUP + HANDOFF + DIAGNOSTICS
       ===================================================================== */

    /**
     * Cache bypass for the Prep module handoffs. WP Engine (and any full-page
     * cache) would otherwise serve everyone the same pre-populated page.
     * Hooked to send_headers so it runs before content is emitted.
     * Trap 4: belt + suspenders with the `?_ts=<timestamp>` param on the
     * cutter's side.
     */
    public function maybe_bypass_page_cache() {
        if ( ! empty( $_GET['zrcpt_from_cutter'] ) ) {
            nocache_headers();
            header( 'Cache-Control: private, no-cache, no-store, must-revalidate' );
            header( 'Pragma: no-cache' );
        }
    }

    /**
     * Nonce + capability guard for the new admin AJAX endpoints.
     */
    private function zrcpt_ajax_guard() {
        $this->zrcpt_clean_output_buffer();
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! self::user_can_access() ) { // v3.6.9 (H1): real app-access, not blanket zdz_access_app
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }
    }

    /**
     * v3.1.1 (chat-regression class safety net): keep AJAX JSON CLEAN.
     * WP 6.7+ can emit a "_load_textdomain_just_in_time" notice (or any other
     * PHP notice/warning from a plugin loaded before init) straight into the
     * response body, which corrupts the JSON and surfaces as "Network error"
     * or "Lookup failed" in the UI. Suppress display + discard any stray output
     * already buffered, then start a clean buffer so only our JSON is sent.
     */
    private function zrcpt_clean_output_buffer() {
        if ( ! headers_sent() ) {
            @ini_set( 'display_errors', '0' );
            while ( ob_get_level() > 0 ) { @ob_end_clean(); }
            ob_start();
        }
    }

    /**
     * AJAX — zrcpt_lookup. Search FB for an estimate/invoice by number/name/phone.
     * Delegates to ZRCPT_FreshBooks::search() which prefers ZPREP_FreshBooks if
     * the the Prep module plugin is active.
     */
    public function ajax_lookup() {
        $this->zrcpt_ajax_guard();

        $query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
        if ( $query === '' ) {
            wp_send_json_error( [ 'message' => 'Please enter something to search.' ] );
        }

        $fb = new ZRCPT_FreshBooks();
        if ( ! $fb->is_ready() ) {
            wp_send_json_error( [ 'message' => 'FreshBooks is not configured. Connect a billing provider in Zorderz settings to enable auto-lookup.' ] );
        }

        $result = $fb->search( $query, [
            'include_estimates' => true,
            'include_invoices'  => true,
            'tag_filter'         => zrcpt_receipt_item_tag(),
        ] );

        if ( ( $result['status'] ?? '' ) !== 'ok' ) {
            wp_send_json_error( [ 'message' => $result['message'] ?? 'Lookup failed.' ] );
        }

        $matches = $result['matches'];

        // Enrich first match with client detail (address/phone/email for the card).
        if ( ! empty( $matches[0]['customer_id'] ) ) {
            $client = $fb->get_client( $matches[0]['customer_id'] );
            if ( $client ) {
                $matches[0]['customer_detail'] = $client;
            }
        }

        wp_send_json_success( [ 'matches' => $matches, 'query' => $query ] );
    }

    /**
     * AJAX — zrcpt_pull_nutshell_install. Pull install-day activity + notes for
     * the selected customer's Nutshell lead.
     */
    public function ajax_pull_nutshell_install() {
        $this->zrcpt_ajax_guard();

        $customer_json = isset( $_POST['customer'] ) ? wp_unslash( $_POST['customer'] ) : '';
        $customer = json_decode( $customer_json, true );
        if ( ! is_array( $customer ) ) {
            wp_send_json_error( [ 'message' => 'Missing customer payload.' ] );
        }

        $ns = new ZRCPT_Nutshell();
        if ( ! $ns->is_ready() ) {
            // Not an error — receipt can still generate without install notes.
            wp_send_json_success( [ 'install_notes' => [], 'message' => 'Nutshell not configured; proceeding without install notes.' ] );
        }

        $lead = $ns->find_lead_for_customer( [
            'name'             => $customer['name']             ?? '',
            'email'            => $customer['email']            ?? '',
            'phone'            => $customer['phone']            ?? '',
            'estimate_number'  => $customer['estimate_number']  ?? '',
        ] );

        if ( ! $lead ) {
            wp_send_json_success( [
                'install_notes' => [],
                'trace'         => $ns->get_last_trace(),
                'message'       => 'No matching Nutshell lead; proceeding without install notes.'
            ] );
        }

        // Pass the full lead array (it already carries notes[] from
        // find_lead_for_customer) so install-note discovery needs no extra API
        // call and never depends on a delegate method that may not exist.
        $notes = $ns->find_install_notes_for_lead( $lead );

        wp_send_json_success( [
            'lead_id'       => $lead['id'],
            'install_notes' => $notes,
        ] );
    }

    /**
     * AJAX — zrcpt_test_fb. Diagnostic probes (delegates to the Prep module).
     */
    public function ajax_test_fb() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Admin access required.' ], 403 );
        }
        $fb = new ZRCPT_FreshBooks();
        $results = $fb->test_connection();

        $sources = [
            'fb_client_id'     => ZRCPT_FreshBooks::resolve_credential_source( 'fb_client_id' ),
            'fb_access_token'  => ZRCPT_FreshBooks::resolve_credential_source( 'fb_access_token' ),
            'fb_refresh_token' => ZRCPT_FreshBooks::resolve_credential_source( 'fb_refresh_token' ),
            'fb_account_id'    => ZRCPT_FreshBooks::resolve_credential_source( 'fb_account_id' ),
        ];

        wp_send_json_success( [
            'tests'   => $results,
            'sources' => $sources,
        ] );
    }

    /**
     * AJAX — zrcpt_test_ns.
     */
    public function ajax_test_ns() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Admin access required.' ], 403 );
        }
        $ns = new ZRCPT_Nutshell();
        $result = $ns->test_connection();

        $sources = [
            'ns_email'   => ZRCPT_FreshBooks::resolve_credential_source( 'ns_email' ),
            'ns_api_key' => ZRCPT_FreshBooks::resolve_credential_source( 'ns_api_key' ),
        ];

        wp_send_json_success( [
            'result'  => $result,
            'sources' => $sources,
        ] );
    }

    /**
     * v3.0.0 — AJAX: Smart Lookup. AI-powered flexible invoice search.
     * Available to ALL logged-in users.
     */
    public function ajax_smart_lookup() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! self::user_can_access() ) { // v3.6.9 (H2): was current_user_can('read') — any subscriber could search FreshBooks
            wp_send_json_error( [ 'message' => 'You do not have access to the receipt generator.' ], 403 );
        }

        $raw_input = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
        if ( $raw_input === '' ) {
            wp_send_json_error( [ 'message' => 'Enter an invoice #, customer name, email, phone, or address.' ] );
        }

        $fb = new ZRCPT_FreshBooks();
        if ( ! $fb->is_ready() ) {
            wp_send_json_error( [ 'message' => 'Billing is not connected. Ask an admin to connect a billing provider in Zorderz settings.' ] );
        }

        // Classify the input (heuristic fast-path, AI for ambiguous)
        $classified = $this->classify_lookup_input( $raw_input );

        $result = $fb->search( $classified, [
            'include_estimates' => true,
            'include_invoices'  => true,
            'tag_filter'         => zrcpt_receipt_item_tag(),
        ] );

        // Retry with raw string if AI classification returned nothing
        if ( empty( $result['matches'] ) && $classified['type'] !== 'raw' ) {
            $result = $fb->search( $raw_input, [
                'include_estimates' => true,
                'include_invoices'  => true,
                'tag_filter'         => zrcpt_receipt_item_tag(),
            ] );
        }

        if ( empty( $result['matches'] ) ) {
            wp_send_json_error( [ 'message' => $result['message'] ?? 'No matches found.' ] );
        }

        $matches = $result['matches'];

        // Enrich first match with client detail
        if ( ! empty( $matches[0]['customer_id'] ) && empty( $matches[0]['customer_detail'] ) ) {
            $client = $fb->get_client( $matches[0]['customer_id'] );
            if ( $client ) {
                $matches[0]['customer_detail'] = $client;
            }
        }

        // v3.3.0 — Invoice-only flow + receipt cross-check. Mark which matches
        // are invoices (the only selectable type), which are already on a
        // receipt (not suggested), and surface estimates as informational only.
        $annotated = $this->annotate_matches_for_receipt( $matches );

        wp_send_json_success( [
            'matches'            => $annotated['matches'],
            'invoice_count'      => $annotated['invoice_count'],
            'selectable_count'   => $annotated['selectable_count'],
            'has_estimates_only' => $annotated['has_estimates_only'],
            'query'              => $raw_input,
        ] );
    }

    /**
     * Classify user input for smart lookup (heuristic fast-path).
     */
    private function classify_lookup_input( $input ) {
        $trimmed = trim( $input );

        // Email (check first — most specific)
        if ( filter_var( $trimmed, FILTER_VALIDATE_EMAIL ) ) {
            return [ 'type' => 'email', 'value' => $trimmed, 'raw' => $input ];
        }

        // Phone: 7+ digits total in the string (catches 760-518-3209, (858) 555-1212, etc.)
        $all_digits = preg_replace( '/[^0-9]/', '', $trimmed );
        if ( strlen( $all_digits ) >= 7 && strlen( $all_digits ) <= 15 ) {
            return [ 'type' => 'phone', 'value' => $trimmed, 'raw' => $input ];
        }

        // Pure number: "14767", "#14767"
        $clean = preg_replace( '/[\s#]/', '', $trimmed );
        if ( preg_match( '/^\d{3,}$/', $clean ) ) {
            return [ 'type' => 'number', 'value' => preg_replace( '/[^0-9]/', '', $trimmed ), 'raw' => $input ];
        }

        // Document keyword + number: "Invoice 14767", "Inv #14767", "EST 5541"
        if ( preg_match( '/^(?:invoice|inv|estimate|est|receipt|rec)[#\s._-]*(\d{3,})/i', $trimmed, $m ) ) {
            return [ 'type' => 'number', 'value' => $m[1], 'raw' => $input ];
        }

        // Default: name
        return [ 'type' => 'name', 'value' => $trimmed, 'raw' => $input ];
    }

    /**
     * v3.1.0 — AJAX: Match already-captured photos to a selected job.
     *
     * This is the heart of the photo-first flow. After the tech picks a job
     * (via Smart Lookup), the widget calls this to pull the photos they already
     * captured in Zorderz and group them into capture sessions. The newest
     * session is the installation set; the one before is the estimate/"before"
     * set. We also hand back an EXIF-derived install DATE so the form can
     * pre-fill it.
     *
     * Available to ALL logged-in users; strictly scoped to the caller's own
     * media (kiosk-safe — under the kiosk identity switch the request genuinely
     * IS the shared user, so "your photos" is correctly limited).
     */
    public function ajax_match_media() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'read' ) ) {
            wp_send_json_error( [ 'message' => 'Please log in.' ], 403 );
        }

        $user_id = get_current_user_id();

        if ( ! ZRCPT_Media::is_available() ) {
            // Older theme without the shared store — not an error; the widget
            // simply falls back to manual upload.
            wp_send_json_success( [
                'available' => false,
                'sessions'  => [],
                'message'   => 'Photo library not available on this site — upload photos manually.',
            ] );
        }

        // Optional GPS gate from the selected customer (passed by the widget when
        // FreshBooks gave us a geocodable address; today we only gate when the
        // client actually carried coordinates, which is rare, so this is mostly
        // a no-op until address geocoding is added — left in so the path exists).
        $opts = [ 'lookback_days' => 120 ];
        $near_lat = isset( $_POST['near_lat'] ) ? (float) $_POST['near_lat'] : null;
        $near_lng = isset( $_POST['near_lng'] ) ? (float) $_POST['near_lng'] : null;
        if ( is_numeric( $near_lat ) && is_numeric( $near_lng ) && ( $near_lat || $near_lng ) ) {
            $opts['near_lat'] = $near_lat;
            $opts['near_lng'] = $near_lng;
        }

        $result = ZRCPT_Media::get_sessions_for_user( $user_id, $opts );

        // Suggested install date = newest session's capture date (editable client-side).
        $suggested_date = '';
        if ( ! empty( $result['sessions'][0]['date_input'] ) ) {
            $suggested_date = $result['sessions'][0]['date_input'];
        }

        wp_send_json_success( [
            'available'      => $result['available'],
            'sessions'       => $result['sessions'],
            'total_photos'   => $result['total_photos'],
            'suggested_date' => $suggested_date,
        ] );
    }

    /**
     * Build a plain-text customer block for the AI input when auto-lookup
     * succeeds. Replaces the invoice-image upload on that path (Trap 5).
     * Keeps the manual-upload path unchanged.
     */
    /**
     * v3.5.0 — Derive install details from the FreshBooks document itself,
     * used when no Nutshell lead/notes exist for the customer. The invoice
     * usually says everything the receipt needs — product, count, color,
     * what's included — it was just never being READ as install context:
     *   "(4) units. White Color."  → product, count, color
     *   "Tax and Installation Included."   → scope wording
     *   Reference "92029-EM"               → job/category code
     * We pass the substantive line text through verbatim (the bot is good at
     * reading prose) plus the computed unit count, and skip pure plumbing
     * (the receipt-link line, URLs, empty/zero rows' boilerplate).
     *
     * @param array $lookup_data FreshBooks lookup payload (type/number/reference).
     * @param array $lines       Authoritative (server-fetched) line items.
     * @return array<string>     Note strings, '' when nothing useful was found.
     */
    private function derive_install_notes_from_invoice( array $lookup_data, array $lines ): array {
        $notes = [];

        foreach ( (array) $lines as $ln ) {
            if ( ! is_array( $ln ) ) {
                $t = trim( (string) $ln );
                if ( $t !== '' && stripos( $t, 'http' ) === false ) { $notes[] = $t; }
                continue;
            }
            $name  = trim( (string) ( $ln['name'] ?? '' ) );
            $desc  = trim( (string) ( $ln['description'] ?? '' ) );
            $label = $this->line_label( $name, $desc );
            if ( $label === '' ) { continue; }
            $hay = strtolower( $label );
            // Skip plumbing lines: the receipt link and bare URLs. Keep the
            // location line (job-area context) and all product/scope text.
            if ( strpos( $hay, 'receipt' ) !== false && strpos( $hay, 'link' ) !== false ) { continue; }
            if ( strpos( $hay, 'http://' ) !== false || strpos( $hay, 'https://' ) !== false ) { continue; }
            $notes[] = $label;
        }

        if ( empty( $notes ) ) { return []; }

        // Job/category reference (e.g. an invoice reference code) up front.
        if ( ! empty( $lookup_data['reference'] ) ) {
            array_unshift( $notes, 'Invoice reference: ' . trim( (string) $lookup_data['reference'] ) );
        }

        // The unit count, restated as install context (same number the
        // headline override uses, so every layer agrees).
        $count = $this->compute_vent_count( $lines );
        if ( $count > 0 ) {
            $notes[] = zrcpt_count_phrase( zrcpt_receipt_item_tag(), $count )
                     . ' installed per the invoice line text/quantities.';
        }

        return $notes;
    }

    public function build_customer_block( array $customer, array $lines = [], array $install_notes = [], string $notes_source = 'nutshell' ): string {
        $parts = [];
        $parts[] = '=== Customer (from FreshBooks auto-lookup) ===';
        if ( ! empty( $customer['name'] ) )    $parts[] = 'Name:    ' . $customer['name'];
        if ( ! empty( $customer['address'] ) ) $parts[] = 'Address: ' . $customer['address'];
        if ( ! empty( $customer['email'] ) )   $parts[] = 'Email:   ' . $customer['email'];
        if ( ! empty( $customer['phone'] ) )   $parts[] = 'Phone:   ' . $customer['phone'];
        if ( ! empty( $customer['number'] ) )  $parts[] = 'Doc #:   ' . $customer['number'];

        // v3.3.9 — reset before (re)computing so the diagnostic never reports a
        // stale or carried-over count when this block has no lines to total.
        $this->last_vent_count = 0;

        if ( ! empty( $lines ) ) {
            $parts[] = '';
            $parts[] = '=== Line items (with quantities) ===';
            // CRITICAL: include the QUANTITY on every line. Without it the bot
            // can't total the units and falls back to "1 unit" (it was
            // only seeing the headline line). FreshBooks puts the real counts on
            // the per-line quantities (e.g. one unit line × 55, another × 2,
            // a third × 6).
            $vent_qty_total = 0;
            foreach ( $lines as $ln ) {
                if ( is_array( $ln ) ) {
                    $name = trim( (string) ( $ln['name'] ?? '' ) );
                    $desc = trim( (string) ( $ln['description'] ?? '' ) );
                    $qty  = isset( $ln['qty'] ) ? (string) $ln['qty'] : '';
                    $label = $this->line_label( $name, $desc );
                    if ( $label === '' ) continue;
                    // v3.5.0 — effective units: QTY × any count embedded in the
                    // line's own text ("(4) units…" with QTY 1 = 4).
                    // When they differ, SAY SO on the line so the bot can't
                    // re-derive the wrong number from the raw qty.
                    $eff = $this->line_effective_count( $name, $desc, $qty );
                    $line = '  • ' . $label;
                    if ( $qty !== '' && $qty !== '0' ) {
                        if ( $eff > 0 && is_numeric( $qty ) && $eff !== (int) $qty ) {
                            $line .= '  (qty: ' . $qty . ' — the line text specifies ' . $eff
                                   . ' units; count it as ' . $eff . ')';
                        } else {
                            $line .= '  (qty: ' . $qty . ')';
                        }
                    }
                    $parts[] = $line;
                    // Sum effective units for lines that look like vent/screen
                    // units (skip the flat "installation" labor line, discounts,
                    // and the receipt-link/location lines).
                    if ( $this->line_is_vent_unit( $label ) ) {
                        $vent_qty_total += $eff;
                    }
                } else {
                    $s = trim( (string) $ln );
                    if ( $s !== '' ) $parts[] = '  • ' . $s;
                }
            }
            if ( $vent_qty_total > 0 ) {
                $noun = zrcpt_unit_noun( true );
                $parts[] = '';
                $parts[] = 'UNIT COUNT: the per-line unit counts above sum to '
                    . $vent_qty_total . ' ' . $noun . ' completed. A count written in the line TEXT '
                    . '(e.g. "(4) …") overrides the bare qty field — that is already reflected in '
                    . 'this total. Use THIS total (not "1") for the "N ' . $noun . '" headline. If a '
                    . 'single flat-price "installation"/labor line also appears, it is the '
                    . 'whole-job labor line — do not add it to the count.';
            }
            // Expose the computed total so ajax_generate can log it (the headline count the bot
            // prints should equal this).
            $this->last_vent_count = $vent_qty_total;
        }

        if ( ! empty( $install_notes ) ) {
            $parts[] = '';
            // v3.5.0 — honest header: when no Nutshell lead exists, the notes
            // below were derived from the FreshBooks document itself, and the
            // bot should treat them as the install details.
            $parts[] = ( $notes_source === 'invoice' )
                ? '=== Install details (read from the FreshBooks invoice — no Nutshell lead was found) ==='
                : '=== Install-day notes from Nutshell ===';
            foreach ( $install_notes as $n ) {
                $body = is_array( $n ) ? ( $n['content'] ?? '' ) : (string) $n;
                if ( $body !== '' ) {
                    $parts[] = '• ' . $body;
                }
            }
        }

        return implode( "\n", $parts );
    }

    /* =====================================================================
       SUPPLEMENTAL MATERIALS (Item Engine bound)

       A job sometimes involves catalog items OTHER than the receipt's primary
       tagged item — accessories, companion products, extra materials. This
       detects them from the job text THROUGH THE ITEM ENGINE (never a hardcoded
       product, brand or part-number list) and asks the bot to document each one
       briefly, pulling any authoritative spec text from a knowledge source via a
       neutral filter.

       NO product, brand, part number, or trade standard is compiled in. With an
       empty catalog (or no Item Engine) match_all() returns nothing, so the whole
       feature degrades to '' — the receipt documents the primary item as usual
       and no supplemental section is emitted.
       ===================================================================== */

    /**
     * Detect catalog items named in the job text that are NOT the primary receipt
     * item (the configured tag). Binds to the Item Engine resolver contract
     * (ZDZ_Item_Engine::match_all — longest-alias-wins) and degrades to an empty
     * result on an empty catalog / absent engine. NO brand or product word is
     * hardcoded here — the vocabulary lives in the catalog.
     *
     * @param string $haystack Combined job text (line items + install/lead notes).
     * @return array{items:array<int,array>,tags:string[]} matched items + human labels.
     */
    private function detect_supplemental_materials( string $haystack ): array {
        $haystack = trim( $haystack );
        if ( $haystack === '' ) {
            return [ 'items' => [], 'tags' => [] ];
        }
        // The primary item this receipt is about — its own lines are the base
        // product, never a "supplemental" material.
        $primary = zrcpt_receipt_item_tag();

        // The Item Engine is the ONLY source of what counts as a known item. No
        // mirrored filter exists for match_all(), so use the static API when
        // available; otherwise there is no catalog to match against → neutral.
        $matched = [];
        if ( class_exists( 'ZDZ_Item_Engine' ) && method_exists( 'ZDZ_Item_Engine', 'match_all' ) ) {
            $found = ZDZ_Item_Engine::match_all( $haystack );
            if ( is_array( $found ) ) {
                $matched = $found;
            }
        }
        if ( empty( $matched ) ) {
            return [ 'items' => [], 'tags' => [] ];
        }

        $items = [];
        $tags  = [];
        $seen  = [];
        foreach ( $matched as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $id = (string) ( $item['id'] ?? '' );
            if ( $id === '' || isset( $seen[ $id ] ) ) {
                continue;
            }
            // Exclude the primary tagged item and anything under it — that is the
            // base product, documented in its own section, not a supplemental one.
            if ( $primary !== '' ) {
                $subtype = (string) ( $item['subtype'] ?? '' );
                if ( $id === $primary || $subtype === $primary || strpos( $id, $primary ) === 0 ) {
                    continue;
                }
            }
            $label = (string) ( $item['display_name'] ?? ( $item['subtype'] ?? $id ) );
            if ( $label === '' ) {
                continue;
            }
            $seen[ $id ] = true;
            $items[]     = $item;
            $tags[]      = $label;
        }

        return [ 'items' => $items, 'tags' => array_values( array_unique( $tags ) ) ];
    }

    /**
     * The canonical material keys for the bot's allowlist: the Item Engine ids of
     * the detected supplemental items. Catalog-driven — NO hardcoded key list.
     *
     * @param array $detected Result of detect_supplemental_materials().
     * @return string[] item ids.
     */
    private function canonical_material_keys( array $detected ): array {
        $keys = [];
        foreach ( (array) ( $detected['items'] ?? [] ) as $item ) {
            $id = is_array( $item ) ? (string) ( $item['id'] ?? '' ) : '';
            if ( $id !== '' ) {
                $keys[] = $id;
            }
        }
        return array_values( array_unique( $keys ) );
    }

    /**
     * Build the "additional items" prompt block for the bot. Detects supplemental
     * catalog items from the job text, pulls any authoritative spec text from a
     * knowledge source via the neutral `zrcpt_materials_context` filter (ships
     * returning '' — a knowledge module registers on it), and instructs the bot to
     * document each detected item briefly and ONLY from supported detail. Returns
     * '' when nothing applies (empty catalog / nothing detected).
     *
     * @param array  $lines         Billing line items.
     * @param array  $install_notes Install notes (array of {content}|string).
     * @param string $extra_text    Any extra text (reference, description).
     */
    private function build_supplemental_materials_block( array $lines, array $install_notes = [], string $extra_text = '' ): string {
        // Assemble the haystack from line items + notes + extra text.
        $parts = [ $extra_text ];
        foreach ( $lines as $ln ) {
            if ( is_array( $ln ) ) {
                $parts[] = (string) ( $ln['name'] ?? '' ) . ' ' . (string) ( $ln['description'] ?? '' );
            } else {
                $parts[] = (string) $ln;
            }
        }
        foreach ( $install_notes as $n ) {
            $parts[] = is_array( $n ) ? (string) ( $n['content'] ?? '' ) : (string) $n;
        }
        $haystack = trim( implode( ' ', array_filter( $parts ) ) );
        if ( $haystack === '' ) {
            return '';
        }

        $detected = $this->detect_supplemental_materials( $haystack );
        if ( empty( $detected['tags'] ) ) {
            return '';
        }

        // Explicit, machine-readable allowlist of what to document (catalog ids).
        $material_keys = $this->canonical_material_keys( $detected );

        // Pull authoritative spec text from a knowledge source. Neutral, documented
        // seam: ships returning '' (feature degrades to a generic mention); a
        // knowledge module registers on the filter. NO provider/plugin name here.
        // Cache keyed on the query, like the other lookups, so re-generating a
        // receipt doesn't re-score the same knowledge on every request.
        $query = 'Supplemental materials specification: ' . implode( ', ', $detected['tags'] )
               . ' approved product specification and applicable standard.';
        $cache_key = 'zrcpt_matctx_' . md5( $query );
        $context   = get_transient( $cache_key );
        if ( ! is_string( $context ) || $context === '' ) {
            $context = (string) apply_filters( 'zrcpt_materials_context', '', $query, $detected );
            if ( $context !== '' ) {
                set_transient( $cache_key, $context, HOUR_IN_SECONDS );
            }
        }

        // Build the block. Even without a knowledge source we still tell the bot
        // WHAT was detected so it can note it conservatively.
        $block  = "\n=== Additional items (not the primary product) ===\n";
        $block .= "ZRCPT_MATERIALS: " . implode( ', ', $material_keys ) . "\n";
        $block .= "(Authoritative detected-items list. Document ONLY these as additional items and "
                . "name no extra manufacturer beyond them. Do NOT infer any product or brand from the "
                . "reference text below — that text is for specifications only.)\n";
        $block .= "This job also involved the following item(s) beyond the primary product, detected "
                . "from the billing line items and the technician's install notes: "
                . implode( '; ', $detected['tags'] ) . ".\n";
        $block .= "Document each one BRIEFLY: its name, any applicable standard or specification the "
                . "reference material below supports, and a one-line reason it was used. Keep it shorter "
                . "than the primary product's write-up.\n";
        $block .= "Use ONLY the authoritative details from the reference material below. If a specific "
                . "product, part number, or standard is not supported there, describe the item generically "
                . "rather than inventing one. Do not show prices on the receipt.\n";

        if ( $context !== '' ) {
            $block .= "--- BEGIN SPEC REFERENCE (specifications only; NOT a list of what was installed) ---\n";
            $block .= $context . "\n";
            $block .= "--- END SPEC REFERENCE ---\n";
        } else {
            $block .= "(No reference material available — describe the detected items generically and "
                    . "conservatively, without specific product numbers or standards.)\n";
        }

        return $block;
    }

    /* =====================================================================
       INVOICE-ONLY FLOW + RECEIPT CROSS-CHECK (v3.3.0)

       Rules:
         • A receipt can ONLY be generated from INVOICES. Estimates are
           proposals — they may appear in lookup as informational, but are
           never selectable and the generate handler hard-refuses them.
         • Cross-check against receipts we already published: each candidate
           invoice is checked against existing 'zrcpt_receipt' posts. An
           invoice already on a receipt is NOT suggested (one invoice → one
           receipt today) but can be used with an explicit override.
         • When several invoices match a customer, the tech can pick one or
           COMBINE several into a single receipt; merged provenance is saved.

       Future-proofing: the receipt↔invoice link is stored as a LIST
       (_fb_doc_numbers) and the cross-check is a query, not a unique
       constraint — so a later "split one invoice across multiple receipts"
       (e.g. property-manager per-unit, or window-screen vs. screen-door
       receipt types) needs no schema change, only relaxing the gate.
       ===================================================================== */

    /**
     * Find an existing published receipt that already references a given
     * FreshBooks invoice/estimate number. Matches both the legacy single
     * _fb_doc_number meta and the v3.3.0 _fb_doc_numbers list (merged receipts).
     *
     * @param string|int $number Invoice/estimate number (any format; normalized to digits).
     * @return array|null { post_id, permalink, title } or null if none.
     */
    public function receipt_for_invoice( $number ) {
        $digits = preg_replace( '/[^0-9]/', '', (string) $number );
        if ( $digits === '' ) { return null; }

        // Legacy single-number meta.
        $q = new \WP_Query( [
            'post_type'      => self::POST_TYPE,
            'post_status'    => [ 'publish', 'private', 'draft' ],
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [
                'relation' => 'OR',
                [ 'key' => '_fb_doc_number', 'value' => $digits, 'compare' => '=' ],
                // The list meta stores each number as its own row (add_post_meta
                // per number), so a plain '=' match also finds merged receipts.
                [ 'key' => '_fb_doc_numbers', 'value' => $digits, 'compare' => '=' ],
            ],
        ] );

        if ( empty( $q->posts ) ) { return null; }
        $pid = (int) $q->posts[0];
        return [
            'post_id'   => $pid,
            'permalink' => get_permalink( $pid ),
            'title'     => get_the_title( $pid ),
        ];
    }

    /**
     * Enrich a list of FreshBooks lookup matches for the invoice-only flow:
     *   • is_invoice    — true for invoices (the only selectable type).
     *   • selectable    — invoices that are NOT already receipted.
     *   • receipted     — { post_id, permalink, title } if already on a receipt.
     *   • suggested     — pre-select hint: unreceipted invoices.
     * Estimates are kept (informational) but marked is_invoice=false /
     * selectable=false with a reason. Sorts invoices first, newest first.
     *
     * @param array $matches Raw matches from ZRCPT_FreshBooks::search().
     * @return array { matches, invoice_count, selectable_count, has_estimates_only }
     */
    public function annotate_matches_for_receipt( array $matches ): array {
        $out = [];
        $invoice_count = 0;
        $selectable_count = 0;

        foreach ( $matches as $m ) {
            $type      = strtolower( (string) ( $m['type'] ?? '' ) );
            $is_invoice = ( $type === 'invoice' );
            $m['is_invoice'] = $is_invoice;

            if ( $is_invoice ) {
                $invoice_count++;
                $existing = $this->receipt_for_invoice( $m['number'] ?? '' );
                if ( $existing ) {
                    $m['receipted']  = $existing;
                    $m['selectable'] = false;            // already receipted → not suggested
                    $m['suggested']  = false;
                    $m['reason']     = 'Already on an installation receipt.';
                } else {
                    $m['receipted']  = null;
                    $m['selectable'] = true;
                    $m['suggested']  = true;             // unreceipted invoice → suggest it
                    $selectable_count++;
                }
            } else {
                // Estimate (or other) — informational only, never selectable.
                $m['receipted']  = null;
                $m['selectable'] = false;
                $m['suggested']  = false;
                $m['reason']     = ( $type === 'estimate' )
                    ? 'Estimate — not yet invoiced. Receipts can only be made from invoices.'
                    : 'Not an invoice — cannot be receipted.';
            }
            $out[] = $m;
        }

        // Sort: invoices first, then by number descending (newest-ish first).
        usort( $out, function ( $a, $b ) {
            $ai = ! empty( $a['is_invoice'] ) ? 0 : 1;
            $bi = ! empty( $b['is_invoice'] ) ? 0 : 1;
            if ( $ai !== $bi ) { return $ai - $bi; }
            $an = (int) preg_replace( '/[^0-9]/', '', (string) ( $a['number'] ?? 0 ) );
            $bn = (int) preg_replace( '/[^0-9]/', '', (string) ( $b['number'] ?? 0 ) );
            return $bn - $an;
        } );

        return [
            'matches'            => $out,
            'invoice_count'      => $invoice_count,
            'selectable_count'   => $selectable_count,
            'has_estimates_only' => ( $invoice_count === 0 && ! empty( $out ) ),
        ];
    }

    /* =====================================================================
       v3.6.0 — APPROVE & SEND
       ---------------------------------------------------------------------
       The receipt generator preps everything; the human who made the receipt
       is the one accountable for it being correct. So before a receipt can go
       to the customer, the signed-in user must PREVIEW it (scroll the whole
       thing) and click "I approve". That click is their sign-off — we capture
       who, when, from what IP/user-agent, and a hash of the EXACT receipt HTML
       they saw, so there's durable providence that they approved THIS content.

       Approving does NOT send (today). Sending is a second, deliberate action
       in History, and is only permitted once a receipt is approved. The
       auto-send-on-approve path is built but gated off behind a filter, so it
       can be switched on later without a rewrite.
       ===================================================================== */

    /**
     * Shared guard for the approve/send/detail endpoints: valid nonce + the
     * same dual-RBAC the rest of the widget uses (WP admin OR a Zorderz
     * app role). Returns the current user id on success; emits JSON error and
     * exits otherwise.
     */
    private function approve_send_guard(): int {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! self::user_can_access() ) { // v3.6.9 (H1): real app-access, not blanket zdz_access_app
            wp_send_json_error( [ 'message' => 'You are not allowed to do that.' ] );
        }
        $uid = get_current_user_id();
        if ( ! $uid ) {
            wp_send_json_error( [ 'message' => 'You must be signed in.' ] );
        }
        return (int) $uid;
    }

    /** Validate that $post_id is one of our receipts; emit error + exit if not. */
    private function require_receipt_post( $post_id ): \WP_Post {
        $post_id = (int) $post_id;
        $post = $post_id ? get_post( $post_id ) : null;
        if ( ! $post || $post->post_type !== self::POST_TYPE ) {
            wp_send_json_error( [ 'message' => 'Receipt not found.' ] );
        }
        // v3.6.9 (H3): per-receipt ownership. Only the creator (or an admin) may
        // read the PII detail of / approve / send / mutate this receipt, so an
        // authorized user can't act on another user's receipt by post_id.
        if ( ! self::current_user_can_manage_receipt( (int) $post->ID ) ) {
            wp_send_json_error( [ 'message' => 'You are not allowed to act on this receipt.' ], 403 );
        }
        return $post;
    }

    /** Best-effort client IP (handles common proxy headers, validates format). */
    private function client_ip(): string {
        $keys = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ];
        foreach ( $keys as $k ) {
            if ( empty( $_SERVER[ $k ] ) ) { continue; }
            // X-Forwarded-For may be a comma list; the first entry is the client.
            $raw = explode( ',', (string) $_SERVER[ $k ] )[0];
            $ip  = trim( $raw );
            if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) { return $ip; }
        }
        return '';
    }

    /**
     * Read the current approval state of a receipt into a plain array the JS
     * can branch on. `approved` and `sent` are the two booleans the UI cares
     * about; the rest is the human-readable audit detail.
     */
    public function get_approval_state( int $post_id ): array {
        $approved_at = (string) get_post_meta( $post_id, '_approved_at', true );
        $sent_at     = (string) get_post_meta( $post_id, '_sent_at', true );
        $by_name     = (string) get_post_meta( $post_id, '_approved_by_name', true );

        $approved = ( $approved_at !== '' );
        $sent     = ( $sent_at !== '' );

        // "Approved by you" if the current user is the approver — a small nicety
        // for the History list.
        $by_uid   = (int) get_post_meta( $post_id, '_approved_by_user_id', true );
        $is_me    = ( $by_uid && $by_uid === get_current_user_id() );

        // v3.8.0 — when a SENT receipt is redone, ajax_generate archives the old
        // send timestamp here. Non-empty means: an OLDER version reached the
        // customer, and the current (redone) version has NOT gone out yet.
        $prev_sent = (string) get_post_meta( $post_id, '_prev_sent_at', true );

        return [
            'approved'        => $approved,
            'approved_at'     => $approved_at ? mysql2date( 'M j, Y g:i a', $approved_at ) : '',
            'approved_by'     => $by_name,
            'approved_by_me'  => $is_me,
            'sent'            => $sent,
            'sent_at'         => $sent_at ? mysql2date( 'M j, Y g:i a', $sent_at ) : '',
            'prev_sent_at'    => ( ! $sent && $prev_sent !== '' ) ? mysql2date( 'M j, Y g:i a', $prev_sent ) : '',
            // Whether we even CAN send: a verified customer email must exist.
            'can_send'        => ( get_post_meta( $post_id, '_customer_email', true ) !== '' ),
        ];
    }

    /**
     * AJAX: zrcpt_receipt_detail
     * Returns one receipt's stored HTML (for the in-modal preview the approver
     * must scroll through) plus its current approval/send state and a few
     * display fields. The receipt HTML is the saved `_receipt_html` — exactly
     * what the customer-facing page renders — so the approver reviews the real
     * thing. The customer email is deliberately NOT included.
     */
    public function ajax_receipt_detail() {
        $this->approve_send_guard();
        $post = $this->require_receipt_post( $_POST['post_id'] ?? 0 );
        $post_id = (int) $post->ID;

        $html = (string) get_post_meta( $post_id, '_receipt_html', true );

        wp_send_json_success( [
            'post_id'     => $post_id,
            'title'       => get_the_title( $post_id ),
            'address'     => (string) get_post_meta( $post_id, '_address_short', true ),
            'install_date'=> (string) get_post_meta( $post_id, '_install_date', true ),
            'permalink'   => get_permalink( $post_id ),
            'html'        => $html,
            // v3.6.4 — the ordered photo URLs in this receipt, so the Review &
            // Approve UI can show a removable thumbnail for each (the source of
            // truth is the receipt's own JS images[] array; see extract).
            'photos'      => $this->extract_receipt_photos( $html ),
            'state'       => $this->get_approval_state( $post_id ),
        ] );
    }

    /**
     * AJAX: zrcpt_remove_photos  (v3.6.4)
     * Remove one or more photos from a receipt during Review & Approve. The
     * reviewer taps the ✕ on a photo in the preview and confirms; we drop that
     * image from the stored _receipt_html (gallery items + the JS images[] array
     * + the "View N Installation Photos" count) and from _source_media_ids, then
     * re-save. This is receipt-only — the photo stays in the shared media
     * library. Editing the HTML naturally changes its hash, so any prior
     * approval is invalidated and the reviewer re-approves the updated receipt
     * (the modal reloads the fresh version and re-arms the gate).
     *
     * Input: post_id, urls[] (the exact image src values to remove). We match by
     * URL so re-indexing is unambiguous even if the client's order drifted.
     */
    public function ajax_remove_photos() {
        $this->approve_send_guard();
        $post = $this->require_receipt_post( $_POST['post_id'] ?? 0 );
        $post_id = (int) $post->ID;

        // Accept urls as a JSON array or a urls[] form array.
        $remove = [];
        if ( isset( $_POST['urls'] ) && is_array( $_POST['urls'] ) ) {
            $remove = array_map( 'strval', wp_unslash( $_POST['urls'] ) );
        } elseif ( isset( $_POST['urls'] ) ) {
            $decoded = json_decode( wp_unslash( (string) $_POST['urls'] ), true );
            if ( is_array( $decoded ) ) { $remove = array_map( 'strval', $decoded ); }
        }
        // Normalize: trim, drop empties, de-dupe.
        $remove = array_values( array_unique( array_filter( array_map( 'trim', $remove ) ) ) );
        if ( empty( $remove ) ) {
            wp_send_json_error( [ 'message' => 'No photos were selected to remove.' ] );
        }

        $html = (string) get_post_meta( $post_id, '_receipt_html', true );
        if ( $html === '' ) {
            wp_send_json_error( [ 'message' => 'This receipt has no content.' ] );
        }

        $current = $this->extract_receipt_photos( $html );
        if ( empty( $current ) ) {
            wp_send_json_error( [ 'message' => 'No photos found on this receipt.' ] );
        }

        // The set to keep, in original order.
        $remove_set = array_fill_keys( $remove, true );
        $kept = array_values( array_filter( $current, function ( $u ) use ( $remove_set ) {
            return ! isset( $remove_set[ $u ] );
        } ) );

        $removed_count = count( $current ) - count( $kept );
        if ( $removed_count <= 0 ) {
            wp_send_json_error( [ 'message' => 'Those photos are not on this receipt (it may have changed). Please reopen and try again.' ] );
        }
        // Guard: never strip the gallery to nothing — a receipt with zero photos
        // reads as broken. Require at least one to remain.
        if ( empty( $kept ) ) {
            wp_send_json_error( [ 'message' => 'You can’t remove every photo — a receipt needs at least one. Leave one photo, or regenerate the receipt instead.' ] );
        }

        // Rebuild the three photo-bearing regions of the stored HTML from $kept.
        $new_html = $this->rewrite_receipt_photos( $html, $kept );
        if ( $new_html === null || $new_html === $html ) {
            wp_send_json_error( [ 'message' => 'Could not update the receipt photos. Please regenerate the receipt.' ] );
        }

        update_post_meta( $post_id, '_receipt_html', $new_html );

        // Keep _source_media_ids consistent: drop the rows whose file_url was
        // removed (best-effort — these ids power provenance, not the rendered
        // gallery, so a mismatch is harmless but we keep them tidy).
        $this->prune_source_media_ids( $post_id, $remove );

        // Removing content changes the approval surface. Clear any prior approval
        // so the reviewer must re-approve the updated receipt (the modal reloads
        // the fresh HTML and re-arms the scroll/checkbox gate, exactly as for a
        // regenerated receipt). We keep the _approval_log history intact.
        $this->invalidate_approval( $post_id );

        error_log( sprintf(
            'ZRCPT REMOVE PHOTOS: post_id=%d removed=%d kept=%d',
            $post_id, $removed_count, count( $kept )
        ) );

        wp_send_json_success( [
            'post_id'       => $post_id,
            'html'          => $new_html,
            'photos'        => $this->extract_receipt_photos( $new_html ),
            'removed_count' => $removed_count,
            'state'         => $this->get_approval_state( $post_id ),
        ] );
    }

    /**
     * AJAX: zrcpt_reorder_photos  (v3.6.6)
     * Reorder the photos on a receipt during Review & Approve. The reviewer drags
     * a photo to a new position in the receipt preview; we receive the FULL list
     * of photo URLs in their new order and rebuild the stored _receipt_html in
     * that order (gallery items + lightbox images[] + re-indexed openLightbox()).
     * This is the same deterministic rewrite the remove path uses — just a
     * different ordering of the same set.
     *
     * The submitted list MUST be a permutation of the receipt's current photos
     * (same set, no additions/removals) — reordering never adds or drops a photo.
     * Like every content edit, it invalidates any prior approval so the reviewer
     * re-approves the updated receipt.
     *
     * Input: post_id, order[] (every current photo URL, in the new sequence).
     */
    public function ajax_reorder_photos() {
        $this->approve_send_guard();
        $post = $this->require_receipt_post( $_POST['post_id'] ?? 0 );
        $post_id = (int) $post->ID;

        // Accept order as a JSON array or an order[] form array.
        $order = [];
        if ( isset( $_POST['order'] ) && is_array( $_POST['order'] ) ) {
            $order = array_map( 'strval', wp_unslash( $_POST['order'] ) );
        } elseif ( isset( $_POST['order'] ) ) {
            $decoded = json_decode( wp_unslash( (string) $_POST['order'] ), true );
            if ( is_array( $decoded ) ) { $order = array_map( 'strval', $decoded ); }
        }
        $order = array_values( array_filter( array_map( 'trim', $order ) ) );
        if ( empty( $order ) ) {
            wp_send_json_error( [ 'message' => 'No photo order was provided.' ] );
        }

        $html = (string) get_post_meta( $post_id, '_receipt_html', true );
        if ( $html === '' ) {
            wp_send_json_error( [ 'message' => 'This receipt has no content.' ] );
        }

        $current = $this->extract_receipt_photos( $html );
        if ( empty( $current ) ) {
            wp_send_json_error( [ 'message' => 'No photos found on this receipt.' ] );
        }

        // The submitted order must be a PERMUTATION of the current photos: the
        // same set, just rearranged. This rejects a stale/mismatched client
        // (e.g. a photo was removed in another tab) and guarantees reordering
        // never silently adds or drops a photo. Compare as multisets via sort.
        $a = $current; $b = $order;
        sort( $a ); sort( $b );
        if ( $a !== $b ) {
            wp_send_json_error( [ 'message' => 'The photo set changed since you opened it. Please reopen the receipt and try again.' ] );
        }

        // No-op if the order didn't actually change.
        if ( $order === $current ) {
            wp_send_json_success( [
                'post_id'   => $post_id,
                'html'      => $html,
                'photos'    => $current,
                'reordered' => false,
                'state'     => $this->get_approval_state( $post_id ),
            ] );
        }

        // Rebuild the photo regions in the new order (same rewrite as remove).
        $new_html = $this->rewrite_receipt_photos( $html, $order );
        if ( $new_html === null || $new_html === $html ) {
            wp_send_json_error( [ 'message' => 'Could not reorder the receipt photos. Please regenerate the receipt.' ] );
        }

        update_post_meta( $post_id, '_receipt_html', $new_html );

        // Reordering changes the approval surface (the order of photos the
        // approver signs off on), so clear any prior approval — consistent with
        // removal. The modal reloads the fresh HTML and re-arms the gate.
        $this->invalidate_approval( $post_id );

        error_log( sprintf(
            'ZRCPT REORDER PHOTOS: post_id=%d count=%d',
            $post_id, count( $order )
        ) );

        wp_send_json_success( [
            'post_id'   => $post_id,
            'html'      => $new_html,
            'photos'    => $this->extract_receipt_photos( $new_html ),
            'reordered' => true,
            'state'     => $this->get_approval_state( $post_id ),
        ] );
    }

    /**
     * v3.6.4 — Extract the ordered list of installation-photo URLs from a stored
     * receipt HTML. The canonical source is the receipt's own lightbox array
     * (`const images = [ 'a', 'b', ... ];`), which is exactly what the gallery
     * renders and what the customer page uses. Falls back to scraping the
     * .gallery <img src> values if the array isn't found.
     *
     * @param string $html Stored _receipt_html.
     * @return string[] Photo URLs, in order.
     */
    private function extract_receipt_photos( string $html ): array {
        if ( $html === '' ) { return []; }

        // Primary: the JS images array.
        if ( preg_match( '/const\s+images\s*=\s*\[(.*?)\]\s*;/s', $html, $m ) ) {
            $inner = $m[1];
            // Capture single- or double-quoted URL string literals.
            if ( preg_match_all( '/([\'"])(.*?)\1/s', $inner, $um ) ) {
                $urls = array_map( function ( $u ) { return html_entity_decode( $u ); }, $um[2] );
                $urls = array_values( array_filter( array_map( 'trim', $urls ) ) );
                if ( ! empty( $urls ) ) { return $urls; }
            }
        }

        // Fallback: gallery <img src> values.
        if ( preg_match( '/<div class="gallery">(.*?)<\/div>\s*<div class="gallery-note">/s', $html, $gm ) ) {
            if ( preg_match_all( '/<img[^>]+src="([^"]+)"/i', $gm[1], $im ) ) {
                return array_values( array_filter( array_map( 'trim', array_map( 'html_entity_decode', $im[1] ) ) ) );
            }
        }
        return [];
    }

    /**
     * v3.6.4 — Rewrite the photo-bearing regions of a stored receipt HTML so it
     * shows exactly $kept (ordered). Rebuilds: (1) the .gallery items with
     * correctly re-indexed openLightbox(i) handlers, (2) the JS `const images`
     * array, and (3) the "View N Installation Photos" count in the CTA. Returns
     * the new HTML, or null if the gallery couldn't be located.
     *
     * @param string   $html Stored HTML.
     * @param string[] $kept Ordered URLs to keep.
     * @return string|null
     */
    private function rewrite_receipt_photos( string $html, array $kept ): ?string {
        // (1) Rebuild the gallery items. Mirror the bot's build_gallery_html
        // markup exactly (div.gallery-item[onclick=openLightbox(i)] > img).
        $items = '';
        foreach ( array_values( $kept ) as $i => $url ) {
            $u = esc_url( $url );
            $items .= '            <div class="gallery-item" onclick="openLightbox(' . $i . ')">' . "\n";
            $items .= '                <img src="' . $u . '" alt="Installation photo ' . ( $i + 1 ) . '" loading="eager">' . "\n";
            $items .= '            </div>' . "\n";
        }
        $gallery_new = '<div class="gallery">' . "\n" . $items . '        </div>';
        $replaced = preg_replace(
            '/<div class="gallery">.*?<\/div>(\s*<div class="gallery-note">)/s',
            // Preserve the trailing gallery-note opener captured in group 1.
            $this->pcre_safe( $gallery_new ) . '$1',
            $html,
            1,
            $count_gallery
        );
        if ( $replaced === null || ! $count_gallery ) {
            return null;
        }
        $html = $replaced;

        // (2) Rebuild the JS images array.
        $entries = implode( ",\n            ", array_map( function ( $u ) {
            // Single-quoted JS string; escape backslashes and single quotes.
            return "'" . str_replace( [ '\\', "'" ], [ '\\\\', "\\'" ], $u ) . "'";
        }, array_values( $kept ) ) );
        $images_new = 'const images = [' . "\n            " . $entries . "\n        ];";
        $html = preg_replace(
            '/const\s+images\s*=\s*\[.*?\]\s*;/s',
            $this->pcre_safe( $images_new ),
            $html,
            1
        );

        // (3) Update the photo-count in the CTA: "View N Installation Photos".
        $n = count( $kept );
        $html = preg_replace(
            '/(View\s+)\d+(\s+Installation Photos)/',
            '${1}' . $n . '${2}',
            $html,
            1
        );

        return $html;
    }

    /** Escape a replacement string for use in preg_replace (only `$` and `\`). */
    private function pcre_safe( string $replacement ): string {
        return str_replace( [ '\\', '$' ], [ '\\\\', '\\$' ], $replacement );
    }

    /**
     * v3.6.4 — Drop removed photos from _source_media_ids by resolving each
     * stored ZDZ_User_Media row's file_url and matching the removed URLs. Pure
     * housekeeping (provenance), never fatal.
     *
     * @param int      $post_id
     * @param string[] $removed_urls
     */
    private function prune_source_media_ids( int $post_id, array $removed_urls ): void {
        $ids = get_post_meta( $post_id, '_source_media_ids', true );
        if ( ! is_array( $ids ) || empty( $ids ) ) { return; }
        if ( ! class_exists( 'ZDZ_User_Media' ) || ! method_exists( 'ZDZ_User_Media', 'get_by_id' ) ) {
            return; // can't resolve urls → leave ids as-is (harmless)
        }
        $removed_set = array_fill_keys( array_map( 'trim', $removed_urls ), true );
        $kept_ids = [];
        foreach ( $ids as $mid ) {
            $row = ZDZ_User_Media::get_by_id( (int) $mid );
            $url = is_array( $row ) ? trim( (string) ( $row['file_url'] ?? '' ) ) : '';
            // Keep the id unless its file_url was one of the removed photos.
            if ( $url !== '' && isset( $removed_set[ $url ] ) ) { continue; }
            $kept_ids[] = (int) $mid;
        }
        update_post_meta( $post_id, '_source_media_ids', array_values( array_unique( $kept_ids ) ) );
    }

    /**
     * v3.6.4 — Invalidate any existing approval (used after the receipt content
     * changes, e.g. photos removed). Clears the approval fields so the receipt
     * reads as "Needs approval" and cannot be sent until re-approved, while
     * preserving the _approval_log history.
     *
     * @param int $post_id
     */
    private function invalidate_approval( int $post_id ): void {
        foreach ( [
            '_approved_by_user_id', '_approved_by_name', '_approved_by_email',
            '_approved_at', '_approved_ip', '_approved_user_agent', '_approved_html_hash',
        ] as $key ) {
            delete_post_meta( $post_id, $key );
        }
    }

    /**
     * AJAX: zrcpt_approve_receipt
     * Records the signed-in user's affirmative sign-off on a receipt. The
     * client must echo back the hash of the receipt HTML it actually displayed
     * (a scroll-gated preview + an explicit "I have read and approved this"
     * checkbox), and we verify it against the stored HTML so the approval is
     * bound to THIS content — if the receipt is regenerated later, the old
     * approval no longer matches and must be re-done.
     */
    public function ajax_approve_receipt() {
        $uid  = $this->approve_send_guard();
        $post = $this->require_receipt_post( $_POST['post_id'] ?? 0 );
        $post_id = (int) $post->ID;

        // The affirmative-consent flag the UI sets only when BOTH the scroll-to-
        // end gate and the confirmation checkbox are satisfied. Defense in depth:
        // the button is disabled until then client-side; we re-require it here.
        $confirmed = isset( $_POST['confirm'] ) && in_array( (string) $_POST['confirm'], [ '1', 'true', 'on', 'yes' ], true );
        if ( ! $confirmed ) {
            wp_send_json_error( [ 'message' => 'Please confirm you have read and approved the receipt.' ] );
        }

        $html = (string) get_post_meta( $post_id, '_receipt_html', true );
        if ( $html === '' ) {
            wp_send_json_error( [ 'message' => 'This receipt has no content to approve.' ] );
        }
        $server_hash = hash( 'sha256', $html );

        // Bind the approval to the exact content the user saw. The client sends
        // the hash of the HTML it rendered; it must match what we have stored.
        $client_hash = isset( $_POST['html_hash'] ) ? preg_replace( '/[^a-f0-9]/i', '', (string) $_POST['html_hash'] ) : '';
        if ( $client_hash === '' || ! hash_equals( $server_hash, strtolower( $client_hash ) ) ) {
            wp_send_json_error( [ 'message' => 'The receipt changed since you opened it. Please re-open and review it again.' ] );
        }

        $user = get_userdata( $uid );
        $name = $user ? ( $user->display_name ?: $user->user_login ) : ( 'User #' . $uid );
        $now  = current_time( 'mysql' );

        update_post_meta( $post_id, '_approved_by_user_id', $uid );
        update_post_meta( $post_id, '_approved_by_name',    sanitize_text_field( $name ) );
        update_post_meta( $post_id, '_approved_by_email',   sanitize_email( (string) ( $user->user_email ?? '' ) ) );
        update_post_meta( $post_id, '_approved_at',         $now );
        update_post_meta( $post_id, '_approved_ip',         $this->client_ip() );
        update_post_meta( $post_id, '_approved_user_agent', sanitize_text_field( substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 300 ) ) );
        update_post_meta( $post_id, '_approved_html_hash',  $server_hash );

        // Append to an immutable-ish approval log (every approval event, in case
        // a regenerate forces a re-approval — we keep the full history).
        $log = get_post_meta( $post_id, '_approval_log', true );
        if ( ! is_array( $log ) ) { $log = []; }
        $log[] = [
            'user_id'    => $uid,
            'name'       => $name,
            'at'         => $now,
            'ip'         => $this->client_ip(),
            'html_hash'  => $server_hash,
        ];
        update_post_meta( $post_id, '_approval_log', $log );

        error_log( sprintf(
            'ZRCPT APPROVE: post_id=%d approved_by=%s (uid %d) at=%s ip=%s hash=%s',
            $post_id, $name, $uid, $now, $this->client_ip(), substr( $server_hash, 0, 12 )
        ) );

        // v3.6.0 — Auto-send-on-approve is intentionally GATED OFF. A human has
        // approved, so an automated system MAY take over — but today the shop
        // wants send to stay a separate, deliberate click. Flip this filter to
        // true (per-receipt $post_id available) to enable auto-send later; the
        // send path below is already production-ready.
        $auto_sent       = null;  // null unless the gated auto-send fired
        $auto_send_error = '';
        if ( apply_filters( 'zrcpt_auto_send_on_approve', false, $post_id ) ) {
            $auto_result = $this->deliver_receipt_email( $post_id, $uid, true );
            if ( is_wp_error( $auto_result ) ) {
                $auto_sent       = false;
                $auto_send_error = $auto_result->get_error_message();
                error_log( 'ZRCPT AUTO-SEND failed for post ' . $post_id . ': ' . $auto_send_error );
            } else {
                $auto_sent = true;
            }
        }

        wp_send_json_success( [
            'message'         => 'Receipt approved. You can now send it to the customer.',
            'state'           => $this->get_approval_state( $post_id ),
            'auto_sent'       => $auto_sent,        // true | false | null
            'auto_send_error' => $auto_send_error,  // '' unless auto-send was on and failed
        ] );
    }

    /**
     * AJAX: zrcpt_send_receipt
     * Emails the customer the link to their finished receipt. Hard requirements:
     *   1) the receipt is APPROVED, and the approval still matches current HTML;
     *   2) a VERIFIED customer email (from FreshBooks/Nutshell) exists.
     * The approver never sees or types the address — we use only the verified
     * one captured at generation time.
     */
    public function ajax_send_receipt() {
        $uid  = $this->approve_send_guard();
        $post = $this->require_receipt_post( $_POST['post_id'] ?? 0 );
        $post_id = (int) $post->ID;

        $result = $this->deliver_receipt_email( $post_id, $uid, false );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }
        wp_send_json_success( [
            'message' => 'Receipt sent to the customer.',
            'state'   => $this->get_approval_state( $post_id ),
        ] );
    }

    /**
     * Core send routine, shared by the manual Send button and the (gated)
     * auto-send-on-approve path. Enforces the approve-first and verified-email
     * rules, sends via wp_mail() using the SAME From identity and HTML content
     * type as the satisfaction-survey emails, logs the send, and mirrors it to
     * Nutshell when a lead id is known.
     *
     * @param int  $post_id Receipt post id.
     * @param int  $uid     Acting user id (the approver / sender).
     * @param bool $is_auto Whether this is the gated auto-send path.
     * @return true|\WP_Error
     */
    private function deliver_receipt_email( int $post_id, int $uid, bool $is_auto = false ) {
        // ── 1) Must be approved, and the approval must still match the HTML ──
        $approved_at = (string) get_post_meta( $post_id, '_approved_at', true );
        if ( $approved_at === '' ) {
            return new \WP_Error( 'not_approved', 'Approve the receipt before sending it.' );
        }
        $html = (string) get_post_meta( $post_id, '_receipt_html', true );
        $approved_hash = (string) get_post_meta( $post_id, '_approved_html_hash', true );
        if ( $html === '' ) {
            return new \WP_Error( 'no_html', 'This receipt has no content to send.' );
        }
        // Always verify the approval is bound to the CURRENT content. If the
        // stored hash is missing (should never happen — approval writes it
        // atomically) OR it no longer matches, treat the approval as stale and
        // require a fresh review. Fail closed: never send content the human
        // didn't actually sign off on.
        if ( $approved_hash === '' || ! hash_equals( $approved_hash, hash( 'sha256', $html ) ) ) {
            return new \WP_Error( 'stale_approval', 'This receipt changed after it was approved. Please review and approve it again before sending.' );
        }

        // ── 2) Verified customer email only (never typed, never from client) ──
        $to = sanitize_email( (string) get_post_meta( $post_id, '_customer_email', true ) );
        if ( $to === '' || ! is_email( $to ) ) {
            return new \WP_Error( 'no_email', 'No verified customer email is on file for this job (from FreshBooks/Nutshell), so it cannot be sent automatically.' );
        }

        $first_name = $this->customer_first_name( (string) get_post_meta( $post_id, '_customer_name', true ) );
        $address    = (string) get_post_meta( $post_id, '_address_short', true );
        $receipt_url= get_permalink( $post_id );

        $subject = 'Your installation receipt' . ( $address !== '' ? ' — ' . $address : '' );
        $body    = $this->build_receipt_email_html( $first_name, $address, $receipt_url );

        // ── 3) Send via wp_mail() — same From + HTML content type as Surveys ──
        $headers = [
            'From: ' . $this->receipt_from_name() . ' <' . $this->receipt_from_email() . '>',
            'Content-Type: text/html; charset=UTF-8',
        ];

        // Ensure HTML content type even if another plugin filters it; mirror the
        // surveys app's belt-and-suspenders approach.
        $force_html = function () { return 'text/html'; };
        add_filter( 'wp_mail_content_type', $force_html, 99 );
        $sent = wp_mail( $to, $subject, $body, $headers );
        remove_filter( 'wp_mail_content_type', $force_html, 99 );

        if ( ! $sent ) {
            error_log( 'ZRCPT SEND FAIL: post_id=' . $post_id . ' to=' . $to );
            return new \WP_Error( 'mail_failed', 'The email could not be sent. Please check the mail configuration and try again.' );
        }

        // ── 4) Log the send (audit) ──
        $now  = current_time( 'mysql' );
        $user = get_userdata( $uid );
        $name = $user ? ( $user->display_name ?: $user->user_login ) : ( 'User #' . $uid );

        update_post_meta( $post_id, '_sent_at', $now );
        update_post_meta( $post_id, '_sent_to', $to );
        update_post_meta( $post_id, '_sent_by_user_id', $uid );
        update_post_meta( $post_id, '_sent_by_name', sanitize_text_field( $name ) );
        // v3.8.0 — the redone version has now gone out; clear the "previously
        // sent, redo pending" marker (full history stays in _send_log).
        delete_post_meta( $post_id, '_prev_sent_at' );

        $send_log = get_post_meta( $post_id, '_send_log', true );
        if ( ! is_array( $send_log ) ) { $send_log = []; }
        $send_log[] = [
            'at'      => $now,
            'to'      => $to,
            'by'      => $name,
            'by_uid'  => $uid,
            'auto'    => $is_auto,
        ];
        update_post_meta( $post_id, '_send_log', $send_log );

        error_log( sprintf(
            'ZRCPT SEND: post_id=%d to=%s by=%s (uid %d) auto=%s at=%s (send #%d)',
            $post_id, $to, $name, $uid, $is_auto ? 'yes' : 'no', $now, count( $send_log )
        ) );

        // ── 5) Mirror to Nutshell (best-effort; non-critical) ──
        $this->log_send_to_nutshell( $post_id, $to, $subject, $now, count( $send_log ), $is_auto );

        return true;
    }

    /**
     * From-name for receipt emails — the business's document-sender identity, from
     * ZDZ_Business_Profile (senders.documents → default → business name). NO person or
     * company name is compiled in; a tenant sets it in Business Profile settings.
     */
    private function receipt_from_name(): string {
        $name = '';
        if ( class_exists( 'ZDZ_Business_Profile' ) ) {
            $sender = ZDZ_Business_Profile::sender( 'documents' );
            $name   = (string) ( $sender['name'] ?? '' );
        }
        if ( $name === '' ) {
            $name = get_bloginfo( 'name' );
        }
        return (string) apply_filters( 'zrcpt_email_from_name', $name );
    }

    /** From-address for receipt emails — the business's document-sender address. */
    private function receipt_from_email(): string {
        $email = '';
        if ( class_exists( 'ZDZ_Business_Profile' ) ) {
            $sender = ZDZ_Business_Profile::sender( 'documents' );
            $email  = (string) ( $sender['email'] ?? '' );
        }
        if ( $email === '' ) {
            $email = (string) get_option( 'admin_email' );
        }
        return (string) apply_filters( 'zrcpt_email_from_email', $email );
    }

    /** First-name extraction for a friendly greeting; falls back to "there". */
    private function customer_first_name( string $full_name ): string {
        $full_name = trim( $full_name );
        if ( $full_name === '' ) { return 'there'; }
        $parts = preg_split( '/\s+/', $full_name );
        return $parts[0] !== '' ? $parts[0] : 'there';
    }

    /**
     * Branded HTML email announcing the customer's receipt is ready, with a button to view it.
     * ALL identity is neutral/from ZDZ_Business_Profile: the header wordmark + signature are
     * the business name, the header/button colours derive from the brand ramp, and the sender
     * line is the document-sender name. NO product, person or company name is compiled in — an
     * unconfigured install renders the site name and the default ramp, and still reads cleanly.
     */
    private function build_receipt_email_html( string $first_name, string $address, string $receipt_url ): string {
        $url   = esc_url( $receipt_url );
        $fname = esc_html( $first_name );
        $addr  = $address !== '' ? esc_html( $address ) : '';

        // Identity + palette from Business Profile (neutral fallbacks).
        $biz_name  = class_exists( 'ZDZ_Business_Profile' ) ? ZDZ_Business_Profile::name() : get_bloginfo( 'name' );
        $sign_name = $this->receipt_from_name();
        $header_bg = class_exists( 'ZDZ_Business_Profile' ) ? (string) ZDZ_Business_Profile::get( 'brand.ramp.600', '#1E3A5F' ) : '#1E3A5F';
        $btn_bg    = class_exists( 'ZDZ_Business_Profile' ) ? (string) ZDZ_Business_Profile::get( 'brand.ramp.500', '#2C5F8A' ) : '#2C5F8A';
        $biz_name  = esc_html( $biz_name );
        $sign_name = esc_html( $sign_name );
        $header_bg = esc_attr( $header_bg );
        $btn_bg    = esc_attr( $btn_bg );

        $addr_line = $addr !== ''
            ? '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#333333;">Your work at <strong>' . $addr . '</strong> is complete, and your receipt is ready.</p>'
            : '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#333333;">Your work is complete, and your receipt is ready.</p>';

        return '<!DOCTYPE html>
<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
@media (prefers-color-scheme: dark) {
  .zrcpt-email-wrapper { background-color:#1a1a1a !important; }
  .zrcpt-email-card { background-color:#2a2a2a !important; border-color:#3a3a3a !important; }
  .zrcpt-email-body { color:#e0e0e0 !important; }
  .zrcpt-email-body p { color:#e0e0e0 !important; }
  .zrcpt-email-sig-name { color:#e0e0e0 !important; }
  .zrcpt-email-sig { color:#b0b0b0 !important; }
}
</style>
</head>
<body style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;">
<table class="zrcpt-email-wrapper" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f5f5f5;">
<tr><td align="center" style="padding:30px 15px;">
<table class="zrcpt-email-card" width="600" cellpadding="0" cellspacing="0" border="0" style="background-color:#ffffff;border-radius:8px;border:1px solid #e0e0e0;">

<!-- Header -->
<tr><td class="zrcpt-email-header" style="background-color:' . $header_bg . ';padding:24px 30px;border-radius:8px 8px 0 0;">
<h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:600;">' . $biz_name . '</h1>
</td></tr>

<!-- Body -->
<tr><td class="zrcpt-email-body" style="padding:30px;color:#333333;">
<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#333333;">Hi ' . $fname . ',</p>
' . $addr_line . '
<p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#333333;">You can view and save your receipt here:</p>

<!-- CTA Button -->
<table cellpadding="0" cellspacing="0" border="0" style="margin:0 auto 24px;">
<tr><td align="center" style="background-color:' . $btn_bg . ';border-radius:8px;">
<a href="' . $url . '" target="_blank" style="display:inline-block;padding:14px 32px;color:#ffffff;font-size:16px;font-weight:700;text-decoration:none;letter-spacing:0.5px;">
View your receipt
</a>
</td></tr>
</table>

<p style="margin:0 0 8px;font-size:14px;line-height:1.6;color:#666666;">If the button doesn\'t work, copy and paste this link into your browser:<br><a href="' . $url . '" style="color:' . $header_bg . ';">' . $url . '</a></p>

<p style="margin:16px 0 8px;font-size:15px;line-height:1.6;color:#333333;">Thank you for your business,</p>
<p style="margin:0;font-size:15px;line-height:1.6;">
<span class="zrcpt-email-sig-name" style="font-weight:600;color:#333333;">' . $sign_name . '</span>
</p>
</td></tr>

</table>
</td></tr></table>
</body></html>';
    }

    /**
     * Record the receipt send in the WordPress audit log (post meta). The full
     * providence trail — who approved, when, from what IP/UA, the approved-HTML
     * hash, and every send event — lives on the receipt post itself, which is
     * durable and self-contained.
     *
     * NOTE on Nutshell: the Receipts plugin reads from Nutshell (to look up
     * the job) but deliberately does NOT write back to it. The two plugins are
     * kept independent and must not share write paths. If a sanctioned Nutshell
     * note API is added to ZRCPT_Nutshell in the future, mirror the send here by
     * resolving the lead via $ns->find_lead_for_customer([...]) and posting a
     * note — guarded by method_exists so it stays a no-op until then.
     */
    private function log_send_to_nutshell( int $post_id, string $to, string $subject, string $now, int $send_count, bool $is_auto ): void {
        // Intentionally a no-op today: the WP post-meta audit log (_send_log,
        // _sent_at, _sent_to, _sent_by_name, plus the _approval_log) is the
        // system of record for send/approval providence. Left as a clearly named
        // seam so a future, sanctioned CRM mirror has an obvious home.
        if ( ! class_exists( 'ZRCPT_Nutshell' ) ) { return; }
        if ( ! method_exists( 'ZRCPT_Nutshell', 'new_note' ) ) { return; }
        try {
            $ns = new ZRCPT_Nutshell();
            if ( ! method_exists( $ns, 'is_ready' ) || ! $ns->is_ready() ) { return; }
            $name = (string) get_post_meta( $post_id, '_customer_name', true );
            $lead = $ns->find_lead_for_customer( [ 'name' => $name, 'email' => $to ] );
            $lead_id = is_array( $lead ) ? (int) ( $lead['id'] ?? 0 ) : 0;
            if ( ! $lead_id ) { return; }

            $approver    = (string) get_post_meta( $post_id, '_approved_by_name', true );
            $approved_at = (string) get_post_meta( $post_id, '_approved_at', true );
            $url         = get_permalink( $post_id );

            $note  = "[Receipt] Installation receipt sent\n";
            $note .= "ââââââââââââ\n";
            $note .= "To: {$to}\n";
            $note .= "Subject: {$subject}\n";
            $note .= "Sent: {$now}" . ( $is_auto ? " (auto)" : "" ) . "\n";
            if ( $approver !== '' ) {
                $note .= "Approved by: {$approver}" . ( $approved_at !== '' ? " at {$approved_at}" : '' ) . "\n";
            }
            if ( $send_count > 1 ) {
                $note .= "This is send #{$send_count} for this receipt\n";
            }
            $note .= "Receipt: {$url}\n";

            $ns->new_note( [ 'entityType' => 'Leads', 'id' => $lead_id ], $note );
        } catch ( \Throwable $e ) {
            error_log( 'ZRCPT: Nutshell send-note (optional) skipped for post ' . $post_id . ': ' . $e->getMessage() );
        }
    }

    /* =====================================================================
       v3.9.0 — MANAGE RECEIPTS (ADMIN TABLE) + DELETION-REQUEST WORKFLOW

       Two roles, two paths:
         • ADMIN (manage_options): a backend table of every receipt — status,
           invoices, customer, creator, link — with Delete (→ Trash, so it's
           recoverable), Restore, Delete-forever, and Regenerate-link. Trashing
           kills the share link immediately (both the token route and the
           legacy address route resolve 'publish' only); Restore revives the
           SAME link (the token is preserved).
         • NON-ADMIN (app users): cannot delete. They file a DELETION REQUEST
           with a required reason (from the widget's History list). The admin
           sees a pending-requests panel + a menu bubble, gets an email, and
           Approves (trashes) or Declines. The requester can cancel their own
           pending request.

       Meta:
         _delete_request      pending request { user_id, name, reason, at, ip }
                              (at most one pending per receipt; cleared on
                              approve / decline / cancel)
         _delete_request_log  append-only audit of every request/resolution
       ===================================================================== */

    /** Count receipts with a pending deletion request (menu bubble + panel). */
    public function pending_delete_request_count(): int {
        static $count = null;
        if ( null !== $count ) { return $count; }
        $q = new \WP_Query( [
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [ [ 'key' => '_delete_request', 'compare' => 'EXISTS' ] ],
        ] );
        $count = (int) $q->found_posts;
        return $count;
    }

    /** The pending deletion request on a receipt, or null. */
    public function delete_request_of( int $post_id ): ?array {
        $req = get_post_meta( $post_id, '_delete_request', true );
        return ( is_array( $req ) && ! empty( $req['at'] ) ) ? $req : null;
    }

    /** Append one entry to the receipt's deletion audit log. */
    private function delreq_log( int $post_id, string $event, array $extra = [] ): void {
        $log = get_post_meta( $post_id, '_delete_request_log', true );
        if ( ! is_array( $log ) ) { $log = []; }
        $user  = get_userdata( get_current_user_id() );
        $log[] = array_merge( [
            'event' => $event, // requested | cancelled | declined | trashed | restored | deleted_forever
            'by'    => $user ? ( $user->display_name ?: $user->user_login ) : 'system',
            'by_id' => get_current_user_id(),
            'at'    => current_time( 'mysql' ),
        ], $extra );
        update_post_meta( $post_id, '_delete_request_log', $log );
    }

    /** Shared trash routine (admin table + widget admin-delete + approvals). */
    private function trash_receipt( int $post_id, string $context ): bool {
        $req = $this->delete_request_of( $post_id );
        $ok  = (bool) wp_trash_post( $post_id );
        if ( ! $ok ) { return false; }
        if ( $req ) {
            delete_post_meta( $post_id, '_delete_request' );
            $this->delreq_log( $post_id, 'trashed', [ 'note' => 'approved request by ' . ( $req['name'] ?? '?' ) . ': ' . ( $req['reason'] ?? '' ) ] );
        } else {
            $this->delreq_log( $post_id, 'trashed', [ 'note' => $context ] );
        }
        error_log( sprintf( 'ZRCPT DELETE: post_id=%d trashed (%s) by uid=%d — share link now 404s; restorable from Manage Receipts.',
            $post_id, $context, get_current_user_id() ) );
        // v3.9.3 — the invoice must never keep a link that now 404s: point it
        // at the successor receipt if one exists, otherwise blank the line.
        try { $this->fb_sync_after_trash( $post_id ); } catch ( \Throwable $e ) {
            error_log( 'ZRCPT FB SYNC: post-trash sync failed for post ' . $post_id . ': ' . $e->getMessage() );
        }
        return true;
    }

    /** Human state of a receipt for the admin table (mirrors the widget). */
    public function receipt_state_for_admin( int $post_id ): array {
        $state = $this->get_approval_state( $post_id );
        if ( ! empty( $state['sent'] ) ) {
            return [ 'sent', 'Sent ' . $state['sent_at'] . ( get_post_meta( $post_id, '_sent_to', true ) ? ' → ' . get_post_meta( $post_id, '_sent_to', true ) : '' ) ];
        }
        if ( ! empty( $state['approved'] ) ) {
            return [ 'approved', 'Approved by ' . $state['approved_by'] . ' ' . $state['approved_at'] ];
        }
        if ( ! empty( $state['prev_sent_at'] ) ) {
            return [ 'redone', 'Redone — approve & re-send (previously sent ' . $state['prev_sent_at'] . ')' ];
        }
        return [ 'draft', 'Needs approval' ];
    }

    /* ------------------------------------------------------------------
     * The Manage Receipts page.
     * ------------------------------------------------------------------ */
    public function render_manage_page() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized.' ); }

        $base_url = admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=zorderz-manage' );
        $search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $status   = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'all';
        $paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $per_page = 20;

        // ── Notices after admin-post redirects ──
        $notices = [
            'trashed'    => [ 'success', 'Receipt moved to Trash. Its share link stops working immediately, and its invoice link line was re-pointed (or blanked if no other receipt exists). You can restore it from the Trash view.' ],
            'restored'   => [ 'success', 'Receipt restored. Its share link works again (same link as before), and the invoice link was re-synced.' ],
            'deleted'    => [ 'success', 'Receipt permanently deleted.' ],
            'declined'   => [ 'success', 'Deletion request declined. The receipt is untouched.' ],
            'fbsynced'   => [ 'success', 'FreshBooks invoice link re-synced to this receipt’s current URL.' ],
            'fbsync_err' => [ 'error',   'Could not re-sync the FreshBooks invoice link — check the debug log (ZRCPT FB SYNC lines).' ],
            'error'      => [ 'error',   'That action could not be completed.' ],
        ];
        if ( isset( $_GET['zrcpt_notice'], $notices[ $_GET['zrcpt_notice'] ] ) ) {
            $n = $notices[ $_GET['zrcpt_notice'] ];
            echo '<div class="notice notice-' . esc_attr( $n[0] ) . ' is-dismissible"><p>' . esc_html( $n[1] ) . '</p></div>';
        }

        // ── Build the query for the current view ──
        $args = [
            'post_type'      => self::POST_TYPE,
            'post_status'    => ( $status === 'trash' ) ? 'trash' : 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];
        if ( $search !== '' ) {
            $digits = preg_replace( '/[^0-9]/', '', $search );
            if ( $digits !== '' && $digits === preg_replace( '/[^0-9#\s]/', '', $search ) ) {
                // Looks like an invoice number → match provenance meta.
                $args['meta_query'][] = [
                    'relation' => 'OR',
                    [ 'key' => '_fb_doc_numbers', 'value' => $digits, 'compare' => '=' ],
                    [ 'key' => '_fb_doc_number',  'value' => $digits, 'compare' => '=' ],
                ];
            } else {
                $args['s'] = $search; // title carries the address
            }
        }
        $status_meta = [
            'sent'     => [ [ 'key' => '_sent_at', 'compare' => 'EXISTS' ] ],
            'approved' => [ [ 'key' => '_approved_at', 'compare' => 'EXISTS' ], [ 'key' => '_sent_at', 'compare' => 'NOT EXISTS' ] ],
            'redone'   => [ [ 'key' => '_prev_sent_at', 'compare' => 'EXISTS' ], [ 'key' => '_sent_at', 'compare' => 'NOT EXISTS' ] ],
            'draft'    => [ [ 'key' => '_approved_at', 'compare' => 'NOT EXISTS' ], [ 'key' => '_sent_at', 'compare' => 'NOT EXISTS' ], [ 'key' => '_prev_sent_at', 'compare' => 'NOT EXISTS' ] ],
            'delreq'   => [ [ 'key' => '_delete_request', 'compare' => 'EXISTS' ] ],
        ];
        if ( isset( $status_meta[ $status ] ) ) {
            foreach ( $status_meta[ $status ] as $mq ) { $args['meta_query'][] = $mq; }
        }
        if ( ! empty( $args['meta_query'] ) && count( $args['meta_query'] ) > 1 ) {
            $args['meta_query']['relation'] = 'AND';
        }
        $q = new \WP_Query( $args );

        // ── Tab counts (cheap: one query each, ids only) ──
        $count_for = function ( $key ) use ( $status_meta ) {
            $a = [
                'post_type' => self::POST_TYPE, 'posts_per_page' => 1, 'fields' => 'ids',
                'post_status' => ( $key === 'trash' ) ? 'trash' : 'publish',
            ];
            if ( isset( $status_meta[ $key ] ) ) { $a['meta_query'] = $status_meta[ $key ]; }
            $qq = new \WP_Query( $a );
            return (int) $qq->found_posts;
        };
        $tabs = [
            'all'      => 'All',
            'draft'    => 'Needs approval',
            'approved' => 'Approved',
            'sent'     => 'Sent',
            'redone'   => 'Redone',
            'delreq'   => 'Deletion requested',
            'trash'    => 'Trash',
        ];

        echo '<div class="wrap"><h1 class="wp-heading-inline">Manage Receipts</h1>';
        echo '<p>Every installation receipt, with the same state the app shows — plus admin-only delete. '
           . '<strong>Delete moves a receipt to Trash</strong>: its customer link stops working immediately, and it can be restored (same link) or deleted forever from the Trash view.</p>';

        // ── Pending deletion requests panel (always on top when any exist) ──
        $pending_q = new \WP_Query( [
            'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => 50,
            'meta_query' => [ [ 'key' => '_delete_request', 'compare' => 'EXISTS' ] ],
        ] );
        if ( $pending_q->have_posts() ) {
            echo '<div style="background:#FCF9E8;border:1px solid #D63638;border-left-width:4px;border-radius:4px;padding:12px 16px;margin:12px 0;">';
            echo '<h2 style="margin:0 0 8px;">🗑 Deletion requests (' . (int) $pending_q->found_posts . ')</h2>';
            foreach ( $pending_q->posts as $p ) {
                $req = $this->delete_request_of( (int) $p->ID );
                if ( ! $req ) { continue; }
                echo '<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:8px 0;border-top:1px solid #eee;">';
                echo '<div style="flex:1 1 320px;min-width:260px;">';
                echo '<strong>' . esc_html( get_post_meta( $p->ID, '_address_short', true ) ?: $p->post_title ) . '</strong>';
                $invs = (array) get_post_meta( $p->ID, '_fb_doc_numbers', false );
                if ( $invs ) { echo ' <span style="color:#666;">(Invoice ' . esc_html( '#' . implode( ', #', $invs ) ) . ')</span>'; }
                echo '<br /><span style="color:#8a1f1f;">“' . esc_html( (string) ( $req['reason'] ?? '' ) ) . '”</span>';
                echo '<br /><span style="color:#666;">Requested by ' . esc_html( (string) ( $req['name'] ?? '?' ) ) . ' on ' . esc_html( mysql2date( 'M j, Y g:i a', (string) $req['at'] ) ) . '</span>';
                echo ' · <a href="' . esc_url( get_permalink( $p->ID ) ) . '" target="_blank" rel="noopener">view receipt ↗</a>';
                echo '</div><div style="display:flex;gap:6px;">';
                echo $this->admin_action_form( 'zrcpt_admin_trash_receipt', (int) $p->ID, 'Approve & delete', 'button button-primary', 'Delete this receipt? Its customer link stops working immediately. (It moves to Trash and can be restored.)' );
                echo $this->admin_action_form( 'zrcpt_admin_decline_delreq', (int) $p->ID, 'Decline', 'button' );
                echo '</div></div>';
            }
            echo '</div>';
            wp_reset_postdata();
        }

        // ── Filter tabs + search ──
        echo '<ul class="subsubsub" style="margin-top:4px;">';
        $t_i = 0;
        foreach ( $tabs as $key => $label ) {
            $url = esc_url( add_query_arg( [ 'status' => $key, 's' => $search ?: false ], $base_url ) );
            $cur = ( $status === $key ) ? ' class="current"' : '';
            $cnt = ( $key === 'all' ) ? $count_for( 'all' ) : $count_for( $key );
            echo '<li>' . ( $t_i++ ? ' | ' : '' ) . '<a href="' . $url . '"' . $cur . '>' . esc_html( $label ) . ' <span class="count">(' . $cnt . ')</span></a></li>';
        }
        echo '</ul>';
        echo '<form method="get" action="' . esc_url( admin_url( 'edit.php' ) ) . '" style="float:right;margin:0 0 8px;">';
        echo '<input type="hidden" name="post_type" value="' . esc_attr( self::POST_TYPE ) . '" />';
        echo '<input type="hidden" name="page" value="zorderz-manage" />';
        echo '<input type="hidden" name="status" value="' . esc_attr( $status ) . '" />';
        echo '<p class="search-box" style="margin:0;"><input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="Address or invoice #" /> ';
        echo '<input type="submit" class="button" value="Search" /></p></form>';

        // ── The table ──
        echo '<table class="widefat striped" style="clear:both;"><thead><tr>';
        echo '<th style="width:26%;">Receipt</th><th>Invoices</th><th>Customer</th><th style="width:22%;">Status</th><th>Created</th><th style="width:200px;">Actions</th>';
        echo '</tr></thead><tbody>';

        if ( ! $q->have_posts() ) {
            echo '<tr><td colspan="6">No receipts match this view.</td></tr>';
        }
        foreach ( $q->posts as $p ) {
            $pid   = (int) $p->ID;
            $addr  = (string) get_post_meta( $pid, '_address_short', true );
            $idate = (string) get_post_meta( $pid, '_install_date', true );
            $invs  = (array) get_post_meta( $pid, '_fb_doc_numbers', false );
            $cust  = (string) get_post_meta( $pid, '_customer_name', true );
            $esrc  = (string) get_post_meta( $pid, '_customer_email_source', true );
            $creator = get_userdata( (int) get_post_meta( $pid, '_created_by', true ) );
            $req   = $this->delete_request_of( $pid );
            list( $skey, $slabel ) = $this->receipt_state_for_admin( $pid );
            $scolor = [ 'sent' => '#166534', 'approved' => '#1E4D6E', 'redone' => '#B45309', 'draft' => '#666' ][ $skey ] ?? '#666';

            echo '<tr' . ( $req ? ' style="background:#FCF0F0;"' : '' ) . '>';
            echo '<td><strong>' . esc_html( $addr ?: $p->post_title ) . '</strong>';
            if ( $idate ) { echo '<br /><span style="color:#666;">Installed ' . esc_html( $idate ) . '</span>'; }
            echo '</td>';
            echo '<td>' . ( $invs ? esc_html( '#' . implode( ', #', $invs ) ) : '—' ) . '</td>';
            echo '<td>' . esc_html( $cust ?: '—' ) . ( $esrc && $esrc !== 'none' ? '<br /><span style="color:#666;">email via ' . esc_html( $esrc ) . '</span>' : '' ) . '</td>';
            echo '<td><span style="color:' . esc_attr( $scolor ) . ';font-weight:600;">' . esc_html( $slabel ) . '</span>';
            if ( $req ) {
                echo '<br /><span style="color:#8a1f1f;">🗑 Deletion requested by ' . esc_html( (string) ( $req['name'] ?? '?' ) ) . ': “' . esc_html( (string) ( $req['reason'] ?? '' ) ) . '”</span>';
            }
            echo '</td>';
            echo '<td>' . esc_html( mysql2date( 'M j, Y', $p->post_date ) ) . ( $creator ? '<br /><span style="color:#666;">by ' . esc_html( $creator->display_name ) . '</span>' : '' ) . '</td>';
            echo '<td>';
            if ( $status === 'trash' ) {
                echo '<div style="display:flex;gap:6px;flex-wrap:wrap;">';
                echo $this->admin_action_form( 'zrcpt_admin_restore_receipt', $pid, 'Restore', 'button button-small' );
                echo $this->admin_action_form( 'zrcpt_admin_delete_forever', $pid, 'Delete forever', 'button button-small', 'Permanently delete this receipt? This CANNOT be undone.' );
                echo '</div>';
            } else {
                echo '<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">';
                echo '<a class="button button-small" href="' . esc_url( get_permalink( $pid ) ) . '" target="_blank" rel="noopener">Open ↗</a>';
                $regen = wp_nonce_url(
                    admin_url( 'admin-post.php?action=zrcpt_regenerate_link&post=' . $pid ),
                    'zrcpt_regenerate_link_' . $pid
                );
                echo '<a class="button button-small" href="' . esc_url( $regen ) . '" onclick="return confirm(\'Regenerate this secret link? The current link stops working immediately (the invoice line is re-synced automatically).\');">New link</a>';
                echo $this->admin_action_form( 'zrcpt_admin_sync_fb_link', $pid, 'Sync FB link', 'button button-small' );
                if ( $req ) {
                    echo $this->admin_action_form( 'zrcpt_admin_trash_receipt', $pid, 'Approve & delete', 'button button-small button-primary', 'Delete this receipt? Its customer link stops working immediately. (It moves to Trash and can be restored.)' );
                    echo $this->admin_action_form( 'zrcpt_admin_decline_delreq', $pid, 'Decline', 'button button-small' );
                } else {
                    echo $this->admin_action_form( 'zrcpt_admin_trash_receipt', $pid, 'Delete', 'button button-small zrcpt-danger', 'Delete this receipt? Its customer link stops working immediately. (It moves to Trash and can be restored.)' );
                }
                echo '</div>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';

        // ── Pagination ──
        $total_pages = (int) $q->max_num_pages;
        if ( $total_pages > 1 ) {
            // NOTE: no esc_url_raw here — it would percent-encode the %#%
            // placeholder before paginate_links can substitute the page number.
            $links = paginate_links( [
                'base'    => add_query_arg( [ 'paged' => '%#%', 'status' => $status, 's' => $search ?: false ], $base_url ),
                'format'  => '',
                'current' => $paged,
                'total'   => $total_pages,
                'type'    => 'plain',
            ] );
            if ( $links ) {
                echo '<div class="tablenav bottom"><div class="tablenav-pages" style="margin:12px 0;">' . $links . '</div></div>';
            }
        }

        echo '<style>.zrcpt-danger{color:#b32d2e !important;border-color:#b32d2e !important;}</style>';
        echo '</div>';
        wp_reset_postdata();
    }

    /** One inline admin-post form button (nonce'd; optional confirm). */
    private function admin_action_form( string $action, int $post_id, string $label, string $classes = 'button', string $confirm = '' ): string {
        $on = $confirm !== '' ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : '';
        return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;margin:0;"' . $on . '>'
            . '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />'
            . '<input type="hidden" name="post_id" value="' . (int) $post_id . '" />'
            . wp_nonce_field( $action . '_' . $post_id, '_wpnonce', true, false )
            . '<button type="submit" class="' . esc_attr( $classes ) . '">' . esc_html( $label ) . '</button>'
            . '</form>';
    }

    /** Shared guard for the admin-post handlers → validated post id. */
    private function admin_action_guard( string $action ): int {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized.' ); }
        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        if ( ! $post_id || ! check_admin_referer( $action . '_' . $post_id ) ) { wp_die( 'Invalid request.' ); }
        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== self::POST_TYPE ) { wp_die( 'Receipt not found.' ); }
        return $post_id;
    }

    /** Redirect back to the Manage Receipts page with a notice key. */
    private function manage_redirect( string $notice, string $view = '' ): void {
        $url = admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=zorderz-manage&zrcpt_notice=' . rawurlencode( $notice ) );
        if ( $view !== '' ) { $url .= '&status=' . rawurlencode( $view ); }
        wp_safe_redirect( $url );
        exit;
    }

    public function handle_admin_trash_receipt() {
        $post_id = $this->admin_action_guard( 'zrcpt_admin_trash_receipt' );
        $ok = $this->trash_receipt( $post_id, 'admin table' );
        $this->manage_redirect( $ok ? 'trashed' : 'error' );
    }

    public function handle_admin_restore_receipt() {
        $post_id = $this->admin_action_guard( 'zrcpt_admin_restore_receipt' );
        // WP restores trashed posts to 'draft' by default — a draft receipt's
        // share link would stay dead. Restore straight to publish (same token,
        // same link).
        $to_publish = function () { return 'publish'; };
        add_filter( 'wp_untrash_post_status', $to_publish, 99 );
        $ok = (bool) wp_untrash_post( $post_id );
        remove_filter( 'wp_untrash_post_status', $to_publish, 99 );
        if ( $ok ) {
            $this->delreq_log( $post_id, 'restored' );
            error_log( 'ZRCPT DELETE: post_id=' . $post_id . ' restored to publish — share link live again.' );
            // v3.9.3 — re-point the invoice at this receipt again, but ONLY if
            // no newer receipt has taken over its invoice(s) in the meantime
            // (receipt_for_invoice resolves the current owner; if that's a
            // different post, its link stays — use "Sync FB link" to force).
            try {
                $numbers = (array) get_post_meta( $post_id, '_fb_doc_numbers', false );
                $owner   = ! empty( $numbers ) ? $this->receipt_for_invoice( $numbers[0] ) : null;
                if ( $owner && (int) ( $owner['post_id'] ?? 0 ) === $post_id ) {
                    $this->fb_sync_receipt_link( $post_id );
                } elseif ( $owner ) {
                    error_log( 'ZRCPT FB SYNC: restore of post ' . $post_id . ' — invoice now owned by post ' . (int) $owner['post_id'] . '; leaving its link in place.' );
                }
            } catch ( \Throwable $e ) {
                error_log( 'ZRCPT FB SYNC: post-restore sync failed for post ' . $post_id . ': ' . $e->getMessage() );
            }
        }
        $this->manage_redirect( $ok ? 'restored' : 'error', 'trash' );
    }

    public function handle_admin_delete_forever() {
        $post_id = $this->admin_action_guard( 'zrcpt_admin_delete_forever' );
        $addr = (string) get_post_meta( $post_id, '_address_short', true );
        $ok = (bool) wp_delete_post( $post_id, true );
        if ( $ok ) {
            error_log( 'ZRCPT DELETE: post_id=' . $post_id . ' (' . $addr . ') PERMANENTLY deleted by uid=' . get_current_user_id() );
        }
        $this->manage_redirect( $ok ? 'deleted' : 'error', 'trash' );
    }

    public function handle_admin_decline_delreq() {
        $post_id = $this->admin_action_guard( 'zrcpt_admin_decline_delreq' );
        $req = $this->delete_request_of( $post_id );
        delete_post_meta( $post_id, '_delete_request' );
        $this->delreq_log( $post_id, 'declined', [ 'note' => $req ? ( 'request by ' . ( $req['name'] ?? '?' ) . ': ' . ( $req['reason'] ?? '' ) ) : '' ] );
        $this->manage_redirect( 'declined' );
    }

    /** v3.9.3 — Manage Receipts "Sync FB link": force the invoice's receipt
     *  link line to this receipt's CURRENT URL. The repair tool for any stale
     *  line (and the belt to the automatic braces). */
    public function handle_admin_sync_fb_link() {
        $post_id = $this->admin_action_guard( 'zrcpt_admin_sync_fb_link' );
        $res = $this->fb_sync_receipt_link( $post_id );
        $this->manage_redirect( ! empty( $res['linked'] ) ? 'fbsynced' : 'fbsync_err' );
    }

    /* ------------------------------------------------------------------
     * Widget-side deletion endpoints.
     * ------------------------------------------------------------------ */

    /** Widget lookup WITHOUT the ownership gate — a deletion REQUEST doesn't
     *  read or mutate receipt content; the admin is the control point. It
     *  still requires login + app access + a valid receipt id. */
    private function require_receipt_post_any( $post_id ): \WP_Post {
        $post_id = (int) $post_id;
        $post = $post_id ? get_post( $post_id ) : null;
        if ( ! $post || $post->post_type !== self::POST_TYPE ) {
            wp_send_json_error( [ 'message' => 'Receipt not found.' ] );
        }
        return $post;
    }

    /**
     * AJAX: zrcpt_request_deletion — a non-admin asks the admin to delete a
     * receipt. Reason is REQUIRED (that's the accountability half of the
     * feature). One pending request per receipt. Emails the site admin.
     */
    public function ajax_request_deletion() {
        $uid  = $this->approve_send_guard();
        $post = $this->require_receipt_post_any( $_POST['post_id'] ?? 0 );
        $post_id = (int) $post->ID;

        if ( $post->post_status === 'trash' ) {
            wp_send_json_error( [ 'message' => 'This receipt is already deleted.' ] );
        }

        $reason = isset( $_POST['reason'] ) ? trim( sanitize_textarea_field( wp_unslash( (string) $_POST['reason'] ) ) ) : '';
        if ( strlen( $reason ) < 5 ) {
            wp_send_json_error( [ 'message' => 'Please give a short reason for deleting this receipt (at least 5 characters).' ] );
        }
        $reason = substr( $reason, 0, 500 );

        if ( $this->delete_request_of( $post_id ) ) {
            wp_send_json_error( [ 'message' => 'A deletion request is already pending for this receipt.' ] );
        }

        $user = get_userdata( $uid );
        $name = $user ? ( $user->display_name ?: $user->user_login ) : ( 'User #' . $uid );
        $req  = [
            'user_id' => $uid,
            'name'    => $name,
            'reason'  => $reason,
            'at'      => current_time( 'mysql' ),
            'ip'      => $this->client_ip(),
        ];
        update_post_meta( $post_id, '_delete_request', $req );
        $this->delreq_log( $post_id, 'requested', [ 'reason' => $reason ] );

        // Notify the admin (best-effort; the request stands even if mail fails).
        $addr   = (string) get_post_meta( $post_id, '_address_short', true );
        $manage = admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=zorderz-manage&status=delreq' );
        $body   = "A deletion request was filed for an installation receipt.\n\n"
                . 'Receipt:  ' . ( $addr ?: get_the_title( $post_id ) ) . "\n"
                . 'Link:     ' . get_permalink( $post_id ) . "\n"
                . 'By:       ' . $name . "\n"
                . 'Reason:   ' . $reason . "\n\n"
                . "Approve or decline it here:\n" . $manage . "\n";
        @wp_mail( get_option( 'admin_email' ), 'Receipt deletion requested — ' . ( $addr ?: '#' . $post_id ), $body );

        error_log( sprintf( 'ZRCPT DELREQ: post_id=%d requested by %s (uid %d): %s', $post_id, $name, $uid, $reason ) );

        wp_send_json_success( [
            'message' => 'Request sent to the admin. The receipt stays up until they approve the deletion.',
            'del_req' => [
                'by_name' => $name,
                'at'      => mysql2date( 'M j, Y g:i a', $req['at'] ),
                'reason'  => $reason,
                'mine'    => true,
            ],
        ] );
    }

    /** AJAX: zrcpt_cancel_delete_request — the requester (or an admin) cancels. */
    public function ajax_cancel_delete_request() {
        $uid  = $this->approve_send_guard();
        $post = $this->require_receipt_post_any( $_POST['post_id'] ?? 0 );
        $post_id = (int) $post->ID;

        $req = $this->delete_request_of( $post_id );
        if ( ! $req ) {
            wp_send_json_error( [ 'message' => 'No deletion request is pending on this receipt.' ] );
        }
        if ( (int) ( $req['user_id'] ?? 0 ) !== $uid && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Only the person who filed the request (or an admin) can cancel it.' ], 403 );
        }
        delete_post_meta( $post_id, '_delete_request' );
        $this->delreq_log( $post_id, 'cancelled' );
        wp_send_json_success( [ 'message' => 'Deletion request cancelled.' ] );
    }

    /** AJAX: zrcpt_admin_delete_receipt — direct delete from the widget (admins
     *  only). Same trash semantics as the admin table. */
    public function ajax_admin_delete_receipt() {
        $this->approve_send_guard();
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Only an admin can delete a receipt. Use “Request deletion” instead.' ], 403 );
        }
        $post = $this->require_receipt_post_any( $_POST['post_id'] ?? 0 );
        $post_id = (int) $post->ID;
        if ( $post->post_status === 'trash' ) {
            wp_send_json_error( [ 'message' => 'This receipt is already deleted.' ] );
        }
        if ( ! $this->trash_receipt( $post_id, 'widget (admin)' ) ) {
            wp_send_json_error( [ 'message' => 'Could not delete the receipt.' ] );
        }
        wp_send_json_success( [ 'message' => 'Receipt deleted (moved to Trash — restorable from Manage Receipts in wp-admin).' ] );
    }
}

