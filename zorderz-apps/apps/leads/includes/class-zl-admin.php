<?php
/**
 * Zorderz Leads - Admin Settings Class
 * 
 * FILE: class-zl-admin.php
 * VERSION: 1.2.1
 * 
 * ARCHITECTURE CONTEXT:
 * This file handles the WordPress admin interface for configuring Zorderz Leads.
 * It manages API credentials (FreshBooks, Nutshell CRM, Poe AI), batch processing parameters,
 * and salesperson territory mapping.
 * 
 * BUSINESS CONTEXT:
 * Built for the business. Manages settings to connect their FreshBooks invoicing
 * to their Nutshell CRM pipeline, using Poe AI (Gemini-3.1-Pro) for data enrichment.
 * 
 * CREDENTIAL SHARING:
 * To prevent duplicate data entry, this plugin attempts to read API credentials from the
 * "TS Satisfaction Surveys" plugin's wp_options if its own fields are blank.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class ZL_Admin
 * 
 * Orchestrates the settings page, option registration, data sanitization/encryption,
 * and the FreshBooks OAuth 2.0 callback flow.
 */
class ZL_Admin {

    /**
     * Constructor: Hooks into WordPress admin actions.
     */
    public function __construct() {
        // Register the menu items in the WP Admin sidebar
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        // Register the settings fields and sanitization callbacks
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        // Intercept the FreshBooks OAuth redirect callback
        add_action( 'admin_init', array( $this, 'handle_oauth_callback' ) );
        // v1.5.2 — FreshBooks diagnostic test (settings page button)
        add_action( 'wp_ajax_zl_test_freshbooks', array( $this, 'ajax_test_freshbooks' ) );
    }

    /**
     * Adds the main menu page, sub-menu page, and a hidden page for OAuth callbacks.
     */
    public function add_admin_menu() {
        // Main menu item
        add_menu_page(
            'Leads',
            'Leads',
            'manage_options',
            'zl-settings',
            array( $this, 'settings_page_html' ),
            'dashicons-groups',
            51
        );
        // Visible settings submenu (mirrors main menu)
        add_submenu_page(
            'zl-settings',
            'Settings',
            'Settings',
            'manage_options',
            'zl-settings',
            array( $this, 'settings_page_html' )
        );
        // Hidden OAuth callback endpoint (no UI rendered, handled in admin_init)
        add_submenu_page( null, 'OAuth Callback', 'OAuth Callback', 'manage_options', 'zl-callback', '__return_false' );
    }

    // ── Encryption helpers ──────────────────────────────────────────

    /**
     * Encrypts sensitive data (API keys, secrets) using AES-256-CBC.
     * Uses the WordPress 'auth' salt as the encryption key.
     * 
     * @param string $data The plaintext data to encrypt.
     * @return string The encrypted string, or empty if input was empty.
     */
    private function encrypt( $data ) {
        if ( empty( $data ) ) return '';
        $key = wp_salt( 'auth' );
        // Generate a 16-byte IV from the hash of the key
        $iv  = substr( hash( 'sha256', $key ), 0, 16 );
        return openssl_encrypt( $data, 'AES-256-CBC', $key, 0, $iv );
    }

    /**
     * Decrypts sensitive data encrypted by $this->encrypt().
     * 
     * @param string $data The encrypted data.
     * @return string|false The decrypted plaintext, or empty/false on failure.
     */
    private function decrypt( $data ) {
        if ( empty( $data ) ) return '';
        $key = wp_salt( 'auth' );
        $iv  = substr( hash( 'sha256', $key ), 0, 16 );
        return openssl_decrypt( $data, 'AES-256-CBC', $key, 0, $iv );
    }

    // ── Credential sharing: get value from our options or fall back to survey plugin ──

    /**
     * Retrieves a plaintext option, falling back to the Survey plugin's option if empty.
     * 
     * @param string $zl_key    This plugin's option key.
     * @param string $survey_key The Survey plugin's option key.
     * @param mixed  $default    Default value if neither exists.
     * @return mixed The resolved option value.
     */
    public static function get_shared_option( $zl_key, $survey_key, $default = '' ) {
        $val = get_option( $zl_key, '' );
        if ( ! empty( $val ) ) return $val;
        return get_option( $survey_key, $default );
    }

    /**
     * Retrieves an encrypted option string, falling back to the Survey plugin.
     * 
     * @param string $zl_key    This plugin's option key.
     * @param string $survey_key The Survey plugin's option key.
     * @return string The encrypted string.
     */
    public static function get_shared_encrypted( $zl_key, $survey_key ) {
        $val = get_option( $zl_key, '' );
        if ( ! empty( $val ) ) return $val; // Already encrypted
        return get_option( $survey_key, '' ); // Survey plugin's encrypted value
    }

    /**
     * Retrieves and decrypts a shared option.
     * 
     * @param string $zl_key    This plugin's option key.
     * @param string $survey_key The Survey plugin's option key.
     * @return string The decrypted plaintext.
     */
    public static function decrypt_shared( $zl_key, $survey_key ) {
        $encrypted = self::get_shared_encrypted( $zl_key, $survey_key );
        if ( empty( $encrypted ) ) return '';
        $key = wp_salt( 'auth' );
        $iv  = substr( hash( 'sha256', $key ), 0, 16 );
        return openssl_decrypt( $encrypted, 'AES-256-CBC', $key, 0, $iv );
    }

    // ── Register settings ──────────────────────────────────────────

    /**
     * Registers all WordPress options used by this plugin, including sanitization rules.
     */
    public function register_settings() {
        // FreshBooks API Settings
        register_setting( 'zl_options_group', 'zl_fb_client_id' );
        register_setting( 'zl_options_group', 'zl_fb_client_secret', array(
            'sanitize_callback' => array( $this, 'sanitize_encrypted' ),
        ) );
        register_setting( 'zl_options_group', 'zl_fb_account_id' );

        // Nutshell CRM API Settings
        register_setting( 'zl_options_group', 'zl_ns_email' );
        register_setting( 'zl_options_group', 'zl_ns_api_key', array(
            'sanitize_callback' => array( $this, 'sanitize_encrypted' ),
        ) );

        // Poe AI Settings
        register_setting( 'zl_options_group', 'zl_poe_api_key', array(
            'sanitize_callback' => array( $this, 'sanitize_encrypted' ),
        ) );
        register_setting( 'zl_options_group', 'zl_ai_model' );

        // Batch processing and filtering settings
        register_setting( 'zl_options_group', 'zl_leads_per_batch', array(
            'sanitize_callback' => 'absint',
        ) );
        register_setting( 'zl_options_group', 'zl_lookback_days', array(
            'sanitize_callback' => 'absint',
        ) );
        register_setting( 'zl_options_group', 'zl_cooldown_days', array(
            'sanitize_callback' => 'absint',
        ) );

        // Salespeople configuration (stored as JSON)
        register_setting( 'zl_options_group', 'zl_salespeople', array(
            'sanitize_callback' => array( $this, 'sanitize_salespeople' ),
        ) );

        // Excluded companies list (newline separated)
        register_setting( 'zl_options_group', 'zl_excluded_companies' );
    }

