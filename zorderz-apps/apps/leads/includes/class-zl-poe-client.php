<?php
/**
 * FILE: class-zl-poe-client.php
 * MODULE: Zorderz Leads
 * 
 * ARCHITECTURE ROLE:
 * This file contains the Poe AI API client. It acts as the bridge between the core 
 * lead generation engine (class-zl-leads.php) and the Poe API, utilizing 
 * their OpenAI-compatible endpoint.
 * 
 * BUSINESS CONTEXT:
 * the business uses this to connect to Gemini-3.1-Pro for AI-driven 
 * lead qualification and enrichment. The AI handles a 3-layer product filtering 
 * process (expansion -> keyword match -> strict validation) and rewrites purchase 
 * descriptions to fit within Nutshell CRM's strict <101 character limit.
 * 
 * KEY FEATURES:
 * - OpenAI-compatible payload structure.
 * - Supports bot-specific parameters like `thinking_budget=32768` and `web_search=true` 
 *   required for Gemini-3.1-Pro.
 * - Robust error handling: Automatically retries on 502/503/504 Gateway errors 
 *   (introduced in v1.2.0, critical for the v1.2.1 AJAX split fix) with exponential backoff.
 * - Fallback SSE (Server-Sent Events) parsing in case the API ignores `stream=false`.
 * 
 * CALLERS:
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
     * Poe API key (Bearer token). Falls back to WP options if not set.
     */
    private $api_key;

    /** 
     * @var string 
     * Default bot name for LLM tasks. Usually 'Gemini-3.1-Pro'.
     */
    private $default_bot;

    /** 
     * @var string 
     * Poe's OpenAI-compatible chat completions endpoint.
     */
    private $api_endpoint = 'https://api.poe.com/v1/chat/completions';

    /** 
     * @var int 
     * Max retries for gateway errors (502, 503, 504) or connection timeouts.
     */
    private $max_retries = 3;

    /**
     * Constructor.
     * 
     * Initializes the client with credentials and the target AI model.
     *
     * @param string $api_key     Poe API key (Bearer token).
     * @param string $default_bot Default bot name for LLM tasks (Business default: Gemini-3.1-Pro).
     */
    public function __construct( $api_key, $default_bot = 'Gemini-3.1-Pro' ) {
        $this->api_key     = $api_key;
        $this->default_bot = $default_bot;
    }

    /**
     * Query a Poe bot and return the full text response.
     *
     * PURPOSE:
     * Sends a synchronous chat completion request to the Poe API. Includes robust 
     * exponential backoff for transient network and gateway errors to ensure the 
     * 8-step generation pipeline doesn't crash mid-batch.
     *
     * SIDE EFFECTS:
     * - Makes external HTTP requests (blocks execution, hence 120s timeout).
     * - Logs retry attempts and errors to the WordPress debug log.
     *
     * @param string      $prompt        The user prompt (e.g., product descriptions, validation rules).
     * @param string|null $bot_name      Optional bot name (defaults to constructor value).
     * @param string      $system_prompt Optional system instructions (e.g., "You are a strict data formatter").
     * @param float|null  $temperature   Optional temperature (0.0 = deterministic, 2.0 = max creativity). Null = bot default.
     * @param array       $extra_params  Optional bot-specific parameters (e.g. thinking_budget, web_search).
     * @return string The complete text response from the AI.
     * @throws Exception On API errors (after all retries exhausted).
     */
    public function query( $prompt, $bot_name = null, $system_prompt = '', $temperature = null, $extra_params = array() ) {
        $bot_name = $bot_name ? $bot_name : $this->default_bot;

        // Build the OpenAI-compatible messages array
        $messages = array();
        if ( ! empty( $system_prompt ) ) {
            $messages[] = array( 'role' => 'system', 'content' => $system_prompt );
        }
        $messages[] = array( 'role' => 'user', 'content' => $prompt );

        // Base request payload
        $body = array(
            'model'    => $bot_name,
            'messages' => $messages,
            'stream'   => false,
        );

        // Set temperature when explicitly provided (0 = deterministic, no creativity)
        // Crucial for the strict <101 char rewriting and boolean validation steps.
        if ( $temperature !== null ) {
            $body['temperature'] = (float) $temperature;
        }

        // Merge bot-specific parameters (e.g. thinking_budget, web_search) into request body
        // This allows Gemini-3.1-Pro to use extended reasoning and live search for commercial entity checks.
        if ( ! empty( $extra_params ) && is_array( $extra_params ) ) {
            foreach ( $extra_params as $key => $value ) {
                $body[ $key ] = $value;
            }
        }

        $delay          = 3; // Initial retry delay in seconds
        $last_exception = null;

        // Retry loop for transient errors
        for ( $attempt = 1; $attempt <= $this->max_retries; $attempt++ ) {
            error_log( 'ZL Poe: Querying bot=' . $bot_name . ' attempt=' . $attempt . '/' . $this->max_retries . ' params=' . wp_json_encode( $extra_params ) );

            // Execute the remote POST request with a long timeout (180s) to accommodate AI thinking time.
            // The AI filter expansion prompt (500+ line items with thinking_budget=32768) can exceed 2 min.
            $response = wp_remote_post( $this->api_endpoint, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode( $body ),
                'timeout' => 180,
            ) );

            // Connection-level error (e.g., DNS failure, cURL timeout) — retry
            if ( is_wp_error( $response ) ) {
                $last_exception = new Exception( 'Poe API request failed: ' . $response->get_error_message() );
                if ( $attempt < $this->max_retries ) {
                    error_log( "ZL Poe: Connection error on attempt {$attempt}, retrying in {$delay}s..." );
                    sleep( $delay );
                    $delay *= 2;
                    continue;
                }
                throw $last_exception;
            }

            $code = wp_remote_retrieve_response_code( $response );
            $raw  = wp_remote_retrieve_body( $response );

            // Gateway errors (502, 503, 504) — retry with backoff
            // This specifically addresses the v1.2.1 fix where Poe's API occasionally drops connections
            if ( in_array( (int) $code, array( 502, 503, 504 ), true ) && $attempt < $this->max_retries ) {
                error_log( "ZL Poe: HTTP {$code} on attempt {$attempt}/{$this->max_retries}, retrying in {$delay}s..." );
                sleep( $delay );
                $delay *= 2;
                continue;
            }

            // Non-200, non-retryable error (e.g., 400 Bad Request, 401 Unauthorized)
            if ( 200 !== (int) $code ) {
                $err = json_decode( $raw, true );
                $msg = isset( $err['error']['message'] ) ? $err['error']['message'] : substr( $raw, 0, 500 );
                throw new Exception( "Poe API error ({$code}): {$msg}" );
            }

            // ── Success: parse response ──
            $data = json_decode( $raw, true );
            if ( isset( $data['choices'][0]['message']['content'] ) ) {
                return $data['choices'][0]['message']['content'];
            }

            // Fallback: try SSE parsing in case API streamed despite stream=false
            // Some API endpoints occasionally ignore the stream flag and return chunked SSE anyway.
            if ( strpos( $raw, 'data: ' ) !== false ) {
                return $this->parse_sse( $raw );
            }

            // If we reach here, the response format was completely unexpected
            throw new Exception( 'Unexpected Poe API response: ' . substr( $raw, 0, 500 ) );
        }

        // Exhausted all retries
        throw $last_exception ?: new Exception( 'Poe API failed after ' . $this->max_retries . ' retries' );
    }

    /**
     * v1.8.0 — Dispatch many Poe queries in parallel via ZL_Parallel_Dispatch.
     *
     * Primary consumer: {@see ZL_Lead_Generator::ai_refine_descriptions()},
     * which pre-v1.8.0 issued N sequential HTTP round-trips. At N=5 chunks
     * that's 5×~6s = ~30s; with cap=4 in parallel it finishes in ~8s.
     *
     * ──────────────────────────────────────────────────────────────────────
     * IMPROVEMENT C — PARALLEL AI CLASSIFICATION
     * ──────────────────────────────────────────────────────────────────────
     * TRAP 1 (bounded concurrency): cap is caller-supplied; we clamp it
     * between 1 and 16 via ZL_Parallel_Dispatch.
     *
     * TRAP 3 (heartbeat promise): a progress callback fires after every
     * completed request (not every N) so upstream watchdogs never go
     * >30s without a heartbeat.
     *
     * Rate-limit handling (429): on a 429 the caller is expected to halve
     * its concurrency cap via ZL_Lead_Generator::halve_poe_cap() for the
     * REST OF THE BATCH, not forever. This method does not auto-retry 429s
     * — it reports the status back and lets the caller decide.
     *
     * @param array[]   $items  Each: { 'id' => string, 'prompt' => string, 'system' => '',
     *                                  'temperature' => float|null, 'extra_params' => array,
     *                                  'bot' => string|null }
     * @param int       $cap    Concurrency cap (1–16; default 4).
     * @param callable|null $on_progress  fn(id, result_arr, done, total).
     * @return array    Map id → { 'text' => string, 'error' => string, 'status' => int, 'was_429' => bool }
     * @since 1.8.0
     */
    public function query_parallel( array $items, $cap = 4, $on_progress = null ) {
        if ( empty( $items ) ) {
            return array();
        }
        if ( ! class_exists( 'ZL_Parallel_Dispatch' ) ) {
            // Serial fallback — still returns the right shape. Slow but correct.
            $out = array();
            $total = count( $items );
            $done  = 0;
            foreach ( $items as $it ) {
                $id = $it['id'] ?? ('i' . $done);
                try {
                    $text = $this->query(
                        $it['prompt'] ?? '',
                        $it['bot']    ?? null,
                        $it['system'] ?? '',
                        $it['temperature'] ?? null,
                        $it['extra_params'] ?? array()
                    );
                    $out[ $id ] = array( 'text' => $text, 'error' => '', 'status' => 200, 'was_429' => false );
                } catch ( \Throwable $e ) {
                    $msg = $e->getMessage();
                    $was_429 = ( strpos( $msg, '429' ) !== false );
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

        // Build parallel requests — each mirrors the headers/body the
        // serial query() path uses, so upstream behavior is identical.
        $requests = array();
        foreach ( $items as $it ) {
            $id = (string) ( $it['id'] ?? spl_object_hash( (object) $it ) );
            $bot_name = ! empty( $it['bot'] ) ? $it['bot'] : $this->default_bot;

            $messages = array();
            if ( ! empty( $it['system'] ) ) {
                $messages[] = array( 'role' => 'system', 'content' => $it['system'] );
            }
            $messages[] = array( 'role' => 'user', 'content' => $it['prompt'] ?? '' );

            $body = array(
                'model'    => $bot_name,
                'messages' => $messages,
                'stream'   => false,
            );
            if ( isset( $it['temperature'] ) && $it['temperature'] !== null ) {
                $body['temperature'] = (float) $it['temperature'];
            }
            if ( ! empty( $it['extra_params'] ) && is_array( $it['extra_params'] ) ) {
                foreach ( $it['extra_params'] as $k => $v ) {
                    $body[ $k ] = $v;
                }
            }

            $requests[] = array(
                'id'      => $id,
                'url'     => $this->api_endpoint,
                'method'  => 'POST',
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode( $body ),
                'timeout' => 180, // match serial query() — long for thinking_budget models
            );
        }

        $raw_results = ZL_Parallel_Dispatch::run( $requests, max( 1, (int) $cap ), $on_progress );

        // Decode each response.
        $out = array();
        foreach ( $requests as $req ) {
            $id  = $req['id'];
            $r   = $raw_results[ $id ] ?? null;
            if ( ! is_array( $r ) ) {
                $out[ $id ] = array( 'text' => '', 'error' => 'No result', 'status' => 0, 'was_429' => false );
                continue;
            }

            $status = (int) ( $r['status'] ?? 0 );
            $body   = (string) ( $r['body'] ?? '' );
            $err    = (string) ( $r['error'] ?? '' );

            if ( $status === 429 ) {
                $out[ $id ] = array( 'text' => '', 'error' => 'Rate limited (429)', 'status' => 429, 'was_429' => true );
                continue;
            }
            if ( $err !== '' ) {
                $out[ $id ] = array( 'text' => '', 'error' => $err, 'status' => $status, 'was_429' => false );
                continue;
            }
            if ( $status < 200 || $status >= 300 ) {
                $dec = json_decode( $body, true );
                $msg = isset( $dec['error']['message'] ) ? $dec['error']['message'] : substr( $body, 0, 300 );
                $out[ $id ] = array( 'text' => '', 'error' => "HTTP {$status}: {$msg}", 'status' => $status, 'was_429' => false );
                continue;
            }

            $data = json_decode( $body, true );
            if ( isset( $data['choices'][0]['message']['content'] ) ) {
                $out[ $id ] = array( 'text' => (string) $data['choices'][0]['message']['content'], 'error' => '', 'status' => 200, 'was_429' => false );
            } elseif ( strpos( $body, 'data: ' ) !== false ) {
                $out[ $id ] = array( 'text' => $this->parse_sse( $body ), 'error' => '', 'status' => 200, 'was_429' => false );
            } else {
                $out[ $id ] = array( 'text' => '', 'error' => 'Unexpected response format', 'status' => $status, 'was_429' => false );
            }
        }
        return $out;
    }

    /**
     * Parse SSE (Server-Sent Events) response in OpenAI streaming format.
     *
     * PURPOSE:
     * Acts as a fallback parser if the Poe API returns a streaming response 
     * instead of a standard JSON object. It aggregates the 'content' deltas 
     * into a single string.
     *
     * @param string $raw_body The raw response body containing SSE data lines.
     * @return string Aggregated text content from all SSE chunks.
     */
    private function parse_sse( $raw_body ) {
        $text  = '';
        $lines = explode( "\n", $raw_body );

        foreach ( $lines as $line ) {
            $line = trim( $line );

            // Ignore empty lines or lines that don't start with the SSE data prefix
            if ( empty( $line ) || strpos( $line, 'data: ' ) !== 0 ) {
                continue;
            }

            // Extract the JSON payload after "data: "
            $json_str = substr( $line, 6 );

            // Stop processing when the stream completion marker is reached
            if ( '[DONE]' === $json_str ) {
                break;
            }

            $data = json_decode( $json_str, true );

            // Append the delta content to the aggregated text
            if ( isset( $data['choices'][0]['delta']['content'] ) ) {
                $text .= $data['choices'][0]['delta']['content'];
            }
        }

        return $text;
    }
}