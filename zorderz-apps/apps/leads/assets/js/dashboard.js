/**
 * Zorderz Leads — Dashboard JS
 *
 * ARCHITECTURE & BUSINESS CONTEXT:
 * This file orchestrates the frontend UI and the 8-step AJAX pipeline for generating sales leads.
 * Business: the business. 
 * Salespeople each own one or more territory codes.
 * Integrations: FreshBooks (Invoices) -> Nutshell (CRM) -> Poe AI / Gemini-3.1-Pro (Enrichment/Filtering).
 * 
 * PIPELINE STEPS:
 * 1.  Create Batch (zl_start_batch) - Initializes DB record.
 * 2.  Fetch Invoices (zl_fetch_invoices) - Pulls paid invoices from FreshBooks.
 * 2b. Expand Filter (zl_expand_filter) - [v1.2.1] AI expands product keywords (split to prevent 502s).
 * 3.  Enrich Chunk (zl_enrich_chunk) - Cross-references with Nutshell CRM data in batches.
 * 4.  Select Leads (zl_select_leads) - Scores and filters candidates.
 * 5.  AI Validate (zl_ai_validate) - Strict Gemini-3.1-Pro check against product filters.
 * 6.  AI Refine (zl_ai_refine) - Cleans up purchase descriptions for the sales team.
 * 7.  Create Nutshell (zl_create_nutshell) - Pushes finalized leads to the CRM.
 * 8.  Finalize (zl_finalize) - Generates AI summary and completes the batch.
 *
 * ITERATION NOTES:
 * - If adding new filters, update `startGeneration` state capture and pass them to `zl_start_batch`.
 * - If modifying the pipeline, ensure the `setProgress` calls reflect the new weight of each step.
 * - AJAX timeouts are set to 120s; heavy AI operations should be chunked to avoid proxy timeouts.
 *
 * v1.2.0 — City/zip, spend-range, and demographic filters.
 * v1.2.1 — Split AI filter expansion into separate AJAX step (502 fix).
 */

