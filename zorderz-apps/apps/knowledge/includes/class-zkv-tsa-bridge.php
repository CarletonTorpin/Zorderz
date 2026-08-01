<?php
/**
 * ZKV_TSA_Bridge — For TSA to inject vault context into Brain Bot.
 *
 * Usage in TSA's analytics engine:
 *   if ( class_exists( 'ZKV_TSA_Bridge' ) ) {
 *       $system_prompt .= ZKV_TSA_Bridge::get_inventory();   // compact doc list
 *       $vault_block    = ZKV_TSA_Bridge::get_context( $q );  // topic-matched content
 *   }
 *
 * v1.2.6: Added get_inventory(), cache invalidation, removed [VAULT-{id}] citation style.
 * v1.3.0: Added content chunk search — get_context() now returns actual document
 *         content excerpts alongside AI summaries, so Brain Bot can answer specific
 *         questions (pricing, dimensions, part numbers) from raw document text.
 * v1.3.1: Boosted chunk retrieval for pricing authority docs — more chunks with
 *         larger excerpts. Added pricing query detection and fallback chunk pull
 *         for pricing docs even when FULLTEXT doesn't match (tables are mostly numbers).
 * v1.3.2: FIXED — pricing chunk fallback was returning cover pages instead of
 *         pricing tables. Sequential retrieval (ORDER BY chunk_index ASC) grabbed
 *         the start of the PDF. New approach: scores ALL chunks by 4-digit number
 *         density (pricing tables have 100-250 four-digit dollar amounts per chunk)
 *         and returns the top 8 densest chunks. Reliably finds pricing grids
 *         regardless of query keywords.
 * v1.3.3: ROBUSTNESS — diagnostic logging at every decision point so future
 *         issues are visible in debug.log instead of requiring blind debugging.
 *         Graceful degradation when density scoring finds 0 qualifying chunks
 *         (lowers threshold progressively). Document priority fix ensures pricing
 *         authority docs always survive the max_docs trim.
 * v1.5.0: PRIVATE TRANSCRIPTS + the bridge is finally visibility-aware.
 *         This bridge is the Brain Bot's REAL retrieval path (the engine calls
 *         it in-process; REST is not involved), and before 1.5.0 it applied NO
 *         visibility filter at all — admin_only chunk text could reach any
 *         user's chat context, and a private transcript would have too.
 *         Now:
 *         - Every query that can return document/index/chunk rows carries
 *           ZKV_ACL::sql_where_chat( $uid ) — the PARTY-ONLY predicate.
 *           Chat deliberately uses the strict mode: a shared-with recipient
 *           can *read* a transcript lent to them in the Vault, but their
 *           Brain Bot never answers from it (a share lends a view, not a
 *           chat seat).
 *         - get_context() takes the requesting user id from the engine
 *           ($uid param; falls back to get_current_user_id(), which the TSA
 *           cron worker restores via wp_set_current_user before processing).
 *         - get_inventory() NEVER lists transcripts (a private conversation
 *           is not "reference material the team can draw on" — its title
 *           does not belong in a shared capabilities list), and the shared
 *           transient is now TIER-KEYED (admin/staff) so admin_only titles
 *           no longer leak to non-admin prompts through a shared cache.
 *         - The pricing-density path skips (and loudly logs) any pricing-
 *           authority doc that is somehow a transcript, and its raw chunk
 *           reads are ACL-joined like everything else — no reader is exempt
 *           by assumption.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ZKV_TSA_Bridge {

	/** Legacy (pre-1.5.0) transient key — cleared on invalidate for upgrades. */
	private static $inventory_cache_key = 'zkv_tsa_inventory';

	/**
	 * Compact listing of indexed vault documents — titles and types only.
	 * Injected into every Brain Bot system prompt so the AI knows what
	 * reference material exists, even before topic-matching fires.
	 *
	 * v1.5.0: transcripts are excluded OUTRIGHT (never listed, for anyone —
	 * parties still get transcript *content* through get_context() when
	 * relevant). The cache is keyed by visibility tier so one user's list is
	 * never served to a different tier: admins see admin_only titles, staff
	 * do not (pre-1.5.0 a single shared transient listed everything to
	 * everyone).
	 *
	 * Cached for 1 hour; invalidated on document index / delete.
	 *
	 * @param int|null $uid Requesting user (default: current user).
	 * @return string  Block to append to system prompt, or empty string.
	 */
	public static function get_inventory( $uid = null ) {
		$uid      = ( null === $uid ) ? get_current_user_id() : (int) $uid;
		$is_admin = class_exists( 'ZKV_ACL' ) ? ZKV_ACL::is_admin_user( $uid ) : false;
		$tier_key = 'zkv_tsa_inventory_' . ( $is_admin ? 'admin' : 'staff' );

		$cached = get_transient( $tier_key );
		if ( false !== $cached ) { return $cached; }

		global $wpdb;
		// Tier clause: transcripts excluded for EVERYONE (no admin bypass);
		// admin_only titles only in the admin tier. Literal values only.
		$tier_sql = $is_admin
			? " AND d.visibility <> 'transcript_private'"
			: " AND d.visibility = 'all_employees'";

		$rows = $wpdb->get_results(
			"SELECT d.id, d.title, i.document_type
			 FROM {$wpdb->prefix}zkv_index i
			 JOIN {$wpdb->prefix}zkv_documents d ON d.id = i.document_id
			 WHERE i.is_current = 1 AND d.status = 'indexed'{$tier_sql}
			 ORDER BY d.title ASC",
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			set_transient( $tier_key, '', HOUR_IN_SECONDS );
			return '';
		}

		$block = "\n═══ KNOWLEDGE VAULT — Available Documents ═══\n";
		foreach ( $rows as $r ) {
			$block .= "- {$r['title']} [{$r['document_type']}] (vault #{$r['id']})\n";
		}
		$block .= "(Use vault content naturally — do not add citation brackets.)\n";

		set_transient( $tier_key, $block, HOUR_IN_SECONDS );
		return $block;
	}

	/**
	 * Invalidate the inventory cache (call on document index, delete, or status change).
	 */
	public static function invalidate_cache() {
		delete_transient( self::$inventory_cache_key ); // legacy pre-1.5.0 key
		delete_transient( 'zkv_tsa_inventory_admin' );
		delete_transient( 'zkv_tsa_inventory_staff' );
	}

	/**
	 * Topic-matched vault content for injection into data context.
	 *
	 * v1.3.0: Now performs a two-layer search:
	 *   Layer 1: FULLTEXT on AI-generated index (synopsis, key_facts, tags)
	 *   Layer 2: FULLTEXT on raw content chunks (actual document text)
	 * When content chunks match, the relevant excerpt is included in the
	 * context block so Brain Bot has the specific details (prices, specs,
	 * dimensions) needed to answer precisely.
	 *
	 * @param string   $query    The user's question.
	 * @param int      $max_docs Max documents to return (default 8).
	 * @param int|null $uid      Requesting user id (v1.5.0). Engine passes it
	 *                           explicitly; defaults to get_current_user_id()
	 *                           (valid even in the TSA cron worker, which
	 *                           restores identity via wp_set_current_user).
	 * @return string  Block to append to system prompt, or empty string.
	 */
	public static function get_context( $query, $max_docs = 8, $uid = null ) {
		global $wpdb;
		$query = trim( (string) $query );
		if ( empty( $query ) ) { return ''; }

		// v1.5.0: THE chat-mode ACL. Party-only — shares never widen chat.
		// Placeholder-free fragment (hard-cast ints), safe to interpolate into
		// the prepared queries below without disturbing positional bindings.
		// Fail closed: if the ACL class is somehow missing, use the legacy
		// staff visibility rule, which excludes every transcript.
		$uid = ( null === $uid ) ? get_current_user_id() : (int) $uid;
		$acl = class_exists( 'ZKV_ACL' )
			? ZKV_ACL::sql_where_chat( $uid, 'd' )
			: " AND d.visibility = 'all_employees'";

		// Layer 1: AI index search (synopsis, key_facts, tags).
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT d.id, d.title, d.user_context, d.slug, i.synopsis, i.key_facts, i.document_type
			 FROM {$wpdb->prefix}zkv_index i
			 JOIN {$wpdb->prefix}zkv_documents d ON d.id = i.document_id
			 WHERE i.is_current = 1 AND d.status = 'indexed'{$acl}
			   AND MATCH(i.search_text) AGAINST(%s IN NATURAL LANGUAGE MODE)
			 ORDER BY MATCH(i.search_text) AGAINST(%s IN NATURAL LANGUAGE MODE) DESC
			 LIMIT %d",
			$query, $query, (int) $max_docs
		), ARRAY_A );

		// Layer 2: Content chunk search (actual document text).
		// Boost limit when the query looks pricing-related. The keyword list is
		// GENERALIZED: generic pricing cues ship in Core, and the business-specific
		// product/brand tokens that used to be hardcoded here come from the
		// settings-driven zkv_pricing_keywords() (empty by default; fed by the
		// Item Engine through the zkv_product_keywords filter when it lands).
		$pricing_keywords = function_exists( 'zkv_pricing_keywords' )
			? zkv_pricing_keywords()
			: array( 'price', 'pricing', 'cost', 'charge', 'how much', 'msrp', 'retail', 'quote' );
		$query_lower = strtolower( $query );
		$is_pricing_query = false;
		foreach ( $pricing_keywords as $pk ) {
			if ( strpos( $query_lower, $pk ) !== false ) {
				$is_pricing_query = true;
				break;
			}
		}
		$chunk_limit = $is_pricing_query ? 24 : 12;

		// v1.5.0: THE CRITICAL FIX. This chunk query previously joined nothing
		// and filtered nothing — raw chunk_text from ANY document (admin_only
		// included) could flow into the model context for any user. It now
		// joins documents and carries the party-only ACL, so a transcript
		// chunk is not merely withheld from the answer — it is never selected.
		$chunk_matches = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.document_id, c.chunk_text,
			        MATCH(c.search_text) AGAINST(%s IN NATURAL LANGUAGE MODE) as relevance
			 FROM {$wpdb->prefix}zkv_chunks c
			 JOIN {$wpdb->prefix}zkv_documents d ON d.id = c.document_id
			 WHERE MATCH(c.search_text) AGAINST(%s IN NATURAL LANGUAGE MODE){$acl}
			 ORDER BY relevance DESC LIMIT %d",
			$query, $query, $chunk_limit
		), ARRAY_A );

		// v1.3.1: Identify pricing authority doc IDs for boosted retrieval.
		$pricing_doc_ids = array();
		if ( class_exists( 'ZKV_Bridge' ) && method_exists( 'ZKV_Bridge', 'get_pricing_context' ) ) {
			$pc = ZKV_Bridge::get_pricing_context();
			$pricing_doc_ids = $pc['doc_ids'] ?? array();
		}

		// Index chunk content by document_id.
		// v1.3.1: For pricing authority docs, keep more excerpts with larger size
		// to capture pricing tables that span multiple chunks.
		$chunk_content = array();
		if ( ! empty( $chunk_matches ) ) {
			foreach ( $chunk_matches as $cm ) {
				$did = (int) $cm['document_id'];
				$is_pricing = in_array( $did, $pricing_doc_ids, true );
				$max_chunks  = $is_pricing ? 6 : 2;
				$excerpt_len = $is_pricing ? 2000 : 600;

				if ( ! isset( $chunk_content[ $did ] ) ) {
					$chunk_content[ $did ] = array();
				}
				if ( count( $chunk_content[ $did ] ) < $max_chunks ) {
					$chunk_content[ $did ][] = mb_substr( trim( $cm['chunk_text'] ), 0, $excerpt_len, 'UTF-8' );
				}
			}
		}

		// v1.3.2: For pricing queries, pull the DENSEST pricing chunks from
		// pricing authority docs. The FULLTEXT search fails on pricing tables
		// because tables contain only numbers — no matching keywords. The
		// sequential fallback (v1.3.1 ORDER BY chunk_index ASC) returned
		// cover pages and T&C instead of actual pricing grids.
		//
		// FIX: Load all chunks for the pricing doc, score each by the count
		// of 4-digit numbers (pricing table rows are dense with dollar
		// amounts like "1719 1822 1925 2028 2131"), and return the top chunks.
		// This reliably finds the pricing grid pages regardless of which
		// keywords the user used.
		if ( $is_pricing_query && ! empty( $pricing_doc_ids ) ) {
			foreach ( $pricing_doc_ids as $pdid ) {
				// v1.5.0 (D5): a pricing-authority doc must never be a private
				// transcript. If one ever is, SKIP it loudly rather than pull
				// its chunks through this shared-transient path — no reader is
				// exempt from the ACL by assumption.
				if ( class_exists( 'ZKV_ACL' ) && ZKV_ACL::is_transcript( (int) $pdid ) ) {
					error_log( 'ZKV bridge: SKIPPING pricing doc ' . $pdid
						. ' — it is a private transcript (pricing ∩ transcripts must be empty; fix the pricing-authority flag).' );
					continue;
				}

				// v1.3.3: Cache the density-scored chunk selection. The Product
				// Book's pricing tables don't change between queries — loading 100
				// chunks from MySQL and scoring each one in PHP was the main
				// contributor to WP Engine timeout on follow-up queries.
				// (Safe to share across users: the transcript skip above plus the
				// ACL-joined reads below mean only non-transcript pricing chunks —
				// identical for every user — can ever enter this transient.)
				$chunk_count = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}zkv_chunks c
					 JOIN {$wpdb->prefix}zkv_documents d ON d.id = c.document_id
					 WHERE c.document_id = %d{$acl}",
					$pdid
				) );
				$density_cache_key = 'zkv_density_' . $pdid . '_' . $chunk_count;
				$cached_chunks = get_transient( $density_cache_key );

				if ( is_array( $cached_chunks ) && ! empty( $cached_chunks ) ) {
					$chunk_content[ $pdid ] = $cached_chunks;
					error_log( 'ZKV bridge: pricing doc ' . $pdid . ' — using cached density scores (' . count( $cached_chunks ) . ' chunks).' );
					continue;
				}

				// Get ALL chunks for this pricing doc (ACL-joined — §6.5: no
				// chunk SELECT runs outside the predicate, even this one).
				$all_chunks = $wpdb->get_results( $wpdb->prepare(
					"SELECT c.chunk_index, c.chunk_text
					 FROM {$wpdb->prefix}zkv_chunks c
					 JOIN {$wpdb->prefix}zkv_documents d ON d.id = c.document_id
					 WHERE c.document_id = %d{$acl}
					 ORDER BY c.chunk_index ASC",
					$pdid
				), ARRAY_A );

				if ( empty( $all_chunks ) ) {
					error_log( 'ZKV bridge: pricing doc ' . $pdid . ' has 0 chunks in DB — run chunk seeder or re-index.' );
					continue;
				}

				// Score each chunk by density of 4-digit numbers.
				// v1.3.3: Graceful degradation — try threshold 15, then 8, then 3.
				$scored = array();
				foreach ( $all_chunks as $c ) {
					$count = preg_match_all( '/\b\d{4}\b/', $c['chunk_text'] );
					$scored[] = array(
						'chunk_text'  => $c['chunk_text'],
						'chunk_index' => (int) $c['chunk_index'],
						'score'       => $count,
					);
				}

				// Pick top 10 densest chunks (core pricing tables)
				$best = array();
				foreach ( array( 15, 8, 3 ) as $threshold ) {
					$qualified = array_filter( $scored, function( $s ) use ( $threshold ) {
						return $s['score'] >= $threshold;
					} );
					if ( ! empty( $qualified ) ) {
						usort( $qualified, function( $a, $b ) { return $b['score'] - $a['score']; } );
						$best = array_slice( $qualified, 0, 10 );
						break;
					}
				}

				// Product-line coverage — density scoring favours product lines with
				// wider dimension ranges (more columns = more numbers), so a
				// narrower line can get squeezed out. Rescue a chunk that names any
				// CONFIGURED product/brand token and still carries pricing data.
				// GENERALIZED: the token list is tenant data (zkv_product_keywords),
				// matched with OCR-spacing tolerance (a token like "WIDGET" also
				// matches the space-mangled "W I D G E T" PDF extraction produces). Core
				// ships NO tokens, so this boost simply no-ops and density scoring
				// stands alone — the mechanic is intact, the product literals are gone.
				$product_tokens = function_exists( 'zkv_product_keywords' ) ? zkv_product_keywords() : array();
				if ( ! empty( $product_tokens ) ) {
					$best_indices = array_map( function( $b ) { return $b['chunk_index']; }, $best );
					foreach ( $all_chunks as $c ) {
						if ( count( $best ) >= 14 ) { break; }
						if ( in_array( (int) $c['chunk_index'], $best_indices, true ) ) { continue; }
						if ( ! self::chunk_matches_product_token( $c['chunk_text'], $product_tokens ) ) { continue; }
						// Must have at least some pricing data (the density mechanic).
						$score = preg_match_all( '/\b\d{4}\b/', $c['chunk_text'] );
						if ( $score >= 5 ) {
							$best[] = array(
								'chunk_text'  => $c['chunk_text'],
								'chunk_index' => (int) $c['chunk_index'],
								'score'       => $score,
							);
							$best_indices[] = (int) $c['chunk_index'];
						}
					}
				}

				// Sort by chunk_index for document order
				usort( $best, function( $a, $b ) { return $a['chunk_index'] - $b['chunk_index']; } );

				error_log( 'ZKV bridge: pricing doc ' . $pdid . ' — ' . count( $all_chunks )
					. ' chunks loaded, returning ' . count( $best )
					. ' (density + product coverage, scores: '
					. implode( ',', array_map( function( $b ) { return $b['score']; },
						array_slice( $best, 0, 6 ) ) ) . ').' );

				if ( empty( $best ) ) {
					error_log( 'ZKV bridge: pricing doc ' . $pdid . ' — 0 qualifying chunks. Run chunk seeder.' );
					continue;
				}

				// Replace FULLTEXT results with density + coverage chunks.
				$chunk_content[ $pdid ] = array();
				foreach ( $best as $b ) {
					$chunk_content[ $pdid ][] = mb_substr( trim( $b['chunk_text'] ), 0, 2000, 'UTF-8' );
				}

				// Cache the scored chunks for 12 hours (invalidated by chunk count change)
				set_transient( $density_cache_key, $chunk_content[ $pdid ], 43200 );
			}
		}

		// Merge documents found only via chunk search into results.
		$found_ids = array_map( function( $r ) { return (int) $r['id']; }, $rows ?: array() );
		$new_chunk_ids = array_diff( array_keys( $chunk_content ), $found_ids );

		// v1.3.1: For pricing queries, also ensure pricing authority docs
		// are in the result set even if they had no chunk or index matches.
		if ( $is_pricing_query && ! empty( $pricing_doc_ids ) ) {
			$missing_pricing = array_diff( $pricing_doc_ids, $found_ids, array_keys( $chunk_content ) );
			$new_chunk_ids = array_unique( array_merge( $new_chunk_ids, $missing_pricing ) );
		}

		if ( ! empty( $new_chunk_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $new_chunk_ids ), '%d' ) );
			// v1.5.0: ACL applies here too — this query decides which chunk-only
			// docs surface in the block; before 1.5.0 it was unfiltered.
			$extra_docs = $wpdb->get_results( $wpdb->prepare(
				"SELECT d.id, d.title, d.user_context, d.slug, i.synopsis, i.key_facts, i.document_type
				 FROM {$wpdb->prefix}zkv_documents d
				 JOIN {$wpdb->prefix}zkv_index i ON i.document_id = d.id AND i.is_current = 1
				 WHERE d.id IN ({$placeholders}) AND d.status = 'indexed'{$acl}",
				...$new_chunk_ids
			), ARRAY_A );
			if ( ! empty( $extra_docs ) ) {
				$rows = array_merge( $rows ?: array(), $extra_docs );
			}
		}

		if ( empty( $rows ) ) { return ''; }

		// v1.3.2: For pricing queries, move pricing authority docs to the front
		// so they survive the max_docs trim. Without this, Layer 1 FULLTEXT
		// fills all slots with non-pricing docs (install transcript, safety
		// policies, etc.) and the Product Book gets cut off by array_slice.
		if ( $is_pricing_query && ! empty( $pricing_doc_ids ) ) {
			$priority = array();
			$rest     = array();
			foreach ( $rows as $r ) {
				if ( in_array( (int) $r['id'], $pricing_doc_ids, true ) ) {
					$priority[] = $r;
				} else {
					$rest[] = $r;
				}
			}
			$rows = array_merge( $priority, $rest );
		}

		// Trim to max_docs.
		$rows = array_slice( $rows, 0, $max_docs );

		$block = "\n═══ KNOWLEDGE VAULT (matched reference material) ═══\n";
		$block .= "Use this content naturally in your answer. Include vault links only when sharing to a channel or when the user asks for the source.\n\n";
		foreach ( $rows as $r ) {
			$slug = ! empty( $r['slug'] ) ? $r['slug'] : $r['id'];
			$did  = (int) $r['id'];

			$block .= "Vault #{$did}: {$r['title']} [{$r['document_type']}]";
			// v1.3.1: Tag pricing authority docs so the AI knows to use them for pricing lookups.
			if ( in_array( $did, $pricing_doc_ids, true ) ) {
				$block .= " ★ PRICING AUTHORITY";
			}
			$block .= "\n";
			$block .= "  {$r['synopsis']}\n";
			if ( ! empty( $r['user_context'] ) ) {
				$block .= "  Context: {$r['user_context']}\n";
			}
			$facts = json_decode( $r['key_facts'] ?? '[]', true );
			if ( is_array( $facts ) && ! empty( $facts ) ) {
				$block .= "  Key facts: " . implode( '; ', array_slice( $facts, 0, 5 ) ) . "\n";
			}
			// v1.3.0: Include matched content excerpts from raw document text.
			if ( ! empty( $chunk_content[ $did ] ) ) {
				$block .= "  ── Matched document content ──\n";
				foreach ( $chunk_content[ $did ] as $excerpt ) {
					$block .= "  " . $excerpt . "\n";
				}
				$block .= "  ── end content ──\n";
			}
			$block .= '  Link: ' . ( function_exists( 'zkv_secure_url' ) ? zkv_secure_url( $did, is_string( $slug ) ? $slug : '' ) : home_url( '/vault/' . $slug ) ) . "\n\n";
		}

		// v1.3.3: Log what the AI will receive so future issues are diagnosable.
		$chunk_total = 0;
		foreach ( $chunk_content as $chunks ) {
			$chunk_total += count( $chunks );
		}
		$pricing_in_output = 0;
		foreach ( $rows as $r ) {
			if ( in_array( (int) $r['id'], $pricing_doc_ids, true ) ) { $pricing_in_output++; }
		}
		error_log( 'ZKV bridge: returning ' . count( $rows ) . ' docs (' . $pricing_in_output . ' pricing), '
			. $chunk_total . ' chunk excerpts, ' . strlen( $block ) . ' chars total'
			. ( $is_pricing_query ? ' [PRICING QUERY]' : '' ) . '.' );

		return $block;
	}

	/**
	 * Does a chunk name any configured product/brand token?
	 *
	 * Matches a plain substring AND an OCR-spaced variant (a token "WIDGET" also
	 * matches "W I D G E T"), because PDF text extraction routinely spaces out
	 * capitalised product names. Tokens are tenant data (zkv_product_keywords); Core ships
	 * none, so with an empty list this is never called.
	 *
	 * @param string   $chunk_text
	 * @param string[] $tokens     lowercase product/brand tokens
	 * @return bool
	 */
	private static function chunk_matches_product_token( $chunk_text, $tokens ) {
		$upper = strtoupper( (string) $chunk_text );
		foreach ( (array) $tokens as $tok ) {
			$tok = strtoupper( trim( (string) $tok ) );
			if ( '' === $tok ) { continue; }
			if ( strpos( $upper, $tok ) !== false ) { return true; }
			// OCR-spaced variant: insert a space between every character.
			$compact = preg_replace( '/\s+/', '', $tok );
			if ( strlen( $compact ) > 1 ) {
				$spaced = trim( implode( ' ', str_split( $compact ) ) );
				if ( '' !== $spaced && strpos( $upper, $spaced ) !== false ) { return true; }
			}
		}
		return false;
	}
}