    /**
     * Sanitizes and encrypts password/secret fields.
     * Prevents overwriting the actual encrypted value if the UI submits the '********' mask.
     * 
     * @param string $value The submitted input value.
     * @return string The encrypted value to store in the database.
     */
    public function sanitize_encrypted( $value ) {
        if ( empty( $value ) ) return '';
        
        // If the user didn't change the masked password, keep the existing DB value
        if ( $value === '********' ) {
            $filter = current_filter();
            if ( strpos( $filter, 'fb_client_secret' ) !== false ) return get_option( 'zl_fb_client_secret' );
            if ( strpos( $filter, 'poe_api_key' ) !== false )     return get_option( 'zl_poe_api_key' );
            return get_option( 'zl_ns_api_key' );
        }
        
        // Otherwise, encrypt the new plaintext value
        return $this->encrypt( $value );
    }

    /**
     * Sanitizes the salespeople JSON string submitted from the dynamic frontend table.
     * 
     * @param string $value The JSON string.
     * @return string The validated JSON string.
     */
    public function sanitize_salespeople( $value ) {
        // Value comes as JSON string from hidden field
        if ( is_string( $value ) ) {
            $decoded = json_decode( stripslashes( $value ), true );
            if ( is_array( $decoded ) ) {
                return wp_json_encode( $decoded );
            }
        }
        // Fallback to default if invalid
        return get_option( 'zl_salespeople', wp_json_encode( zl_salespeople() ) );
    }

    // ── OAuth callback ────────────────────────────────────────────

    /**
     * Handles the FreshBooks OAuth 2.0 authorization code callback.
     * Exchanges the 'code' for an access token and refresh token, then discovers the Account ID.
     */
    public function handle_oauth_callback() {
        // Check if we are on the hidden callback page and have an auth code
        if ( isset( $_GET['page'] ) && $_GET['page'] === 'zl-callback' && isset( $_GET['code'] ) ) {
            if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

            $code          = sanitize_text_field( $_GET['code'] );
            $client_id     = self::get_shared_option( 'zl_fb_client_id', 'ts_surveys_fb_client_id' );
            $client_secret = self::decrypt_shared( 'zl_fb_client_secret', 'ts_surveys_fb_client_secret' );
            $redirect_uri  = admin_url( 'admin.php?page=zl-callback' );

            // Step 1: Exchange authorization code for tokens
            $token_response = wp_remote_post( 'https://api.freshbooks.com/auth/oauth/token', array(
                'body'    => wp_json_encode( array(
                    'grant_type'    => 'authorization_code',
                    'client_id'     => $client_id,
                    'client_secret' => $client_secret,
                    'code'          => $code,
                    'redirect_uri'  => $redirect_uri,
                ) ),
                'headers' => array( 'Content-Type' => 'application/json' ),
            ) );

            if ( is_wp_error( $token_response ) ) {
                wp_die( 'OAuth Error: ' . esc_html( $token_response->get_error_message() ) );
            }

            $token_body = json_decode( wp_remote_retrieve_body( $token_response ), true );

            // Step 2: Store tokens if successful
            if ( isset( $token_body['access_token'] ) ) {
                update_option( 'zl_fb_access_token', $token_body['access_token'] );
                if ( isset( $token_body['refresh_token'] ) ) {
                    update_option( 'zl_fb_refresh_token', $token_body['refresh_token'] );
                }

                // Step 3: Discover the FreshBooks Account ID via the FreshBooks client.
                // v1.5.1 — Uses the robust discover_account_id() method which handles
                // multiple business membership formats and reports clear errors.
                try {
                    $fb = new ZL_FreshBooks( $client_id, $client_secret );
                    $fb->set_access_token( $token_body['access_token'] );
                    $discovered_id = $fb->discover_account_id();
                    update_option( 'zl_fb_account_id', $discovered_id );
                    error_log( 'ZL OAuth: Account ID discovered: ' . $discovered_id );
                } catch ( \Throwable $e ) {
                    error_log( 'ZL OAuth: Account ID discovery failed: ' . $e->getMessage() );
                    // Still redirect — token is saved, user can troubleshoot from settings
                    wp_redirect( admin_url( 'admin.php?page=zl-settings&fb_success=1&fb_warn=' . urlencode( $e->getMessage() ) ) );
                    exit;
                }

                // Redirect back to settings page with success flag
                wp_redirect( admin_url( 'admin.php?page=zl-settings&fb_success=1' ) );
                exit;
            } else {
                wp_die( 'Failed to retrieve access token.' );
            }
        }
    }

    // ── FreshBooks diagnostic test (v1.5.2) ──────────────────────

