<?php
/**
 * FILE: class-zl-poe-client.php
 * MODULE: Zorderz Leads
 *
 * ARCHITECTURE ROLE:
 * A thin, leads-shaped ADAPTER over the shared platform AI gateway
 * (ZDZ_Core_Poe). It preserves the leads-specific call surface — a string-prompt
 * query() and a parallel query_parallel() — and the leads message-assembly
 * conventions (system + user roles, deterministic temperature), while routing
 * every request through the ONE choke: the private provider endpoint POST, the
 * retired hardcoded model handle, the in-request sleep()/backoff and the SSE
 * fallback all live in the gateway now, not here.
 *
 * WHY (INV-4 / one-choke): every AI call on the platform must leave through a
 * single repaired client so stream:false, the per-family reasoning knob, the
 * no-sleep retry, the registry remap and the WP_Error+status contract are applied
 * uniformly. This adapter keeps leads' callers unchanged while making that real.
 *
 * The concrete model is resolved from config (the zl_ai_model option / the model
 * registry) with a NEUTRAL, empty default — a business's model comes from its
 * connections pack; Core ships no dead handle. The credential is read by the
 * gateway from Core settings (plugins never pass a key — INV-4).
 *
 * Reliability: each call is routed past Zdz_Service_Breaker (a sick upstream is
 * short-circuited; three consecutive 401s hit the auth wall WITHOUT tripping the
 * breaker, because a 401 is our config, not a sick upstream) and its cost is
 * observed for Zdz_Request_Guard's adaptive reservation, so a slow AI batch can't
 * exhaust workers. The cURL clamp applies automatically whenever an enclosing
 * sweep has a budget open (and no-ops otherwise, keeping interactive patience).
 *
 * CALLERS (contract unchanged):
 * - ZL_Lead_Generator::expand_filter_with_ai()
 * - ZL_Lead_Generator::validate_lead_with_ai()
 * - ZL_Lead_Generator::refine_lead_description()
 * - ZL_Lead_Generator::finalize_batch()
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ZL_Poe_Client {

    /**
     * @var string
     * Retained for constructor-signature back-compat only. The gateway reads the
     * single platform credential from Core settings (INV-4); this is not sent.
     */
    private $api_key;

    /**
     * @var string
     * Default model handle for this client. May be '' (neutral) — resolved from
     * the registry / zl_ai_model option at call time. No vendor literal here.
     */
    private $default_bot;

    /** @var ZDZ_Core_Poe|null Lazily-built shared gateway, reused across calls. */
    private $gateway = null;

    /**
     * Constructor.
     *
     * @param string $api_key     Ignored for the request (kept for back-compat).
     * @param string $default_bot Default model handle; '' resolves from config.
     */
    public function __construct( $api_key = '', $default_bot = '' ) {
        $this->api_key     = $api_key;
        $this->default_bot = (string) $default_bot;
    }

    /** The neutral, filterable service key for the breaker/guard (never a brand). */
    private function service() {
        return (string) apply_filters( 'zl_ai_service', 'leads_ai' );
    }

    /** Build (once) and return the shared gateway. */
    private function gateway() {
        if ( $this->gateway instanceof ZDZ_Core_Poe ) {
            return $this->gateway;
        }
        // No api_key argument: the gateway owns the single credential (INV-4).
        $this->gateway = new ZDZ_Core_Poe();
        return $this->gateway;
    }

    /**
     * Resolve a concrete model handle from an explicit request, then the client
     * default, then the registry — with a neutral empty default. An empty result
     * is passed through to the gateway, which surfaces a diagnosable "no model
     * configured" error rather than a dead hardcoded handle.
     */
    private function resolve_model( $requested ) {
        $model = $requested ? (string) $requested : $this->default_bot;
        if ( '' === $model && class_exists( 'ZDZ_Model_Registry' ) ) {
            // The leads planner/enrichment slot; config-driven, ships empty.
            $model = (string) ZDZ_Model_Registry::model_for( 'planner' );
        }
        return $model;
    }

    /**
     * Query the AI and return the full text response.
     *
     * Preserves the leads call surface: a string prompt + optional system prompt
     * assembled into role messages, deterministic temperature by default, and
     * bot-specific extras (e.g. thinking_budget / web_search) passed through for
     * the gateway's reasoning-family translation. Throws on error, exactly as the
     * previous private client did, so callers that catch Throwable are unchanged.
     *
     * @param string      $prompt        The user prompt.
     * @param string|null $bot_name      Optional model handle (defaults to config).
     * @param string      $system_prompt Optional system instructions.
     * @param float|null  $temperature   Optional temperature (null → 0.0 default).
     * @param array       $extra_params  Optional extras (thinking_budget, web_search…).
     * @return string The assistant text.
     * @throws Exception On any gateway error (after the gateway's own no-sleep retry).
     */
    public function query( $prompt, $bot_name = null, $system_prompt = '', $temperature = null, $extra_params = array() ) {
        if ( ! class_exists( 'ZDZ_Core_Poe' ) ) {
            throw new Exception( 'AI gateway (ZDZ_Core_Poe) is unavailable.' );
        }

        $service = $this->service();

        // Short-circuit a sick upstream: don't hold a worker on a service that is
        // already paused by the breaker.
        if ( class_exists( 'Zdz_Service_Breaker' ) && Zdz_Service_Breaker::is_open( $service ) ) {
            throw new Exception( 'AI service temporarily paused (circuit breaker open).' );
        }

        $model = $this->resolve_model( $bot_name );

        // Leads-specific message assembly (system + user).
        $messages = array();
        if ( ! empty( $system_prompt ) ) {
            $messages[] = array( 'role' => 'system', 'content' => $system_prompt );
        }
        $messages[] = array( 'role' => 'user', 'content' => (string) $prompt );

        $temp  = ( null !== $temperature ) ? (float) $temperature : 0.0;
        $extra = is_array( $extra_params ) ? $extra_params : array();

        $t      = microtime( true );
        $result = $this->gateway()->query( $messages, $temp, $extra, $model );
        $cost   = microtime( true ) - $t;

        // Feed the enclosing sweep's adaptive reservation (no-op if no budget open).
        if ( class_exists( 'Zdz_Request_Guard' ) ) {
            Zdz_Request_Guard::observe( $service, $cost );
        }

        // Error paths — support both the gateway's WP_Error return and the legacy
        // 'Error: …' string sentinel, converting either to a thrown Exception.
        if ( is_wp_error( $result ) ) {
            $data    = $result->get_error_data();
            $status  = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 0;
            $message = $result->get_error_message();
            $this->note_failure( $service, $status, $message );
            throw new Exception( 'AI error: ' . $message );
        }

        $text = (string) $result;
        if ( 0 === strncmp( $text, 'Error: ', 7 ) ) {
            $this->note_failure( $service, $this->sniff_status( $text ), $text );
            throw new Exception( $text );
        }

        if ( class_exists( 'Zdz_Service_Breaker' ) ) {
            Zdz_Service_Breaker::record_success( $service );
        }
        return $text;
    }

    /**
     * Route a failure to the breaker: an auth failure (401/403) hits the AUTH
     * WALL — counted, aborts after the threshold, but does NOT trip the breaker
     * (a 401 is our config, not a sick upstream). Anything else is a hard failure.
     */
    private function note_failure( $service, $status, $message ) {
        if ( ! class_exists( 'Zdz_Service_Breaker' ) ) {
            return;
        }
        $is_auth = in_array( (int) $status, array( 401, 403 ), true )
            || false !== stripos( (string) $message, 'rejected the API key' )
            || false !== stripos( (string) $message, 'No Poe API key' );
        if ( $is_auth ) {
            Zdz_Service_Breaker::record_auth_failure( $service );
        } else {
            Zdz_Service_Breaker::record_failure( $service );
        }
    }

    /** Best-effort HTTP status sniff from a legacy 'Error: …' string. */
    private function sniff_status( $text ) {
        if ( preg_match( '/HTTP\s+(\d{3})/', (string) $text, $m ) ) {
            return (int) $m[1];
        }
        return 0;
    }

    /**
     * Dispatch many AI queries in parallel through the shared gateway.
     *
     * Preserves the leads item shape and the result contract
     * ( id => { text, error, status, was_429 } ) the batch refiner consumes. When
     * the gateway exposes query_many() the whole fan-out (and the SSE decode) is
     * the gateway's; otherwise this falls back to a serial loop that still routes
     * every call through the gateway via query(). Either way there is no private
     * provider request here.
     *
     * @param array[]        $items       Each: { id, prompt, system, temperature, extra_params, bot }.
     * @param int            $cap         Concurrency cap (gateway clamps).
     * @param callable|null  $on_progress fn(id, result_arr, done, total).
     * @return array id => { text:string, error:string, status:int, was_429:bool }
     */
    public function query_parallel( array $items, $cap = 4, $on_progress = null ) {
        if ( empty( $items ) ) {
            return array();
        }

        $service = $this->service();

        // If the upstream is already paused, degrade to the safe "no refinement"
        // shape rather than starting a parallel batch against a sick service.
        if ( class_exists( 'Zdz_Service_Breaker' ) && Zdz_Service_Breaker::is_open( $service ) ) {
            $out = array();
            foreach ( $items as $i => $it ) {
                $id = (string) ( $it['id'] ?? ( 'i' . $i ) );
                $out[ $id ] = array( 'text' => '', 'error' => 'breaker_open', 'status' => 0, 'was_429' => false );
            }
            return $out;
        }

        // Preferred path: the shared gateway's parallel dispatch.
        if ( class_exists( 'ZDZ_Core_Poe' ) && method_exists( 'ZDZ_Core_Poe', 'query_many' ) ) {
            $requests = array();
            foreach ( $items as $i => $it ) {
                $id       = (string) ( $it['id'] ?? ( 'i' . $i ) );
                $messages = array();
                if ( ! empty( $it['system'] ) ) {
                    $messages[] = array( 'role' => 'system', 'content' => $it['system'] );
                }
                $messages[] = array( 'role' => 'user', 'content' => (string) ( $it['prompt'] ?? '' ) );

                $temp = ( isset( $it['temperature'] ) && $it['temperature'] !== null ) ? (float) $it['temperature'] : 0.0;

                $requests[] = array(
                    'id'           => $id,
                    'messages'     => $messages,
                    'temperature'  => $temp,
                    'extra_params' => ( isset( $it['extra_params'] ) && is_array( $it['extra_params'] ) ) ? $it['extra_params'] : array(),
                    'model'        => $this->resolve_model( $it['bot'] ?? '' ),
                );
            }
            return $this->gateway()->query_many( $requests, max( 1, (int) $cap ), $on_progress );
        }

        // Serial fallback (gateway lacks query_many): still routes through the
        // gateway via query(), preserving the exact return shape.
        return $this->serial_fallback( $items, $on_progress );
    }

    /** Serial equivalent of query_parallel — same result contract, via query(). */
    private function serial_fallback( array $items, $on_progress = null ) {
        $out   = array();
        $total = count( $items );
        $done  = 0;
        foreach ( $items as $i => $it ) {
            $id = (string) ( $it['id'] ?? ( 'i' . $i ) );
            try {
                $text = $this->query(
                    $it['prompt']      ?? '',
                    $it['bot']         ?? null,
                    $it['system']      ?? '',
                    $it['temperature'] ?? null,
                    $it['extra_params'] ?? array()
                );
                $out[ $id ] = array( 'text' => $text, 'error' => '', 'status' => 200, 'was_429' => false );
            } catch ( \Throwable $e ) {
                $msg     = $e->getMessage();
                $was_429 = ( false !== strpos( $msg, '429' ) );
                $out[ $id ] = array( 'text' => '', 'error' => $msg, 'status' => $was_429 ? 429 : 0, 'was_429' => $was_429 );
            }
            $done++;
            if ( is_callable( $on_progress ) ) {
                try { call_user_func( $on_progress, $id, $out[ $id ], $done, $total ); }
                catch ( \Throwable $e ) { /* noop */ }
            }
        }
        return $out;
    }
}