(function ($) {
    'use strict';

    // ── Global State Variables ────────────────────────────────────────
    // These maintain the configuration of the currently running batch across the multi-step AJAX flow.
    var isRunning = false;
    var currentBatchId = null;
    var currentIsTest = false;
    var currentProductFilter = '';
    var currentLookback = 730;
    var currentCityZipFilter = '';
    var currentSpendMin = 0;
    var currentSpendMax = 0;
    var currentDemographic = 'both';
    var startTime = null;

    // ── Generation buttons ────────────────────────────────────────────

    /**
     * Test Generation Trigger
     * Runs the pipeline but limits output to 3 leads and skips CRM insertion.
     */
    $(document).on('click', '#zl-btn-test', function () {
        if (isRunning) return;
        startGeneration(true);
    });

    /**
     * Full Generation Trigger
     * Runs the full pipeline and pushes leads directly to Nutshell CRM.
     */
    $(document).on('click', '#zl-btn-full', function () {
        if (isRunning) return;
        if (!confirm('Generate a full batch of leads? This will create leads in Nutshell CRM.')) return;
        startGeneration(false);
    });

    /**
     * Initializes the generation pipeline.
     * Captures UI state, disables inputs to prevent interference, and triggers Step 1.
     * 
     * @param {boolean} isTest - Whether this is a test run.
     */
    function startGeneration(isTest) {
        isRunning = true;
        currentIsTest = isTest;
        startTime = Date.now();
        
        // Capture all filter states from the DOM
        var spCode = $('#zl-salesperson').val();
        currentProductFilter = $.trim($('#zl-product-filter').val());
        currentLookback = parseInt($('#zl-lookback').val()) || 730;
        currentCityZipFilter = $.trim($('#zl-city-zip').val());
        currentSpendMin = parseFloat($('#zl-spend-min').val()) || 0;
        currentSpendMax = parseFloat($('#zl-spend-max').val()) || 0;
        currentDemographic = $('#zl-demographic').val() || 'both';

        // Disable buttons and inputs to lock configuration during processing
        $('#zl-btn-test, #zl-btn-full').prop('disabled', true);
        $('#zl-salesperson, #zl-lookback, #zl-product-filter').prop('disabled', true);
        $('#zl-city-zip, #zl-spend-min, #zl-spend-max, #zl-demographic').prop('disabled', true);

        // Show progress UI and reset logs
        $('#zl-progress').show();
        setProgress(0);
        clearLog();

        var desc = isTest ? 'test (3 leads)' : 'full batch';
        log('═══════════════════════════════════════════════════');
        log('🚀 Starting ' + desc + ' generation for ' + spCode);
        log('═══════════════════════════════════════════════════');
        
        // Log active filters for user visibility
        if (currentProductFilter) {
            log('🔍 Product filter: "' + currentProductFilter + '"');
        }
        if (currentCityZipFilter) {
            log('📍 Location filter: "' + currentCityZipFilter + '"');
        }
        if (currentSpendMin > 0 || currentSpendMax > 0) {
            var spendStr = 'Spend range: ';
            if (currentSpendMin > 0) spendStr += '$' + currentSpendMin;
            else spendStr += '$0';
            spendStr += ' — ';
            if (currentSpendMax > 0) spendStr += '$' + currentSpendMax;
            else spendStr += 'no limit';
            log('💰 ' + spendStr);
        }
        if (currentDemographic !== 'both') {
            log('👤 Demographic: ' + currentDemographic);
        }
        log('📅 Lookback: ' + formatLookback(currentLookback));
        log('');

        // v1.7.0 — Background generation (flush-and-continue).
        // Server creates batch, flushes response, runs pipeline in background.
        // We poll for progress instead of driving steps from the browser.
        log('Sending to server (background mode)...');
        ajaxPost('zl_start_background', {
            salesperson: spCode,
            is_test: isTest ? 1 : 0,
            lookback_days: currentLookback,
            product_filter: currentProductFilter,
            city_zip_filter: currentCityZipFilter,
            spend_min: currentSpendMin,
            spend_max: currentSpendMax,
            demographic_filter: currentDemographic
        }, function (data) {
            currentBatchId = data.batch_id;
            log('✓ Batch #' + data.batch_id + ' created: ' + data.batch_tag);
            log('💡 Pipeline running in background — you can close this page.');
            log('');
            setProgress(5);

            // Start polling for progress
            pollBatchProgress(data.batch_id);
        });
    }

    /**
     * v1.7.0 — Poll the server for background batch progress.
     * Polls every 3 seconds until the batch is complete or errors.
     *
     * @param {number} batchId
     */
    var _pollTimer = null;
    var _lastPollMsg = '';
    function pollBatchProgress(batchId) {
        if (_pollTimer) { clearInterval(_pollTimer); _pollTimer = null; }
        _lastPollMsg = '';

        // v1.8.0 — stall banner state (speed release).
        var lastStalledAt = 0;

        function doPoll() {
            $.post(tslAdmin.ajaxurl, {
                action: 'zl_poll_batch_progress',
                nonce: tslAdmin.nonce,
                batch_id: batchId
            }, function (resp) {
                if (!resp || !resp.success) { return; }
                var d = resp.data || {};
                var pct    = d.pct || 0;
                var msg    = d.message || '';
                var status = d.status || 'unknown';
                var step   = d.step || '';

                setProgress(pct);

                // Only log when the message actually changes (avoid spam)
                if (msg && msg !== _lastPollMsg) {
                    log('[' + step + ' ' + pct + '%] ' + msg + elapsed());
                    _lastPollMsg = msg;
                }

                // v1.8.0 — surface stall without aborting. Log once every
                // 30s while stalled so the admin has a hint something's up.
                if (d.stalled && status !== 'complete' && status !== 'error') {
                    var now = Date.now();
                    if (now - lastStalledAt > 30000) {
                        log('⏳ Backend heartbeat silent for ' + (d.last_heartbeat_s || 0) + 's — still waiting (not failed).');
                        lastStalledAt = now;
                    }
                }

                if (status === 'complete') {
                    clearInterval(_pollTimer);
                    _pollTimer = null;
                    generationComplete();
                } else if (status === 'error') {
                    clearInterval(_pollTimer);
                    _pollTimer = null;
                    log('❌ ' + msg);
                    isRunning = false;
                    $('#zl-btn-test, #zl-btn-full').prop('disabled', false);
                    $('#zl-salesperson, #zl-lookback, #zl-product-filter').prop('disabled', false);
                    $('#zl-city-zip, #zl-spend-min, #zl-spend-max, #zl-demographic').prop('disabled', false);
                }
            }).fail(function () {
                log('⚠️ Poll error (will retry)');
            });
        }

        doPoll();
        _pollTimer = setInterval(doPoll, 3000);
    }

    /**
     * v1.7.0 — Called when background generation is complete.
     * Unlocks the UI and reloads the page to show updated batch list.
     */
    function generationComplete() {
        log('');
        log('═══════════════════════════════════════════════════');
        log('✅ Background generation complete!' + elapsed());
        log('═══════════════════════════════════════════════════');

        isRunning = false;
        $('#zl-btn-test, #zl-btn-full').prop('disabled', false);
        $('#zl-salesperson, #zl-lookback, #zl-product-filter').prop('disabled', false);
        $('#zl-city-zip, #zl-spend-min, #zl-spend-max, #zl-demographic').prop('disabled', false);

        setTimeout(function () {
            location.reload();
        }, 4000);
    }

    /**
     * Helper to calculate and format elapsed time since generation started.
     */
    function elapsed() {
        if (!startTime) return '';
        var s = Math.round((Date.now() - startTime) / 1000);
        return ' [' + s + 's]';
    }

    /**
     * Formats the lookback days into a human-readable string for the log.
     */
    function formatLookback(days) {
        if (days <= 180) return '6 months';
        if (days <= 365) return '1 year';
        if (days <= 730) return '2 years';
        if (days <= 1095) return '3 years';
        if (days <= 1825) return '5 years';
        if (days <= 3650) return '10 years';
        if (days <= 5475) return '15 years';
        return 'Since 2000';
    }

    /**
     * Step 1: Fetch Invoices (paginated)
     * v1.2.3: Fetches FreshBooks invoices one page at a time (100 per page) to prevent
     * web server proxy timeouts. Each AJAX call fetches a single page (~2-3s).
     * Loops until all pages are fetched, then proceeds to filter expansion.
     */
    function stepFetchInvoices() {
        log('');
        log('── Step 1: Fetching Invoices ──────────────────────');
        log('Fetching invoices from FreshBooks...');
        fetchInvoicePage(1);
    }

    /**
     * Fetches a single page of invoices and loops until all pages are done.
     *
     * @param {number} page - The page number to fetch (1-based).
     */
    function fetchInvoicePage(page) {
        ajaxPost('zl_fetch_invoices', { batch_id: currentBatchId, page: page }, function (data) {
            // Progress update per page
            if (data.total_pages > 1) {
                log('  📄 Page ' + data.page + '/' + data.total_pages + ' — ' + data.invoice_count + ' invoices so far (' + data.customer_count + ' customers)');
                // Scale progress from 5% to 10% across pages
                var pagePct = 5 + Math.round((data.page / data.total_pages) * 5);
                setProgress(pagePct);
            }

            if (!data.done) {
                // More pages to fetch — continue
                fetchInvoicePage(data.page + 1);
                return;
            }

            // All pages fetched — show final totals
            log('✓ Found ' + data.invoice_count + ' paid invoices across ' + data.customer_count + ' customers' + elapsed());
            if (data.total_pages > 1) {
                log('   (Fetched across ' + data.total_pages + ' pages)');
            }

            // v1.5.3 — Show fallback warning if include[]=lines was dropped
            if (data.fb_fallback) {
                log('');
                log('⚠️ FreshBooks Fallback Active (v1.5.3)');
                log('   The include[]=lines parameter returned 0 invoices.');
                log('   Fetched invoices WITHOUT line items — product filtering will be limited.');
                log('   Run "Test FreshBooks Connection" in Settings for details.');
            }

            // Show AI info
            if (data.ai_model) {
                log('🧠 AI Model: ' + data.ai_model + ' (High Reasoning + Web)');
            }

            setProgress(10);

            // v1.2.1: AI filter expansion is now a separate AJAX step to prevent 502 timeouts
            if (currentProductFilter && data.ai_available) {
                stepExpandFilter(data.customer_count);
            } else if (currentProductFilter && !data.ai_available) {
                log('⚠️ Poe API key not found — using basic keyword matching');
                log('   Add your Poe API key in Leads > Settings.');
                setProgress(15);
                stepEnrichChunk(0, data.customer_count);
            } else {
                setProgress(15);
                stepEnrichChunk(0, data.customer_count);
            }
        });
    }

    /**
     * Step 1b: AI Product Filter Expansion (separate AJAX call).
     * Added in v1.2.1 — split out of stepFetchInvoices to prevent 502 Bad Gateway
     * when FreshBooks fetch + AI expansion combined exceed server proxy timeout.
     * Uses Poe AI to find synonyms and related product terms for broader matching.
     */
    function stepExpandFilter(customerCount) {
        log('');
        log('── Step 1b: AI Product Filter Expansion ───────────');
        log('🤖 Running AI product filter expansion...');
        ajaxPost('zl_expand_filter', { batch_id: currentBatchId }, function (data) {
            if (data.skipped) {
                log('⚠️ No product filter to expand — skipping');
            } else if (data.filter_expanded && data.filter_ai_used) {
                log('✓ AI identified ' + data.filter_matches + ' matching product names' + elapsed());
                if (data.filter_matched_names) {
                    log('📋 Matched items: ' + data.filter_matched_names);
                }
                if (data.filter_keywords) {
                    log('🔑 Search phrases: ' + data.filter_keywords);
                }
            } else {
                log('⚠️ AI filter expansion failed — using basic keyword matching');
                if (data.filter_error) {
                    log('   Reason: ' + data.filter_error);
                }
                if (data.filter_keywords) {
                    log('🔑 Fallback keywords: ' + data.filter_keywords);
                }
            }

            setProgress(15);
            // Move to enrichment phase
            stepEnrichChunk(0, customerCount);
        });
    }

    /**
     * Step 2: Enrich Customers (Chunked)
     * Cross-references FreshBooks customers with Nutshell CRM to check if they are already active leads.
     * This is chunked because API limits and processing time for hundreds of customers would timeout.
     * 
     * @param {number} offset - Current pagination offset.
     * @param {number} total - Total customers to process.
     */
    // Cumulative filter-skip counters for enrichment diagnostics
    var enrichSkips = { cooldown: 0, enrich: 0, product: 0, territory: 0, cityzip: 0, spend: 0 };
    // Enrichment sub-category counters (v1.3.0 diagnostics)
    var enrichFailReasons = { fb_api: 0, excluded: 0, commercial: 0 };
    var lastDiagAt = 0; // last processed count where diagnostics were shown

    function stepEnrichChunk(offset, total) {
        var pctBase = 15;
        var pctRange = 35; // 15% to 50%
        var pct = total > 0 ? pctBase + Math.round((offset / total) * pctRange) : pctBase;
        setProgress(pct);

        if (offset === 0) {
            log('');
            log('── Step 2: Enriching Customers ────────────────────');
            log('Enriching customers with Nutshell data...');
            // Reset counters for new run
            enrichSkips = { cooldown: 0, enrich: 0, product: 0, territory: 0, cityzip: 0, spend: 0 };
            enrichFailReasons = { fb_api: 0, excluded: 0, commercial: 0 };
            lastDiagAt = 0;
        }

        ajaxPost('zl_enrich_chunk', { batch_id: currentBatchId, offset: offset }, function (data) {
            // Accumulate per-filter skip counters
            enrichSkips.cooldown  += data.skip_cooldown  || 0;
            enrichSkips.enrich    += data.skip_enrich    || 0;
            enrichSkips.product   += data.skip_product   || 0;
            enrichSkips.territory += data.skip_territory || 0;
            enrichSkips.cityzip   += data.skip_cityzip   || 0;
            enrichSkips.spend     += data.skip_spend     || 0;
            // Accumulate enrichment sub-categories
            enrichFailReasons.fb_api     += data.skip_fb_api     || 0;
            enrichFailReasons.excluded   += data.skip_excluded   || 0;
            enrichFailReasons.commercial += data.skip_commercial || 0;

            // v1.2.10: Log every 100 customers (not every chunk) to prevent
            // browser memory exhaustion on 9,000+ customer runs.
            // Always log when candidate count changes (new eligible found).
            var shouldLog = (data.processed % 100 < 26) || (data.candidate_count !== (window._tslLastCandCount || 0));
            window._tslLastCandCount = data.candidate_count;

            if (shouldLog) {
                var logMsg = '📊 Processed ' + data.processed + '/' + data.total + ' — ' + data.candidate_count + ' eligible so far';
                if (data.errors > 0) {
                    logMsg += ' (' + data.errors + ' errors)';
                }
                log(logMsg + elapsed());
            }

            // Show filter breakdown every 1000 customers if 0 eligible (diagnostic)
            if (data.candidate_count === 0 && data.processed >= 1000 && data.processed - lastDiagAt >= 1000) {
                lastDiagAt = data.processed;
                log('   🔍 Filter breakdown so far:');
                log('      Cooldown: ' + enrichSkips.cooldown
                    + ' | Product mismatch: ' + enrichSkips.product
                    + ' | Not enrichable: ' + enrichSkips.enrich
                    + ' (API fail: ' + enrichFailReasons.fb_api
                    + ', Excluded: ' + enrichFailReasons.excluded
                    + ', Commercial: ' + enrichFailReasons.commercial + ')'
                    + ' | Wrong territory: ' + enrichSkips.territory
                    + ' | Wrong city/zip: ' + enrichSkips.cityzip
                    + ' | Outside spend: ' + enrichSkips.spend);
            }

            if (data.done) {
                if (data.early_stopped) {
                    log('⚡ Early stop: found ' + data.candidate_count + ' candidates (enough for selection)');
                } else {
                    log('✓ Enrichment complete: ' + data.candidate_count + ' eligible candidates' + elapsed());
                }
                // Always show final filter breakdown
                if (enrichSkips.cooldown + enrichSkips.enrich + enrichSkips.product + enrichSkips.territory + enrichSkips.cityzip + enrichSkips.spend > 0) {
                    log('   📋 Final filter breakdown:');
                    log('      Cooldown: ' + enrichSkips.cooldown
                        + ' | Product mismatch: ' + enrichSkips.product
                        + ' | Not enrichable: ' + enrichSkips.enrich);
                    if (enrichSkips.enrich > 0) {
                        log('         ↳ FreshBooks API fail: ' + enrichFailReasons.fb_api
                            + ' | Excluded company: ' + enrichFailReasons.excluded
                            + ' | Commercial entity: ' + enrichFailReasons.commercial);
                    }
                    log('      Wrong territory: ' + enrichSkips.territory
                        + ' | Wrong city/zip: ' + enrichSkips.cityzip
                        + ' | Outside spend: ' + enrichSkips.spend);
                }
                setProgress(50);
                stepSelectLeads();
            } else {
                stepEnrichChunk(data.next_offset, data.total);
            }
        });
    }

    /**
     * Step 3: Scoring & Selection
     * Ranks candidates based on recency, spend, and demographic match, then selects the top N leads.
     */
    function stepSelectLeads() {
        log('');
        log('── Step 3: Scoring & Selection ────────────────────');
        log('Scoring and selecting top leads...');
        ajaxPost('zl_select_leads', { batch_id: currentBatchId, is_test: currentIsTest ? 1 : 0 }, function (data) {
            log('✓ Selected ' + data.lead_count + ' leads from ' + data.total_candidates + ' candidates' + elapsed());
            if (currentProductFilter && data.lead_count > (currentIsTest ? 3 : 50)) {
                log('   (Over-selected for AI validation — will trim after strict check)');
            }
            setProgress(55);

            // Step 4: AI strict validation (new in v1.0.9)
            stepAIValidate();
        });
    }

    /**
     * Step 4: AI Strict Validation
     * Uses Gemini-3.1-Pro to strictly verify if the selected leads actually match the product filter.
     * This prevents false positives from the earlier, broader keyword expansion.
     */
    function stepAIValidate() {
        log('');
        log('── Step 4: AI Strict Validation ───────────────────');
        if (!currentProductFilter) {
            log('⏭ No product filter — skipping validation');
            setProgress(65);
            stepAIRefine(0);
            return;
        }

        log('🤖 Gemini 3.1 Pro verifying each lead against filter: "' + currentProductFilter + '"');
        log('   Using High Reasoning + Web Search for maximum accuracy...');

        ajaxPost('zl_ai_validate', { batch_id: currentBatchId, is_test: currentIsTest ? 1 : 0 }, function (data) {
            if (data.skipped) {
                log('⏭ Validation skipped' + (data.reason ? ' — ' + data.reason : '') + elapsed());
            } else if (data.ai_used) {
                log('✓ AI Validation complete' + elapsed());
                log('   ✅ ' + data.passed + ' leads PASSED strict validation');

                if (data.rejected > 0) {
                    log('   ❌ ' + data.rejected + ' leads REJECTED:');
                    if (data.details && data.details.length > 0) {
                        data.details.forEach(function (d) {
                            log('      • ' + d.name + ' — ' + d.reason);
                        });
                    }
                }

                if (data.trimmed && data.trimmed > 0) {
                    log('   ✂️ ' + data.trimmed + ' excess leads trimmed to fit batch size');
                }

                log('   📊 Final lead count: ' + data.final_count);
            } else {
                log('⚠️ AI validation unavailable — keeping all leads' + elapsed());
                if (data.error) {
                    log('   Error: ' + data.error);
                }
            }

            setProgress(65);
            stepAIRefine(0);
        });
    }

    /**
     * Step 5: AI Description Refinement (Chunked)
     * Cleans up messy invoice line items into a readable summary for the salesperson.
     *
     * @param {number} offset - Current pagination offset.
     */
    function stepAIRefine(offset) {
        if (offset === 0) {
            log('');
            log('── Step 5: AI Description Refinement ──────────────');
            log('🤖 Refining purchase descriptions with AI...');
        }

        ajaxPost('zl_ai_refine', { batch_id: currentBatchId, offset: offset }, function (data) {
            if (data.skipped) {
                log('⏭ AI refinement skipped' + (data.reason ? ' (' + data.reason + ')' : '') + elapsed());
            } else if (data.refined > 0) {
                log('  ✨ Refined ' + data.refined + ' descriptions...');
            }

            if (data.done) {
                if (!data.skipped) {
                    log('✓ Refinement complete' + elapsed());
                }
                setProgress(75);
                // Step 6: Create Nutshell leads
                stepCreateNutshell(0);
            } else {
                stepAIRefine(data.next_offset);
            }
        });
    }

    /**
     * Step 6: Create Nutshell Leads (Chunked)
     * Pushes the finalized leads into Nutshell CRM. Skipped if running in Test Mode.
     * 
     * @param {number} offset - Current pagination offset.
     */
    function stepCreateNutshell(offset) {
        if (offset === 0) {
            log('');
            log('── Step 6: Nutshell CRM ───────────────────────────');
            log(currentIsTest ? '⏭ Skipping Nutshell lead creation (test mode)' : 'Creating Nutshell leads...');
        }

        ajaxPost('zl_create_nutshell', { batch_id: currentBatchId, offset: offset, is_test: currentIsTest ? 1 : 0 }, function (data) {
            if (data.skipped) {
                log('   Use "Send to Nutshell" button to create leads later');
            } else if (data.created > 0) {
                log('  📤 Created ' + data.created + ' Nutshell leads...');
            }

            if (data.done) {
                setProgress(85);
                stepFinalize();
            } else {
                // Recursive call for the next chunk
                stepCreateNutshell(data.next_offset);
            }
        });
    }

    /**
     * Step 7: Finalize Batch
     * Generates a final AI summary of the batch and unlocks the UI.
     */
    function stepFinalize() {
        log('');
        log('── Step 7: Batch Summary ──────────────────────────');
        log('🤖 Generating AI batch summary...');
        ajaxPost('zl_finalize', { batch_id: currentBatchId }, function (data) {
            setProgress(100);
            log('');
            log('═══════════════════════════════════════════════════');
            log('✅ Generation complete! ' + data.lead_count + ' leads generated' + elapsed());
            log('═══════════════════════════════════════════════════');
            if (data.summary) {
                log('');
                log('📊 AI Summary: ' + data.summary);
            }

            isRunning = false;
            // Re-enable UI
            $('#zl-btn-test, #zl-btn-full').prop('disabled', false);
            $('#zl-salesperson, #zl-lookback, #zl-product-filter').prop('disabled', false);
            $('#zl-city-zip, #zl-spend-min, #zl-spend-max, #zl-demographic').prop('disabled', false);

            // Reload page after brief delay to show updated batch list in the UI
            setTimeout(function () {
                location.reload();
            }, 4000);
        });
    }

    // ── AJAX Helper ───────────────────────────────────────────────────

    /**
     * Standardized AJAX POST wrapper with error handling, timeout management,
     * and automatic retry for gateway errors (502/503/504).
     *
     * v1.2.1: Added retry logic — when the web server returns a 502/503/504
     * (typically caused by proxy timeout during heavy AI operations), the request
     * is retried up to 2 times with exponential backoff (5s, then 10s).
     * Combined with PHP-side set_time_limit(300) and Poe client retries, this
     * provides three layers of timeout protection.
     *
     * @param {string} action - WordPress AJAX action hook name.
     * @param {object} data - Payload to send.
     * @param {function} onSuccess - Callback on success.
     * @param {function} onError - Optional callback on error.
     */
    function ajaxPost(action, data, onSuccess, onError) {
        var maxRetries = 2;     // Up to 2 retries (3 total attempts)
        var retryDelay = 5000;  // Start with 5s delay, doubles each retry

        function attemptRequest(attempt) {
            var payload = $.extend({}, data, { action: action, nonce: tslData.nonce });

            $.ajax({
                url: tslData.ajaxUrl,
                type: 'POST',
                data: payload,
                timeout: 360000, // 6 min — allows room for slow FreshBooks/Nutshell API calls on large lookbacks
                success: function (response) {
                    if (response.success) {
                        onSuccess(response.data);
                    } else {
                        var msg = response.data || 'Unknown error';
                        if (typeof onError === 'function') {
                            onError(msg);
                        } else {
                            log('❌ Error: ' + msg);
                            isRunning = false;
                            // Rescue UI state on error
                            $('#zl-btn-test, #zl-btn-full').prop('disabled', false);
                            $('#zl-salesperson, #zl-lookback, #zl-product-filter').prop('disabled', false);
                            $('#zl-city-zip, #zl-spend-min, #zl-spend-max, #zl-demographic').prop('disabled', false);
                        }
                    }
                },
                error: function (xhr, status, error) {
                    var httpStatus = xhr ? xhr.status : 0;

                    // Retry on gateway errors (502, 503, 504) and timeouts — these
                    // typically mean the web server or a slow API call timed out.
                    var isRetryable = (httpStatus === 502 || httpStatus === 503 || httpStatus === 504 || status === 'timeout');
                    if (isRetryable && attempt < maxRetries) {
                        var reason = status === 'timeout' ? 'Request timed out' : 'HTTP ' + httpStatus;
                        var delaySec = (retryDelay * Math.pow(2, attempt)) / 1000;
                        log('⚠️ ' + reason + ' — retrying in ' + delaySec + 's (attempt ' + (attempt + 2) + '/' + (maxRetries + 1) + ')...');
                        setTimeout(function () {
                            attemptRequest(attempt + 1);
                        }, retryDelay * Math.pow(2, attempt));
                        return;
                    }

                    // No more retries — report the error
                    var msg = '';

                    // Status 0 with "error" status = connection lost (server crashed)
                    // This commonly happens when PHP hits memory_limit or max_execution_time
                    if ((!httpStatus || httpStatus === 0) && status === 'error') {
                        msg = 'Connection lost — server may have run out of memory (check PHP error log)';
                    } else {
                        msg = error || status || 'Request failed';
                    }

                    // Try to extract a more helpful message from the response body (e.g., PHP fatals)
                    if (xhr && xhr.responseText) {
                        try {
                            var resp = JSON.parse(xhr.responseText);
                            if (resp && resp.data) msg = resp.data;
                        } catch (e) {
                            // If response isn't JSON, check for common PHP errors
                            var rt = xhr.responseText.substring(0, 300);
                            if (rt.indexOf('Fatal error') !== -1 || rt.indexOf('memory') !== -1) {
                                msg = 'PHP fatal error (memory) — check server error log';
                            } else if (rt.indexOf('Maximum execution time') !== -1) {
                                msg = 'PHP timeout — try reducing lookback period';
                            }
                        }
                    }
                    if (httpStatus && httpStatus > 0) {
                        msg += ' [HTTP ' + httpStatus + ']';
                    }
                    if (typeof onError === 'function') {
                        onError(msg);
                    } else {
                        log('❌ Request failed: ' + msg);
                        isRunning = false;
                        $('#zl-btn-test, #zl-btn-full').prop('disabled', false);
                        $('#zl-salesperson, #zl-lookback, #zl-product-filter').prop('disabled', false);
                        $('#zl-city-zip, #zl-spend-min, #zl-spend-max, #zl-demographic').prop('disabled', false);
                    }
                }
            });
        }

        attemptRequest(0);
    }

    // ── Progress UI ───────────────────────────────────────────────────

    /**
     * Updates the visual progress bar.
     */
    function setProgress(pct) {
        $('#zl-progress-bar').css('width', pct + '%').text(pct + '%');
    }

    /**
     * Clears the generation log output area.
     */
    function clearLog() {
        $('#zl-progress-log').empty();
    }

    /**
     * Appends a message to the generation log and auto-scrolls to the bottom.
     * Trims oldest entries when the log exceeds 300 lines to prevent browser
     * memory exhaustion on long enrichment runs (9,000+ customers).
     */
    function log(msg) {
        var $log = $('#zl-progress-log');
        $log.append('<div class="zl-log-line">' + escapeHtml(msg) + '</div>');

        // Trim oldest log lines to prevent DOM bloat
        var $lines = $log.children('.zl-log-line');
        if ($lines.length > 300) {
            $lines.slice(0, $lines.length - 300).remove();
        }

        $log.scrollTop($log[0].scrollHeight);
    }

    /**
     * Basic HTML escaping to prevent XSS in log output.
     */
    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    /**
     * Safely parse JSON strings
     */
    function safelyParseJSON(jsonStr) {
        if (!jsonStr) return null;
        try {
            return JSON.parse(jsonStr);
        } catch (e) {
            return null;
        }
    }

    // ── Batch expand/collapse ─────────────────────────────────────────

    /**
     * Toggles the visibility of a batch's leads in the dashboard list.
     * Lazy-loads the leads via AJAX the first time it is opened.
     */
    $(document).on('click', '.zl-toggle-btn', function () {
        var batchId = $(this).data('batch-id');
        var $leadsRow = $('#zl-leads-' + batchId);
        var $btn = $(this);

        if ($leadsRow.is(':visible')) {
            $leadsRow.hide();
            $btn.text('▶');
        } else {
            $leadsRow.show();
            $btn.text('▼');
            loadBatchLeads(batchId);
        }
    });

    /**
     * Fetches and renders the leads for a specific batch.
     * 
     * @param {number} batchId - The DB ID of the batch.
     */
    function loadBatchLeads(batchId) {
        var $container = $('#zl-leads-container-' + batchId);

        ajaxPost('zl_get_batch_leads', { batch_id: batchId }, function (data) {
            if (!data.leads || data.leads.length === 0) {
                $container.html('<p class="zl-empty-state">No leads in this batch.</p>');
                return;
            }

            var html = '';

            // AI Summary
            if (data.batch && data.batch.ai_summary) {
                html += '<div class="zl-ai-summary"><strong>📊 AI Summary:</strong> ' + escapeHtml(data.batch.ai_summary) + '</div>';
            }

            // Leads as cards — salesperson-friendly format
            data.leads.forEach(function (lead, idx) {
                // Determine CSS classes based on lead score and status
                var scoreClass = lead.score >= 70 ? 'zl-score-high' : (lead.score >= 40 ? 'zl-score-med' : 'zl-score-low');
                var statusClass = lead.contact_status === 'contacted' ? 'zl-contacted' : (lead.contact_status === 'skipped' ? 'zl-skipped' : 'zl-pending');
                var fullName = escapeHtml((lead.first_name + ' ' + lead.last_name).trim());

                html += '<div class="zl-lead-card" data-lead-id="' + lead.id + '">';

                // Header row: score + name + status + actions
                html += '<div class="zl-card-header">';
                html += '<span class="zl-score ' + scoreClass + '">' + parseFloat(lead.score).toFixed(0) + '</span>';
                html += '<div class="zl-card-name">';
                html += '<strong>' + fullName + '</strong>';
                // Only show organization if it's a real company name — not just the person's name repeated
                // (FreshBooks auto-fills org with the customer name when no company is specified)
                if (lead.organization && lead.organization.trim().toLowerCase() !== fullName.trim().toLowerCase()) {
                    html += ' <span class="zl-card-org">(' + escapeHtml(lead.organization) + ')</span>';
                }
                html += '</div>';
                html += '<span class="zl-contact-status ' + statusClass + '">' + escapeHtml(ucfirst(lead.contact_status)) + '</span>';
                
                // Nutshell Status Badge
                var nsStatus = lead.nutshell_status ? lead.nutshell_status.toLowerCase() : 'open';
                var nsStatusClass = 'zl-ns-open';
                if (nsStatus === 'won') nsStatusClass = 'zl-ns-won';
                else if (nsStatus === 'lost') nsStatusClass = 'zl-ns-lost';
                else if (nsStatus === 'cancelled') nsStatusClass = 'zl-ns-cancelled';
                if (lead.nutshell_status) {
                    html += '<span class="zl-ns-status-badge ' + nsStatusClass + '">' + escapeHtml(ucfirst(lead.nutshell_status)) + '</span>';
                }

                html += '<div class="zl-card-actions">';
                if (lead.contact_status !== 'contacted') {
                    html += '<button class="button button-small zl-mark-contacted" data-lead-id="' + lead.id + '">✓ Contacted</button> ';
                }
                if (lead.contact_status !== 'skipped') {
                    html += '<button class="button button-small zl-mark-skipped" data-lead-id="' + lead.id + '">✕ Skip</button>';
                }
                
                // Nutshell Link Button
                if (lead.nutshell_lead_id) {
                    html += ' <a href="https://app.nutshell.com/lead/' + lead.nutshell_lead_id + '" target="_blank" class="button button-small zl-nutshell-link">Nutshell ↗</a>';
                }
                
                html += '</div>';
                html += '</div>';

                // Location — prominent display
                if (lead.city) {
                    html += '<div class="zl-card-location">📍 ' + escapeHtml(lead.city) + '</div>';
                }

                // Contact info row
                html += '<div class="zl-card-contact">';
                if (lead.email) html += '📧 <a href="mailto:' + escapeHtml(lead.email) + '">' + escapeHtml(lead.email) + '</a>';
                if (lead.email && lead.phone) html += ' &nbsp;|&nbsp; ';
                if (lead.phone) html += '📞 <a href="tel:' + escapeHtml(lead.phone) + '">' + escapeHtml(lead.phone) + '</a>';
                if (lead.nutshell_lead_id) html += ' &nbsp;|&nbsp; NS Lead #' + escapeHtml(lead.nutshell_lead_id);
                html += '</div>';

                // What they bought — the key info for the salesperson (Refined by AI)
                html += '<div class="zl-card-section">';
                html += '<span class="zl-card-label">What they bought:</span> ';
                html += escapeHtml(lead.purchase_summary || 'No purchase details available');
                html += '</div>';

                // Nutshell insights — what we know about them from CRM cross-referencing
                if (lead.nutshell_interests) {
                    html += '<div class="zl-card-section">';
                    html += '<span class="zl-card-label">What we know:</span> ';
                    html += escapeHtml(lead.nutshell_interests);
                    html += '</div>';
                }

                // Contact notes if any (added by salesperson)
                if (lead.contact_notes) {
                    html += '<div class="zl-card-section zl-card-notes">';
                    html += '<span class="zl-card-label">Notes:</span> ' + escapeHtml(lead.contact_notes);
                    html += '</div>';
                }

                // Salesperson Notes (from Nutshell)
                if (lead.salesperson_notes) {
                    html += '<div class="zl-sp-notes">';
                    html += '<div class="zl-collapsible-toggle">📝 Salesperson Notes <span>▼</span></div>';
                    html += '<div class="zl-collapsible-content" style="display:none;">' + escapeHtml(lead.salesperson_notes) + '</div>';
                    html += '</div>';
                }

                // Purchase History
                if (lead.purchase_history) {
                    var ph = safelyParseJSON(lead.purchase_history);
                    if (ph && ph.length > 0) {
                        html += '<div class="zl-purchase-history">';
                        html += '<div class="zl-collapsible-toggle">🛒 Purchase History <span>▼</span></div>';
                        html += '<div class="zl-collapsible-content" style="display:none;"><ul>';
                        ph.forEach(function(item) {
                            var desc = item.description || item.name || 'Item';
                            var amt = item.amount !== undefined ? parseFloat(item.amount).toFixed(2) : '0.00';
                            var dt = item.date || item.created_at || '';
                            html += '<li><strong>' + escapeHtml(dt) + '</strong>: ' + escapeHtml(desc) + ' ($' + escapeHtml(amt) + ')</li>';
                        });
                        html += '</ul></div></div>';
                    }
                }

                // Custom Fields
                if (lead.nutshell_custom_fields) {
                    var cf = safelyParseJSON(lead.nutshell_custom_fields);
                    if (cf && Object.keys(cf).length > 0) {
                        html += '<div class="zl-custom-fields">';
                        html += '<div class="zl-collapsible-toggle">📋 Custom Fields <span>▼</span></div>';
                        html += '<div class="zl-collapsible-content" style="display:none;"><ul>';
                        for (var key in cf) {
                            html += '<li><strong>' + escapeHtml(key) + ':</strong> ' + escapeHtml(String(cf[key])) + '</li>';
                        }
                        html += '</ul></div></div>';
                    }
                }

                html += '</div>'; // end card
            });
            $container.html(html);
        });
    }

    // ── Collapsible toggles ───────────────────────────────────────────
    $(document).on('click', '.zl-collapsible-toggle', function() {
        var $content = $(this).next('.zl-collapsible-content');
        $content.slideToggle(200);
        var $icon = $(this).find('span');
        if ($icon.text() === '▼') $icon.text('▲');
        else $icon.text('▼');
    });

    // ── Sync Nutshell ─────────────────────────────────────────────────
    $(document).on('click', '#zl-btn-sync-nutshell', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Syncing...');
        
        var $progress = $('#zl-sync-progress');
        if(!$progress.length) {
            $btn.after('<span id="zl-sync-progress" style="margin-left:10px;color:#2271b1;font-weight:600;">Syncing with Nutshell...</span>');
            $progress = $('#zl-sync-progress');
        } else {
            $progress.text('Syncing with Nutshell...').css('color', '#2271b1').show();
        }

        ajaxPost('zl_sync_nutshell', {}, function(data) {
            $btn.prop('disabled', false).text('Sync Nutshell');
            var syncMsg = '✓ Synced ' + (data.synced || 0) + ' leads.';
            if (data.freed && data.freed > 0) {
                syncMsg += ' 🔓 ' + data.freed + ' deleted leads freed up for future searches!';
            }
            $progress.text(syncMsg).css('color', '#065f46').delay(5000).fadeOut();
            
            // Reload currently open batches to reflect new synced data
            $('.zl-toggle-btn').each(function() {
                if ($(this).text() === '▼') {
                    loadBatchLeads($(this).data('batch-id'));
                }
            });
        }, function(err) {
            $btn.prop('disabled', false).text('Sync Nutshell');
            $progress.text('❌ Error: ' + err).css('color', '#991b1b');
        });
    });

    // ── Lead actions ──────────────────────────────────────────────────

    /**
     * Marks a lead as contacted and allows adding optional notes.
     */
    $(document).on('click', '.zl-mark-contacted', function () {
        var leadId = $(this).data('lead-id');
        var notes = prompt('Add a note (optional):') || '';
        updateContactStatus(leadId, 'contacted', notes);
    });

    /**
     * Marks a lead as skipped (e.g., bad fit, outside territory).
     */
    $(document).on('click', '.zl-mark-skipped', function () {
        var leadId = $(this).data('lead-id');
        updateContactStatus(leadId, 'skipped', '');
    });

    /**
     * Updates the status of a lead in the database and refreshes the UI.
     */
    function updateContactStatus(leadId, status, notes) {
        ajaxPost('zl_update_contact_status', {
            lead_id: leadId,
            contact_status: status,
            contact_notes: notes
        }, function () {
            // Refresh the leads for the parent batch
            var $row = $('[data-lead-id="' + leadId + '"]').closest('.zl-leads-container');
            var batchId = $row.attr('id').replace('zl-leads-container-', '');
            loadBatchLeads(batchId);
        });
    }

    // ── Delete batch ─────────────────────────────────────────────────

    /**
     * Deletes a batch (any type) and removes it from the UI.
     * Shows lead count in confirm dialog so user knows what they're deleting.
     * Also clears cooldown history so those customers can be re-generated.
     */
    $(document).on('click', '.zl-delete-batch', function () {
        var $btn = $(this);
        var batchId = $btn.data('batch-id');
        var leadCount = parseInt($btn.data('lead-count'), 10) || 0;
        var batchTag = $btn.data('batch-tag') || 'Batch #' + batchId;

        var msg = 'Delete "' + batchTag + '"';
        if (leadCount > 0) {
            msg += ' and its ' + leadCount + ' lead' + (leadCount !== 1 ? 's' : '') + '?\n\n';
            msg += 'This will also clear cooldown entries so these customers can be re-generated.';
        } else {
            msg += '?\n\nThis batch has no leads.';
        }
        if (!confirm(msg)) return;

        ajaxPost('zl_delete_batch', { batch_id: batchId }, function () {
            // Remove the batch row and its leads sub-row
            $btn.closest('tr').fadeOut(300, function () { $(this).remove(); });
            $('#zl-leads-' + batchId).fadeOut(300, function () { $(this).remove(); });
        });
    });

    // ── Send test batch to Nutshell ─────────────────────────────────

    /**
     * Converts a test batch into a real batch by pushing its leads to Nutshell CRM.
     */
    $(document).on('click', '.zl-send-to-nutshell', function () {
        var batchId = $(this).data('batch-id');
        if (!confirm('Send all leads in this test batch to Nutshell CRM as formal leads?\n\nThis will create real leads in Nutshell and mark them for cooldown tracking.')) return;

        var $btn = $(this);
        $btn.prop('disabled', true).text('Sending...');
        sendTestToNutshell(batchId, 0, $btn, 0, 0);
    });

    /**
     * Recursively sends test leads to Nutshell in chunks.
     * Includes a safety limit (10 attempts) to prevent infinite loops if the API fails repeatedly.
     */
    function sendTestToNutshell(batchId, offset, $btn, totalCreated, attempts) {
        // Safety: max 10 AJAX round-trips to prevent infinite loops
        if (attempts >= 10) {
            $btn.text('📤 Send to Nutshell').prop('disabled', false).removeClass('button-disabled');
            alert('Stopped after ' + attempts + ' attempts. ' + totalCreated + ' leads sent successfully. Some leads may have failed — check the debug log.');
            loadBatchLeads(batchId);
            return;
        }

        ajaxPost('zl_send_test_to_nutshell', { batch_id: batchId, offset: offset }, function (data) {
            var running = totalCreated + (data.created || 0);
            if (data.done) {
                $btn.text('✓ Sent (' + running + ')').addClass('button-disabled').prop('disabled', true);
                // Refresh the leads display to show Nutshell lead IDs
                loadBatchLeads(batchId);
            } else {
                sendTestToNutshell(batchId, data.next_offset, $btn, running, attempts + 1);
            }
        }, function (errorMsg) {
            // Error callback — reset button and show visible error
            $btn.text('📤 Send to Nutshell').prop('disabled', false).removeClass('button-disabled');
            if (totalCreated > 0) {
                alert('Sent ' + totalCreated + ' leads, but encountered an error:\n\n' + errorMsg);
            } else {
                alert('Failed to send leads to Nutshell:\n\n' + errorMsg + '\n\nCheck Settings to ensure Nutshell credentials are configured.');
            }
            loadBatchLeads(batchId);
        });
    }

    // ── Utility ───────────────────────────────────────────────────────

    /**
     * Capitalizes the first letter of a string.
     */
    function ucfirst(str) {
        if (!str) return '';
        return str.charAt(0).toUpperCase() + str.slice(1);
    }

})(jQuery);