    /**
     * AJAX handler: Test the FreshBooks API connection from the settings page.
     *
     * Makes three diagnostic API calls and returns the results:
     * 1. /users/me — verifies the token and account identity
     * 2. Invoices query WITHOUT status filter — checks if ANY invoices exist
     * 3. Invoices query WITH status=4 — checks for paid invoices specifically
     *
     * This gives clear, user-visible feedback when the "Test Connection" button
     * is clicked on the settings page — no error log digging required.
     *
     * @since 1.5.2
     */
    public function ajax_test_freshbooks() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }
        check_ajax_referer( 'zl_settings_nonce', 'nonce' );

        $results = array();

        // Gather credentials
        $client_id     = self::get_shared_option( 'zl_fb_client_id', 'ts_surveys_fb_client_id' );
        $client_secret = self::decrypt_shared( 'zl_fb_client_secret', 'ts_surveys_fb_client_secret' );
        $account_id    = self::get_shared_option( 'zl_fb_account_id', 'ts_surveys_fb_account_id' );
        $access_token  = self::get_shared_option( 'zl_fb_access_token', 'ts_surveys_fb_access_token' );

        $results['credentials'] = array(
            'client_id'    => ! empty( $client_id ) ? substr( $client_id, 0, 12 ) . '...' : '(EMPTY)',
            'client_secret'=> ! empty( $client_secret ) ? 'set (' . strlen( $client_secret ) . ' chars)' : '(EMPTY)',
            'account_id'   => ! empty( $account_id ) ? $account_id : '(EMPTY)',
            'access_token' => ! empty( $access_token ) ? 'set (' . strlen( $access_token ) . ' chars)' : '(EMPTY)',
            'token_source' => ! empty( get_option( 'zl_fb_access_token', '' ) ) ? 'ZL plugin' : ( ! empty( get_option( 'ts_surveys_fb_access_token', '' ) ) ? 'Shared from Surveys' : 'NONE' ),
        );

        if ( empty( $access_token ) ) {
            $results['error'] = 'No access token found. Click "Connect FreshBooks" first.';
            wp_send_json_success( $results );
        }

        $headers = array(
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type'  => 'application/json',
        );

        // Test 1: /users/me — verify token and identity
        $me = wp_remote_get( 'https://api.freshbooks.com/auth/api/v1/users/me', array(
            'headers' => $headers,
            'timeout' => 15,
        ) );

        if ( is_wp_error( $me ) ) {
            $results['test_identity'] = array( 'status' => 'ERROR', 'message' => $me->get_error_message() );
        } else {
            $me_code = wp_remote_retrieve_response_code( $me );
            $me_body = json_decode( wp_remote_retrieve_body( $me ), true );
            if ( 200 === (int) $me_code && isset( $me_body['response'] ) ) {
                $resp = $me_body['response'];
                $memberships = isset( $resp['business_memberships'] ) ? $resp['business_memberships'] : array();
                $biz_names = array();
                $biz_ids   = array();
                foreach ( $memberships as $m ) {
                    $b = isset( $m['business'] ) ? $m['business'] : array();
                    $biz_names[] = isset( $b['name'] ) ? $b['name'] : '(unnamed)';
                    $biz_ids[]   = isset( $b['account_id'] ) ? $b['account_id'] : ( isset( $b['id'] ) ? $b['id'] : '?' );
                }
                $results['test_identity'] = array(
                    'status'     => 'OK',
                    'http_code'  => $me_code,
                    'email'      => isset( $resp['email'] ) ? $resp['email'] : 'N/A',
                    'businesses' => $biz_names,
                    'account_ids'=> $biz_ids,
                    'using_id'   => $account_id,
                    'match'      => in_array( $account_id, $biz_ids, true ) ? 'YES' : 'NO — MISMATCH!',
                );
            } else {
                $results['test_identity'] = array(
                    'status'  => 'FAILED',
                    'http_code' => $me_code,
                    'body'    => substr( wp_remote_retrieve_body( $me ), 0, 500 ),
                );
            }
        }

        // Test 2: Invoice query WITHOUT status filter — any invoices at all?
        if ( ! empty( $account_id ) ) {
            $url_any = add_query_arg(
                array( 'page' => 1, 'per_page' => 1 ),
                "https://api.freshbooks.com/accounting/account/{$account_id}/invoices/invoices"
            );
            $inv_any = wp_remote_get( $url_any, array( 'headers' => $headers, 'timeout' => 15 ) );

            if ( is_wp_error( $inv_any ) ) {
                $results['test_any_invoices'] = array( 'status' => 'ERROR', 'message' => $inv_any->get_error_message() );
            } else {
                $any_code = wp_remote_retrieve_response_code( $inv_any );
                $any_body = json_decode( wp_remote_retrieve_body( $inv_any ), true );
                $any_total = isset( $any_body['response']['result']['total'] ) ? $any_body['response']['result']['total'] : 'N/A';
                $results['test_any_invoices'] = array(
                    'status'    => 200 === (int) $any_code ? 'OK' : 'FAILED',
                    'http_code' => $any_code,
                    'total'     => $any_total,
                    'message'   => 200 === (int) $any_code
                        ? "Found {$any_total} total invoice(s) in account {$account_id}"
                        : 'HTTP ' . $any_code . ': ' . substr( wp_remote_retrieve_body( $inv_any ), 0, 300 ),
                );
            }

            // Test 3: Invoice query WITH status=4 (paid) and 5-year lookback
            $date_min = gmdate( 'Y-m-d', strtotime( '-1825 days' ) );
            $url_paid = add_query_arg(
                array(
                    'search[status]'   => 4,
                    'search[date_min]' => $date_min,
                    'page'             => 1,
                    'per_page'         => 1,
                ),
                "https://api.freshbooks.com/accounting/account/{$account_id}/invoices/invoices"
            );
            $inv_paid = wp_remote_get( $url_paid, array( 'headers' => $headers, 'timeout' => 15 ) );

            if ( is_wp_error( $inv_paid ) ) {
                $results['test_paid_invoices'] = array( 'status' => 'ERROR', 'message' => $inv_paid->get_error_message() );
            } else {
                $paid_code = wp_remote_retrieve_response_code( $inv_paid );
                $paid_body = json_decode( wp_remote_retrieve_body( $inv_paid ), true );
                $paid_total = isset( $paid_body['response']['result']['total'] ) ? $paid_body['response']['result']['total'] : 'N/A';
                $results['test_paid_invoices'] = array(
                    'status'    => 200 === (int) $paid_code ? 'OK' : 'FAILED',
                    'http_code' => $paid_code,
                    'total'     => $paid_total,
                    'date_min'  => $date_min,
                    'message'   => 200 === (int) $paid_code
                        ? "Found {$paid_total} paid invoice(s) since {$date_min}"
                        : 'HTTP ' . $paid_code . ': ' . substr( wp_remote_retrieve_body( $inv_paid ), 0, 300 ),
                );
            }

            // v1.5.3 — Test 4: Pipeline mirror — EXACTLY matches get_paid_invoices_page()
            // Uses include[]=lines and per_page=100, which is the only difference between
            // the working diagnostic test (above) and the failing pipeline fetch.
            // This definitively proves whether include[]=lines is the culprit.
            $lookback  = (int) get_option( 'zl_lookback_days', 730 );
            $date_min4 = gmdate( 'Y-m-d', strtotime( "-{$lookback} days" ) );
            $url_pipe  = add_query_arg(
                array(
                    'search[status]'   => 4,
                    'search[date_min]' => $date_min4,
                    'include[]'        => 'lines',
                    'page'             => 1,
                    'per_page'         => 100,
                ),
                "https://api.freshbooks.com/accounting/account/{$account_id}/invoices/invoices"
            );
            $inv_pipe = wp_remote_get( $url_pipe, array( 'headers' => $headers, 'timeout' => 45 ) );

            if ( is_wp_error( $inv_pipe ) ) {
                $results['test_pipeline_mirror'] = array( 'status' => 'ERROR', 'message' => $inv_pipe->get_error_message() );
            } else {
                $pipe_code  = wp_remote_retrieve_response_code( $inv_pipe );
                $pipe_raw   = wp_remote_retrieve_body( $inv_pipe );
                $pipe_body  = json_decode( $pipe_raw, true );
                $pipe_total = isset( $pipe_body['response']['result']['total'] ) ? $pipe_body['response']['result']['total'] : 'N/A';
                $pipe_count = isset( $pipe_body['response']['result']['invoices'] ) ? count( $pipe_body['response']['result']['invoices'] ) : 0;

                // Determine if this test shows the pipeline bug
                $pipe_ok      = 200 === (int) $pipe_code && is_numeric( $pipe_total ) && (int) $pipe_total > 0;
                $pipe_message = '';
                if ( $pipe_ok ) {
                    $pipe_message = "Found {$pipe_total} paid invoices with include[]=lines (page has {$pipe_count} invoices)";
                } elseif ( 200 === (int) $pipe_code && 0 === (int) $pipe_total ) {
                    // THIS IS THE BUG — include[]=lines returns 0 but Test 3 found thousands
                    $pipe_message = "⚠ 0 invoices with include[]=lines! This is the pipeline bug. "
                                  . "Test 3 found {$paid_total} without include[]=lines. "
                                  . "The v1.5.3 fallback will automatically work around this.";
                } else {
                    $pipe_message = 'HTTP ' . $pipe_code . ': ' . substr( $pipe_raw, 0, 300 );
                }

                $results['test_pipeline_mirror'] = array(
                    'status'       => $pipe_ok ? 'OK' : ( 200 === (int) $pipe_code ? 'MISMATCH' : 'FAILED' ),
                    'http_code'    => $pipe_code,
                    'total'        => $pipe_total,
                    'page_count'   => $pipe_count,
                    'date_min'     => $date_min4,
                    'lookback_days'=> $lookback,
                    'url'          => $url_pipe,
                    'message'      => $pipe_message,
                );
            }
        }

        wp_send_json_success( $results );
    }

    // ── Settings page HTML ─────────────────────────────────────────

    /**
     * Renders the HTML for the plugin settings page.
     */
    public function settings_page_html() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        // Display success notice if returning from OAuth flow
        if ( isset( $_GET['fb_success'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>FreshBooks connected successfully!</p></div>';
        }

        // Check for survey plugin credentials to display sharing notices
        $survey_fb  = get_option( 'ts_surveys_fb_access_token', '' );
        $survey_ns  = get_option( 'ts_surveys_ns_api_key', '' );
        $survey_poe = get_option( 'ts_surveys_poe_api_key', '' );
        $has_survey = ! empty( $survey_fb ) || ! empty( $survey_ns );

        // Determine current credential status (resolving shared fallbacks)
        $fb_token   = self::get_shared_option( 'zl_fb_access_token', 'ts_surveys_fb_access_token' );
        $ns_key     = self::get_shared_encrypted( 'zl_ns_api_key', 'ts_surveys_ns_api_key' );
        $poe_key    = self::get_shared_encrypted( 'zl_poe_api_key', 'ts_surveys_poe_api_key' );

        $fb_status  = $fb_token ? '<span style="color:green;font-weight:bold;">✅ Connected</span>' : '<span style="color:red;font-weight:bold;">❌ Not Connected</span>';
        $ns_status  = $ns_key ? '<span style="color:green;font-weight:bold;">✅ Configured</span>' : '<span style="color:red;font-weight:bold;">❌ Not Configured</span>';
        $poe_status = $poe_key ? '<span style="color:green;font-weight:bold;">✅ Configured</span>' : '<span style="color:gray;font-weight:bold;">⚠️ Not Set (AI features disabled)</span>';

        // Load salespeople configuration
        $salespeople = json_decode( get_option( 'zl_salespeople', '[]' ), true );
        if ( ! is_array( $salespeople ) ) $salespeople = zl_salespeople();

        // Prepare OAuth URL for FreshBooks
        $client_id    = self::get_shared_option( 'zl_fb_client_id', 'ts_surveys_fb_client_id' );
        $redirect_uri = urlencode( admin_url( 'admin.php?page=zl-callback' ) );
        $oauth_url    = "https://auth.freshbooks.com/service/auth/oauth/authorize?client_id={$client_id}&response_type=code&redirect_uri={$redirect_uri}";

        ?>
        <div class="wrap">
            <h1>Leads — Settings</h1>
            <p class="description">v<?php echo esc_html( ZL_VERSION ); ?></p>

            <?php if ( $has_survey ) : ?>
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px 20px;margin:16px 0;">
                <strong>🔗 TS Satisfaction Surveys detected.</strong> API credentials are shared automatically. Override below if needed.
            </div>
            <?php endif; ?>

            <div style="background:#fff;padding:15px;border:1px solid #ccd0d4;margin-bottom:20px;">
                <h3>Connection Status</h3>
                <p><strong>FreshBooks:</strong> <?php echo $fb_status; ?><?php echo $survey_fb && empty( get_option( 'zl_fb_access_token' ) ) ? ' <em style="color:#6b7280;">(shared from Survey plugin)</em>' : ''; ?></p>
                <p><strong>Nutshell CRM:</strong> <?php echo $ns_status; ?><?php echo $survey_ns && empty( get_option( 'zl_ns_api_key' ) ) ? ' <em style="color:#6b7280;">(shared from Survey plugin)</em>' : ''; ?></p>
                <p><strong>Poe AI:</strong> <?php echo $poe_status; ?><?php echo $survey_poe && empty( get_option( 'zl_poe_api_key' ) ) ? ' <em style="color:#6b7280;">(shared from Survey plugin)</em>' : ''; ?></p>
            </div>

            <form action="options.php" method="post">
                <?php settings_fields( 'zl_options_group' ); ?>

                <!-- ── Salespeople Configuration ────────────────── -->
                <h2>👥 Salespeople &amp; Territories</h2>
                <p class="description">Configure active salespeople and the Nutshell territory codes they handle. Leads are matched to salespeople by their Territory custom field in Nutshell.</p>
                <table class="form-table" id="zl-salespeople-table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Name</th>
                            <th>Territory Codes (comma-separated)</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $salespeople as $i => $sp ) : ?>
                        <tr class="zl-sp-row">
                            <td><input type="text" class="zl-sp-code" value="<?php echo esc_attr( $sp['code'] ); ?>" style="width:60px;" /></td>
                            <td><input type="text" class="zl-sp-name" value="<?php echo esc_attr( $sp['name'] ); ?>" style="width:200px;" /></td>
                            <td><input type="text" class="zl-sp-territories" value="<?php echo esc_attr( $sp['territories'] ); ?>" style="width:200px;" placeholder="e.g. NW,ZONE-1" /></td>
                            <td><button type="button" class="button zl-sp-remove" onclick="this.closest('tr').remove();tslUpdateSalespeople();">✕</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <button type="button" class="button" onclick="tslAddSalesperson()">+ Add Salesperson</button>
                <input type="hidden" name="zl_salespeople" id="zl-salespeople-json" value="<?php echo esc_attr( wp_json_encode( $salespeople ) ); ?>" />
                <script>

                function tslAddSalesperson() {
                // Dynamically add a new salesperson row to the UI
                    var tbody = document.querySelector('#zl-salespeople-table tbody');
                    var row = document.createElement('tr');
                    row.className = 'zl-sp-row';
                    row.innerHTML = '<td><input type="text" class="zl-sp-code" value="" style="width:60px;" /></td>' +
                        '<td><input type="text" class="zl-sp-name" value="" style="width:200px;" /></td>' +
                        '<td><input type="text" class="zl-sp-territories" value="" style="width:200px;" placeholder="e.g. SE,ZONE-2" /></td>' +
                        '<td><button type="button" class="button zl-sp-remove" onclick="this.closest(\'tr\').remove();tslUpdateSalespeople();">✕</button></td>';
                    tbody.appendChild(row);
                    tslUpdateSalespeople();
                }
                function tslUpdateSalespeople() {
                // Serialize the table rows into JSON and update the hidden input field
                    var rows = document.querySelectorAll('.zl-sp-row');
                    var data = [];
                    rows.forEach(function(row) {
                        var code = row.querySelector('.zl-sp-code').value.trim();
                        var name = row.querySelector('.zl-sp-name').value.trim();
                        var territories = row.querySelector('.zl-sp-territories').value.trim();
                        if (code && name) data.push({code: code, name: name, territories: territories});
                    });
                    document.getElementById('zl-salespeople-json').value = JSON.stringify(data);
                }
                document.addEventListener('input', function(e) {
                // Update the hidden JSON field automatically on any input change
                    if (e.target.classList.contains('zl-sp-code') || e.target.classList.contains('zl-sp-name') || e.target.classList.contains('zl-sp-territories')) {
                        tslUpdateSalespeople();
                    }
                });
                </script>
                <hr>

                <!-- ── Batch Settings ────────────────────────────── -->

                <h2>⚙️ Batch Settings</h2>
                <table class="form-table">
                    <tr>
                        <th>Leads Per Batch</th>
                        <td><input type="number" name="zl_leads_per_batch" min="1" max="200" value="<?php echo esc_attr( get_option( 'zl_leads_per_batch', 50 ) ); ?>" class="small-text" />
                        <p class="description">Number of leads to generate per full batch (default: 50).</p></td>
                    </tr>
                    <tr>
                        <th>FreshBooks Lookback</th>
                        <td>
                            <select name="zl_lookback_days">
                                <?php
                                $current_lookback = (int) get_option( 'zl_lookback_days', 730 );
                                $options = array(
                                // Options updated in v1.2.0 to include extended lookback (Since 2000)
                                    180  => '6 Months',
                                    365  => '1 Year',
                                    730  => '2 Years',
                                    1095 => '3 Years',
                                    1825 => '5 Years',
                                    3650 => '10 Years',
                                    5475 => '15 Years',
                                    9500 => 'Since 2000 (~26 years)',
                                );
                                foreach ( $options as $val => $label ) {
                                    printf( '<option value="%d" %s>%s</option>', $val, selected( $current_lookback, $val, false ), esc_html( $label ) );
                                }
                                ?>
                            </select>
                            <p class="description">How far back to pull purchase history from FreshBooks.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Cooldown Period (days)</th>
                        <td><input type="number" name="zl_cooldown_days" min="0" max="365" value="<?php echo esc_attr( get_option( 'zl_cooldown_days', 90 ) ); ?>" class="small-text" />
                        <p class="description">Don't regenerate same customer as a lead within this many days.</p></td>
                    </tr>
                    <tr>
                        <th>Excluded Companies</th>
                        <td><textarea name="zl_excluded_companies" rows="3" style="width:400px;" placeholder="One company name per line"><?php echo esc_textarea( get_option( 'zl_excluded_companies', '' ) ); ?></textarea>
                        <p class="description">One company name per line (case-insensitive). These will be skipped.</p></td>
                    </tr>
                </table>
                <hr>

                <!-- ── API Credentials ──────────────────────────── -->

                <h2>🔑 FreshBooks API</h2>
                <p class="description">Leave blank to use credentials from TS Satisfaction Surveys plugin (if installed).</p>
                <table class="form-table">
                    <tr>
                        <th>Client ID</th>
                        <td><input type="text" name="zl_fb_client_id" value="<?php echo esc_attr( get_option( 'zl_fb_client_id' ) ); ?>" class="regular-text" placeholder="<?php echo $survey_fb ? 'Using survey plugin credentials' : ''; ?>" /></td>
                    </tr>
                    <tr>
                        <th>Client Secret</th>
                        <td><input type="password" name="zl_fb_client_secret" value="<?php echo get_option( 'zl_fb_client_secret' ) ? '********' : ''; ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th>Account ID</th>
                        <td><input type="text" name="zl_fb_account_id" value="<?php echo esc_attr( get_option( 'zl_fb_account_id' ) ); ?>" class="regular-text" readonly /></td>
                    </tr>
                </table>
                <?php if ( $client_id ) : ?>
                    <p>
                        <a href="<?php echo esc_url( $oauth_url ); ?>" class="button button-secondary">Connect FreshBooks</a>
                        &nbsp;
                        <button type="button" id="zl-test-fb" class="button button-secondary" style="background:#f0f7ff;border-color:#2271b1;">🔍 Test FreshBooks Connection</button>
                    </p>
                    <div id="zl-fb-test-results" style="display:none;margin:10px 0;padding:12px 16px;background:#fafafa;border:1px solid #ccd0d4;border-radius:4px;max-width:700px;font-family:monospace;font-size:13px;white-space:pre-wrap;line-height:1.6;"></div>
                    <script>
                    document.getElementById('zl-test-fb').addEventListener('click', function() {
                        var btn = this;
                        var box = document.getElementById('zl-fb-test-results');
                        btn.disabled = true;
                        btn.textContent = '⏳ Testing...';
                        box.style.display = 'block';
                        box.textContent = 'Running FreshBooks diagnostic tests...\n';

                        var fd = new FormData();
                        fd.append('action', 'zl_test_freshbooks');
                        fd.append('nonce', '<?php echo wp_create_nonce("zl_settings_nonce"); ?>');

                        fetch(ajaxurl, { method: 'POST', body: fd })
                            .then(function(r) { return r.json(); })
                            .then(function(resp) {
                                var d = resp.data || {};
                                var out = '═══ FreshBooks Diagnostic Report ═══\n\n';

                                // Credentials
                                var c = d.credentials || {};
                                out += '📋 CREDENTIALS\n';
                                out += '  Client ID:    ' + (c.client_id || '?') + '\n';
                                out += '  Client Secret: ' + (c.client_secret || '?') + '\n';
                                out += '  Account ID:   ' + (c.account_id || '?') + '\n';
                                out += '  Access Token: ' + (c.access_token || '?') + '\n';
                                out += '  Token Source: ' + (c.token_source || '?') + '\n\n';

                                if (d.error) {
                                    out += '❌ ' + d.error + '\n';
                                    box.textContent = out;
                                    btn.disabled = false;
                                    btn.textContent = '🔍 Test FreshBooks Connection';
                                    return;
                                }

                                // Identity test
                                var id = d.test_identity || {};
                                out += '👤 IDENTITY TEST (' + (id.status || '?') + ')\n';
                                if (id.status === 'OK') {
                                    out += '  Email:       ' + (id.email || 'N/A') + '\n';
                                    out += '  Businesses:  ' + (id.businesses || []).join(', ') + '\n';
                                    out += '  Account IDs: ' + (id.account_ids || []).join(', ') + '\n';
                                    out += '  Using ID:    ' + (id.using_id || '?') + '\n';
                                    out += '  ID Match:    ' + (id.match || '?') + '\n';
                                } else {
                                    out += '  HTTP ' + (id.http_code || '?') + ': ' + (id.body || id.message || 'Unknown error') + '\n';
                                }
                                out += '\n';

                                // Any invoices test
                                var any = d.test_any_invoices || {};
                                out += '📄 ANY INVOICES TEST (' + (any.status || '?') + ')\n';
                                out += '  ' + (any.message || 'N/A') + '\n\n';

                                // Paid invoices test
                                var paid = d.test_paid_invoices || {};
                                out += '💰 PAID INVOICES TEST (' + (paid.status || '?') + ')\n';
                                out += '  ' + (paid.message || 'N/A') + '\n';
                                if (paid.date_min) out += '  Date min: ' + paid.date_min + '\n';
                                out += '\n';

                                // v1.5.3 — Pipeline mirror test (include[]=lines)
                                var pipe = d.test_pipeline_mirror || {};
                                out += '🔧 PIPELINE MIRROR TEST (' + (pipe.status || '?') + ')\n';
                                out += '  ' + (pipe.message || 'N/A') + '\n';
                                if (pipe.lookback_days) out += '  Lookback: ' + pipe.lookback_days + ' days (date_min: ' + pipe.date_min + ')\n';
                                if (pipe.url) out += '  URL: ' + pipe.url + '\n';
                                if (pipe.status === 'MISMATCH') {
                                    out += '  ⚡ This means include[]=lines is causing the 0-invoice bug.\n';
                                    out += '  ⚡ v1.5.3 has an automatic fallback to work around this.\n';
                                }

                                // Summary
                                out += '\n═══════════════════════════════════\n';
                                if (id.match === 'NO — MISMATCH!') {
                                    out += '⚠️ PROBLEM: Account ID mismatch! Click "Connect FreshBooks" to fix.\n';
                                } else if (any.total === 0 || any.total === '0') {
                                    out += '⚠️ PROBLEM: 0 invoices in this account. The Account ID may be wrong.\n';
                                } else if (paid.total === 0 || paid.total === '0') {
                                    out += '⚠️ PROBLEM: Invoices exist but 0 are marked as "paid" (status=4).\n';
                                } else if (id.status === 'FAILED') {
                                    out += '⚠️ PROBLEM: Token is invalid/expired. Click "Connect FreshBooks".\n';
                                } else if (pipe.status === 'MISMATCH') {
                                    out += '⚠️ include[]=lines bug detected! v1.5.3 auto-fallback is active.\n';
                                    out += '✅ Pipeline will fetch invoices without line items (product filter limited).\n';
                                } else if (paid.total && parseInt(paid.total) > 0) {
                                    out += '✅ Everything looks good! ' + paid.total + ' paid invoices found.\n';
                                    if (pipe.status === 'OK') {
                                        out += '✅ Pipeline mirror test passed — include[]=lines is working.\n';
                                    }
                                }

                                box.textContent = out;
                                btn.disabled = false;
                                btn.textContent = '🔍 Test FreshBooks Connection';
                            })
                            .catch(function(err) {
                                box.textContent = '❌ Request failed: ' + err.message;
                                btn.disabled = false;
                                btn.textContent = '🔍 Test FreshBooks Connection';
                            });
                    });
                    </script>
                <?php endif; ?>
                <hr>
                <h2>🔑 Nutshell CRM API</h2>

                <p class="description">Leave blank to use credentials from TS Satisfaction Surveys plugin.</p>

                <table class="form-table">
                    <tr>
                        <th>Nutshell Email</th>
                        <td><input type="email" name="zl_ns_email" value="<?php echo esc_attr( get_option( 'zl_ns_email' ) ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th>API Key</th>
                        <td><input type="password" name="zl_ns_api_key" value="<?php echo get_option( 'zl_ns_api_key' ) ? '********' : ''; ?>" class="regular-text" /></td>
                    </tr>
                </table>
                <hr>
                <h2>🤖 Poe AI (LLM Integration)</h2>

                <p class="description">Used for purchase description refinement and batch summaries. Leave blank to use Survey plugin credentials.</p>

                <table class="form-table">
                    <tr>
                        <th>Poe API Key</th>
                        <td><input type="password" name="zl_poe_api_key" value="<?php echo get_option( 'zl_poe_api_key' ) ? '********' : ''; ?>" class="regular-text" />
                        <p class="description">Get from <a href="https://poe.com/api_key" target="_blank">poe.com/api_key</a></p></td>
                    </tr>
                    <tr>
                        <th>AI Model</th>
                        <td>
                            <select name="zl_ai_model">
                                <?php
                                $current_model = get_option( 'zl_ai_model', 'Gemini-3.1-Pro' );
                                $models = array(
                                // Gemini-3.1-Pro is the recommended model for the business's setup
                                // Uses thinking_budget=32768 and web_search=true via the Poe API client
                                    'Gemini-3.1-Pro'              => 'Gemini 3.1 Pro — High Reasoning + Web (Recommended)',
                                    'Gemini-3-Flash'              => 'Gemini 3 Flash (Fast & Affordable)',
                                    'GPT-5.2'                     => 'GPT 5.2 (Versatile)',
                                    'Claude-Sonnet-4.5'           => 'Claude Sonnet 4.5 (Quality)',
                                    'Grok-4.1-Fast-Non-Reasoning' => 'Grok 4.1 Fast (Very Fast)',
                                );
                                foreach ( $models as $val => $label ) {
                                    printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( $current_model, $val, false ), esc_html( $label ) );
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Save Settings' ); ?>
            </form>

            <!-- ── Permission Management (v1.5.0) ──────────────── -->
            <hr>
            <h2>🔐 Widget Permissions</h2>
            <p class="description">
                Control which features each role or specific username can access in the frontend dashboard widget.
                <br>These settings apply to the the app inline widget — the backend WP Admin dashboard is always fully accessible to administrators.
            </p>

            <?php
            $perm_config = ZL_Permissions::get_config();
            $perm_defs   = ZL_Permissions::get_feature_definitions();
            $all_keys    = ZL_Permissions::get_all_feature_keys();
            ?>

            <div id="zl-permissions-ui" style="background:#fff; border:1px solid #ccd0d4; padding:20px; margin-top:10px;">

                <!-- Role Permissions -->
                <h3>Role Permissions</h3>
                <p class="description">Select which features each Zorderz role can access.</p>

                <table class="widefat" style="margin-bottom:20px;">
                    <thead>
                        <tr>
                            <th style="width:200px;">Feature</th>
                            <th style="width:120px;">zdz_owner</th>
                            <th style="width:120px;">zdz_admin</th>
                            <th style="width:120px;">zdz_sales</th>
                            <th style="width:120px;">zdz_operator</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $owner_features    = $perm_config['roles']['zdz_owner'] ?? array();
                    $admin_features    = $perm_config['roles']['zdz_admin'] ?? array();
                    $sales_features    = $perm_config['roles']['zdz_sales'] ?? array();
                    $operator_features = $perm_config['roles']['zdz_operator'] ?? array();
                    $owner_has_all     = in_array( 'all', $owner_features, true );
                    $admin_has_all     = in_array( 'all', $admin_features, true );

                    foreach ( $perm_defs as $group_key => $group ) :
                        ?>
                        <tr style="background:#f0f0f1;">
                            <td colspan="5"><strong><?php echo esc_html( $group['label'] ); ?></strong></td>
                        </tr>
                        <?php foreach ( $group['features'] as $feat_key => $feat ) : ?>
                        <tr>
                            <td>
                                <?php echo esc_html( $feat['label'] ); ?>
                                <br><small style="color:#6b7280;"><?php echo esc_html( $feat['desc'] ); ?></small>
                            </td>
                            <td>
                                <input type="checkbox"
                                       class="zl-perm-role-cb"
                                       data-role="zdz_owner"
                                       data-feature="<?php echo esc_attr( $feat_key ); ?>"
                                       <?php checked( $owner_has_all || in_array( $feat_key, $owner_features, true ) ); ?>
                                       <?php if ( $owner_has_all ) echo 'disabled title="Owner has all permissions"'; ?>
                                />
                            </td>
                            <td>
                                <input type="checkbox"
                                       class="zl-perm-role-cb"
                                       data-role="zdz_admin"
                                       data-feature="<?php echo esc_attr( $feat_key ); ?>"
                                       <?php checked( $admin_has_all || in_array( $feat_key, $admin_features, true ) ); ?>
                                       <?php if ( $admin_has_all ) echo 'disabled title="Admin has all permissions"'; ?>
                                />
                            </td>
                            <td>
                                <input type="checkbox"
                                       class="zl-perm-role-cb"
                                       data-role="zdz_sales"
                                       data-feature="<?php echo esc_attr( $feat_key ); ?>"
                                       <?php checked( in_array( $feat_key, $sales_features, true ) ); ?>
                                />
                            </td>
                            <td>
                                <input type="checkbox"
                                       class="zl-perm-role-cb"
                                       data-role="zdz_operator"
                                       data-feature="<?php echo esc_attr( $feat_key ); ?>"
                                       <?php checked( in_array( $feat_key, $operator_features, true ) ); ?>
                                />
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <p><label>
                    <input type="checkbox" id="zl-perm-owner-all" <?php checked( $owner_has_all ); ?> />
                    <strong>zdz_owner gets ALL permissions</strong> (overrides individual checkboxes)
                </label></p>
                <p><label>
                    <input type="checkbox" id="zl-perm-admin-all" <?php checked( $admin_has_all ); ?> />
                    <strong>zdz_admin gets ALL permissions</strong> (overrides individual checkboxes)
                </label></p>

                <!-- Username Overrides -->
                <h3 style="margin-top:20px;">Username Overrides</h3>
                <p class="description">
                    Grant or revoke specific features for individual usernames.
                    Prefix with <code>!</code> to revoke (e.g., <code>!view_pricing</code> hides dollar amounts for that user).
                    Without prefix, the feature is granted even if their role doesn't include it.
                </p>

                <table class="widefat" id="zl-perm-users-table" style="margin-bottom:10px;">
                    <thead>
                        <tr>
                            <th style="width:200px;">Username</th>
                            <th>Feature Overrides (comma-separated)</th>
                            <th style="width:60px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $user_overrides = $perm_config['users'] ?? array();
                    if ( empty( $user_overrides ) ) :
                    ?>
                        <tr class="zl-perm-user-row">
                            <td><input type="text" class="zl-perm-username" value="" style="width:100%;" placeholder="e.g. john_doe" /></td>
                            <td><input type="text" class="zl-perm-user-features" value="" style="width:100%;" placeholder="e.g. !view_pricing, can_generate_full" /></td>
                            <td><button type="button" class="button zl-perm-user-remove" onclick="this.closest('tr').remove();">✕</button></td>
                        </tr>
                    <?php
                    else :
                        foreach ( $user_overrides as $uname => $feats ) :
                    ?>
                        <tr class="zl-perm-user-row">
                            <td><input type="text" class="zl-perm-username" value="<?php echo esc_attr( $uname ); ?>" style="width:100%;" /></td>
                            <td><input type="text" class="zl-perm-user-features" value="<?php echo esc_attr( implode( ', ', $feats ) ); ?>" style="width:100%;" /></td>
                            <td><button type="button" class="button zl-perm-user-remove" onclick="this.closest('tr').remove();">✕</button></td>
                        </tr>
                    <?php
                        endforeach;
                    endif;
                    ?>
                    </tbody>
                </table>
                <button type="button" class="button" onclick="tslAddPermUserRow()">+ Add Username Override</button>

                <p style="margin-top:15px;">
                    <button type="button" class="button button-primary" id="zl-perm-save-btn" onclick="tslSavePermissions()">
                        Save Permissions
                    </button>
                    <span id="zl-perm-save-status" style="margin-left:10px;font-weight:600;"></span>
                </p>

                <script>
                function tslAddPermUserRow() {
                    var tbody = document.querySelector('#zl-perm-users-table tbody');
                    var row = document.createElement('tr');
                    row.className = 'zl-perm-user-row';
                    row.innerHTML = '<td><input type="text" class="zl-perm-username" value="" style="width:100%;" placeholder="e.g. jane_smith" /></td>' +
                        '<td><input type="text" class="zl-perm-user-features" value="" style="width:100%;" placeholder="e.g. !view_pricing, can_generate_full" /></td>' +
                        '<td><button type="button" class="button zl-perm-user-remove" onclick="this.closest(\'tr\').remove();">✕</button></td>';
                    tbody.appendChild(row);
                }

                function tslSavePermissions() {
                    var btn    = document.getElementById('zl-perm-save-btn');
                    var status = document.getElementById('zl-perm-save-status');
                    btn.disabled = true;
                    status.textContent = 'Saving...';
                    status.style.color = '#2271b1';

                    // Build roles config
                    var roles = {};
                    var ownerAll = document.getElementById('zl-perm-owner-all').checked;
                    var adminAll = document.getElementById('zl-perm-admin-all').checked;

                    if (ownerAll) {
                        roles['zdz_owner'] = ['all'];
                    } else {
                        roles['zdz_owner'] = [];
                        document.querySelectorAll('.zl-perm-role-cb[data-role="zdz_owner"]:checked').forEach(function(cb) {
                            roles['zdz_owner'].push(cb.dataset.feature);
                        });
                    }

                    if (adminAll) {
                        roles['zdz_admin'] = ['all'];
                    } else {
                        roles['zdz_admin'] = [];
                        document.querySelectorAll('.zl-perm-role-cb[data-role="zdz_admin"]:checked').forEach(function(cb) {
                            roles['zdz_admin'].push(cb.dataset.feature);
                        });
                    }

                    roles['zdz_sales'] = [];
                    document.querySelectorAll('.zl-perm-role-cb[data-role="zdz_sales"]:checked').forEach(function(cb) {
                        roles['zdz_sales'].push(cb.dataset.feature);
                    });

                    roles['zdz_operator'] = [];
                    document.querySelectorAll('.zl-perm-role-cb[data-role="zdz_operator"]:checked').forEach(function(cb) {
                        roles['zdz_operator'].push(cb.dataset.feature);
                    });

                    // Build users config
                    var users = {};
                    document.querySelectorAll('.zl-perm-user-row').forEach(function(row) {
                        var uname = row.querySelector('.zl-perm-username').value.trim();
                        var feats = row.querySelector('.zl-perm-user-features').value.trim();
                        if (uname && feats) {
                            users[uname] = feats.split(',').map(function(f) { return f.trim(); }).filter(Boolean);
                        }
                    });

                    var config = { roles: roles, users: users };

                    // AJAX save
                    var fd = new FormData();
                    fd.append('action', 'zl_save_permissions');
                    fd.append('nonce', '<?php echo wp_create_nonce( 'zl_nonce' ); ?>');
                    fd.append('config', JSON.stringify(config));

                    fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                        method: 'POST',
                        body: fd,
                        credentials: 'same-origin'
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        btn.disabled = false;
                        if (res.success) {
                            status.textContent = '✅ Permissions saved!';
                            status.style.color = '#065f46';
                            setTimeout(function() { status.textContent = ''; }, 3000);
                        } else {
                            status.textContent = '❌ ' + (res.data || 'Save failed');
                            status.style.color = '#991b1b';
                        }
                    })
                    .catch(function(err) {
                        btn.disabled = false;
                        status.textContent = '❌ Network error';
                        status.style.color = '#991b1b';
                    });
                }

                // Toggle admin checkboxes when "all" is toggled
                document.getElementById('zl-perm-admin-all').addEventListener('change', function() {
                    var cbs = document.querySelectorAll('.zl-perm-role-cb[data-role="zdz_admin"]');
                    var allChecked = this.checked;
                    cbs.forEach(function(cb) {
                        cb.checked = allChecked;
                        cb.disabled = allChecked;
                        if (allChecked) cb.title = 'Admin has all permissions';
                        else cb.title = '';
                    });
                });
                </script>

                <!-- Available feature keys reference -->
                <details style="margin-top:15px;">
                    <summary style="cursor:pointer;color:#6b7280;font-size:12px;">Available feature keys reference</summary>
                    <ul style="font-size:12px;color:#6b7280;columns:2;">
                        <?php foreach ( $all_keys as $key ) : ?>
                            <li><code><?php echo esc_html( $key ); ?></code></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            </div>

        </div>
        <?php
    }
}
new ZL_Admin();