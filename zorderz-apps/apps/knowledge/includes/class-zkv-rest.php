<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ZKV_Rest {

	public static function register_routes() {

		// Decline cleanly if the theme (owner of the REST namespace) is absent.
		if ( ! defined( 'ZDZ_REST_NS' ) ) {
			return;
		}

		register_rest_route( ZDZ_REST_NS, '/vault/search', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'handle_search' ),
			'permission_callback' => array( __CLASS__, 'check_access' ),
			'args' => array(
				'q'    => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'limit'=> array( 'required' => false, 'sanitize_callback' => 'absint' ),
			),
		) );

		register_rest_route( ZDZ_REST_NS, '/vault/context', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'handle_context' ),
			'permission_callback' => array( __CLASS__, 'check_access' ),
			'args' => array(
				'q' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		register_rest_route( ZDZ_REST_NS, '/vault/preview/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'handle_preview' ),
			'permission_callback' => array( __CLASS__, 'check_access' ),
		) );

		register_rest_route( ZDZ_REST_NS, '/vault/pricing', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'handle_pricing' ),
			'permission_callback' => array( __CLASS__, 'check_access' ),
		) );

		// v1.3.0: Deep search — searches actual document content chunks.
		// Returns matched excerpts with surrounding context, ideal for
		// specific lookups like a specific product-and-size pricing lookup.
		register_rest_route( ZDZ_REST_NS, '/vault/deep-search', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'handle_deep_search' ),
			'permission_callback' => array( __CLASS__, 'check_access' ),
			'args' => array(
				'q'    => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'limit'=> array( 'required' => false, 'sanitize_callback' => 'absint' ),
			),
		) );
	}

	public static function check_access() {
		if ( ! is_user_logged_in() ) { return false; }
		// v1.3.8 (theme pass item #2): real app-access to 'knowledge-vault', not blanket zdz_access_app.
		if ( is_callable( array( 'ZDZ_Plugin_API', 'user_can_access_app' ) ) ) {
			return ZDZ_Plugin_API::user_can_access_app( get_current_user_id(), 'knowledge-vault' );
		}
		return current_user_can( 'manage_options' ) || current_user_can( 'zdz_access_app' );
	}

	/**
	 * v1.3.7 (security): visibility scope for REST results -- mirror ZKV_Dashboard so the
	 * REST search/context/preview/deep-search/pricing endpoints stop leaking admin_only
	 * document content (synopsis, key facts, chunk excerpts) to non-admins and into the
	 * chat RAG / cross-plugin previews. The file-serving paths (/vault/{slug} + ajax_download)
	 * already enforce this; this closes the metadata/content side. Admin-tier sees all.
	 *
	 * v1.5.0: delegates to ZKV_ACL::sql_where_view() — the single authoritative
	 * predicate. For NON-transcripts the emitted clause preserves the exact
	 * pre-1.5.0 behavior (admins see all incl. admin_only; everyone else sees
	 * all_employees). Transcripts (visibility='transcript_private') are
	 * admitted ONLY for their parties and active whole-document sharees — with
	 * NO admin bypass. The fragment is placeholder-free, so it composes with
	 * both the prepared handlers (search/context/deep-search) and the raw
	 * pricing query without disturbing positional bindings.
	 */
	protected static function visibility_sql( $alias = 'd' ) {
		if ( class_exists( 'ZKV_ACL' ) ) {
			return ZKV_ACL::sql_where_view( get_current_user_id(), $alias );
		}
		// Fallback (ACL class missing): pre-1.5.0 behavior. A transcript's
		// visibility value matches neither branch's allow-list, so transcripts
		// stay hidden even here — fails toward hiding, never exposing.
		$is_admin = false;
		if ( class_exists( 'ZDZ_User_Roles' ) ) {
			$user  = wp_get_current_user();
			$roles = (array) ( $user ? $user->roles : array() );
			$role  = ! empty( $roles ) ? $roles[0] : '';
			$is_admin = ZDZ_User_Roles::is_admin_role( $role );
		} else {
			$is_admin = current_user_can( 'manage_options' );
		}
		if ( $is_admin ) { return " AND {$alias}.visibility <> 'transcript_private'"; }
		return " AND {$alias}.visibility = 'all_employees'";
	}

	public static function handle_search( $request ) {
		global $wpdb;
		$q     = $request->get_param( 'q' );
		$limit = min( (int) ( $request->get_param( 'limit' ) ?: 20 ), 50 );
		$vis   = self::visibility_sql( 'd' );

		// v1.3.0: Search BOTH the AI-generated index AND raw content chunks.
		// Index search finds documents by synopsis/key_facts/tags.
		// Chunk search finds documents by their actual extracted content.
		// This lets specific product-and-size pricing queries hit
		// exact text from supplier PDFs, not just AI summaries.

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT d.id, d.title, d.created_at, i.synopsis, i.key_facts, i.document_type,
			        MATCH(i.search_text) AGAINST(%s IN NATURAL LANGUAGE MODE) as relevance
			 FROM {$wpdb->prefix}zkv_index i
			 JOIN {$wpdb->prefix}zkv_documents d ON d.id = i.document_id
			 WHERE i.is_current = 1 AND d.status = 'indexed'{$vis}
			   AND MATCH(i.search_text) AGAINST(%s IN NATURAL LANGUAGE MODE)
			 ORDER BY relevance DESC LIMIT %d",
			$q, $q, $limit
		), ARRAY_A );

		// Also search content chunks for documents not already found.
		$found_ids = array();
		if ( is_array( $rows ) ) {
			$found_ids = array_map( function( $r ) { return (int) $r['id']; }, $rows );
		}

		// v1.5.0: the chunk-id pass now joins documents + the ACL predicate.
		// (Candidate ids were already re-verified through the scoped doc query
		// below before being returned, but §6.5's invariant is that NO chunk
		// SELECT runs unscoped — and unscoped ids here could let invisible
		// docs consume result slots.)
		$chunk_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT c.document_id,
			        MATCH(c.search_text) AGAINST(%s IN NATURAL LANGUAGE MODE) as chunk_relevance
			 FROM {$wpdb->prefix}zkv_chunks c
			 JOIN {$wpdb->prefix}zkv_documents d ON d.id = c.document_id
			 WHERE MATCH(c.search_text) AGAINST(%s IN NATURAL LANGUAGE MODE){$vis}
			 ORDER BY chunk_relevance DESC LIMIT %d",
			$q, $q, $limit
		), ARRAY_A );

		if ( ! empty( $chunk_rows ) ) {
			$new_ids = array();
			foreach ( $chunk_rows as $cr ) {
				$did = (int) $cr['document_id'];
				if ( ! in_array( $did, $found_ids, true ) ) {
					$new_ids[] = $did;
				}
			}
			if ( ! empty( $new_ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $new_ids ), '%d' ) );
				$chunk_docs = $wpdb->get_results( $wpdb->prepare(
					"SELECT d.id, d.title, d.created_at, i.synopsis, i.key_facts, i.document_type, 0 as relevance
					 FROM {$wpdb->prefix}zkv_documents d
					 JOIN {$wpdb->prefix}zkv_index i ON i.document_id = d.id AND i.is_current = 1
					 WHERE d.id IN ({$placeholders}) AND d.status = 'indexed'{$vis}",
					...$new_ids
				), ARRAY_A );
				if ( ! empty( $chunk_docs ) ) {
					$rows = array_merge( $rows ?: array(), $chunk_docs );
				}
			}
		}

		// Rewrite file URLs to authenticated proxy — never expose raw paths.
		if ( is_array( $rows ) ) {
			foreach ( $rows as &$r ) {
				$r['file_url'] = zkv_secure_url( (int) $r['id'] );
			}
		}

		return rest_ensure_response( array( 'success' => true, 'data' => $rows ?: array() ) );
	}

	public static function handle_context( $request ) {
		global $wpdb;
		$q = $request->get_param( 'q' );

		$vis = self::visibility_sql( 'd' );

		// v1.3.0: Two-layer search — AI index + content chunks.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT d.id, d.title, d.created_at, i.synopsis, i.key_facts, i.document_type
			 FROM {$wpdb->prefix}zkv_index i
			 JOIN {$wpdb->prefix}zkv_documents d ON d.id = i.document_id
			 WHERE i.is_current = 1 AND d.status = 'indexed'{$vis}
			   AND MATCH(i.search_text) AGAINST(%s IN NATURAL LANGUAGE MODE)
			 ORDER BY MATCH(i.search_text) AGAINST(%s IN NATURAL LANGUAGE MODE) DESC
			 LIMIT 8",
			$q, $q
		), ARRAY_A );

		// Also find documents via content chunk matches.
		$chunk_matches = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.document_id, c.chunk_text,
			        MATCH(c.search_text) AGAINST(%s IN NATURAL LANGUAGE MODE) as relevance
			 FROM {$wpdb->prefix}zkv_chunks c
			 JOIN {$wpdb->prefix}zkv_documents d ON d.id = c.document_id
			 WHERE MATCH(c.search_text) AGAINST(%s IN NATURAL LANGUAGE MODE){$vis}
			 ORDER BY relevance DESC LIMIT 10",
			$q, $q
		), ARRAY_A );

		// Index matched chunk content by document_id for easy lookup.
		$chunk_content = array();
		if ( ! empty( $chunk_matches ) ) {
			foreach ( $chunk_matches as $cm ) {
				$did = (int) $cm['document_id'];
				if ( ! isset( $chunk_content[ $did ] ) ) {
					$chunk_content[ $did ] = array();
				}
				// Keep the best-matching excerpt (first 500 chars of chunk).
				$chunk_content[ $did ][] = mb_substr( trim( $cm['chunk_text'] ), 0, 500, 'UTF-8' );
			}
		}

		// Merge chunk-only documents into results.
		$found_ids = array_map( function( $r ) { return (int) $r['id']; }, $rows ?: array() );
		$new_chunk_ids = array_diff( array_keys( $chunk_content ), $found_ids );
		if ( ! empty( $new_chunk_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $new_chunk_ids ), '%d' ) );
			$chunk_docs = $wpdb->get_results( $wpdb->prepare(
				"SELECT d.id, d.title, d.created_at, i.synopsis, i.key_facts, i.document_type
				 FROM {$wpdb->prefix}zkv_documents d
				 JOIN {$wpdb->prefix}zkv_index i ON i.document_id = d.id AND i.is_current = 1
				 WHERE d.id IN ({$placeholders}) AND d.status = 'indexed'{$vis}",
				...$new_chunk_ids
			), ARRAY_A );
			if ( ! empty( $chunk_docs ) ) {
				$rows = array_merge( $rows ?: array(), $chunk_docs );
			}
		}

		if ( empty( $rows ) ) {
			return rest_ensure_response( array( 'success' => true, 'data' => array( 'block' => '' ) ) );
		}

		$block = "\n═══ KNOWLEDGE VAULT (employee-uploaded reference docs) ═══\n\n";
		foreach ( $rows as $r ) {
			$secure_url = zkv_secure_url( (int) $r['id'] );
			$block .= "VAULT-{$r['id']}: {$r['title']}\n";
			$block .= "  {$r['synopsis']}\n";
			$facts = json_decode( $r['key_facts'] ?? '[]', true );
			if ( is_array( $facts ) && ! empty( $facts ) ) {
				$block .= "  Facts: " . implode( '; ', array_slice( $facts, 0, 5 ) ) . "\n";
			}
			// v1.3.0: Include matched content chunk excerpt if available.
			$did = (int) $r['id'];
			if ( ! empty( $chunk_content[ $did ] ) ) {
				$block .= "  Matched content: " . implode( ' [...] ', array_slice( $chunk_content[ $did ], 0, 2 ) ) . "\n";
			}
			$block .= "  Source: {$secure_url}\n\n";
		}

		return rest_ensure_response( array( 'success' => true, 'data' => array( 'block' => $block, 'count' => count( $rows ) ) ) );
	}

	public static function handle_preview( $request ) {
		global $wpdb;
		$id = (int) $request->get_param( 'id' );
		$vis = self::visibility_sql( 'd' );

		$doc = $wpdb->get_row( $wpdb->prepare(
			"SELECT d.id, d.title, d.mime_type, d.uploaded_by, d.created_at,
			        i.synopsis, i.document_type
			 FROM {$wpdb->prefix}zkv_documents d
			 LEFT JOIN {$wpdb->prefix}zkv_index i ON i.document_id = d.id AND i.is_current = 1
			 WHERE d.id = %d{$vis}", $id
		), ARRAY_A );

		if ( ! $doc ) {
			return new WP_REST_Response( array( 'success' => false ), 404 );
		}

		$u = get_userdata( (int) $doc['uploaded_by'] );

		return rest_ensure_response( array(
			'success' => true,
			'data' => array(
				'id'            => (int) $doc['id'],
				'title'         => $doc['title'],
				'synopsis'      => $doc['synopsis'] ?? '',
				'document_type' => $doc['document_type'] ?? 'general',
				'uploader_name' => $u ? $u->display_name : '',
				'uploaded_at'   => $doc['created_at'],
				'file_url'      => zkv_secure_url( (int) $doc['id'] ), // Authenticated proxy, NOT raw path
			),
		) );
	}

	/**
	 * GET {ZDZ_REST_NS}/vault/pricing — Returns pricing authority documents with indexed content.
	 *
	 * @since 1.2.8
	 * @return WP_REST_Response
	 */
	public static function handle_pricing( $request ) {
		global $wpdb;
		$vis = self::visibility_sql( 'd' );

		$rows = $wpdb->get_results(
			"SELECT d.id, d.title, d.slug, d.mime_type, d.file_size, d.user_context,
			        d.created_at, d.updated_at,
			        i.synopsis, i.key_facts, i.key_entities, i.document_type, i.tags
			 FROM {$wpdb->prefix}zkv_documents d
			 JOIN {$wpdb->prefix}zkv_index i ON i.document_id = d.id AND i.is_current = 1
			 WHERE d.is_pricing_authority = 1
			   AND d.status = 'indexed'{$vis}
			 ORDER BY d.updated_at DESC",
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return rest_ensure_response( array(
				'success' => true,
				'data'    => array( 'documents' => array(), 'count' => 0 ),
			) );
		}

		$documents = array();
		foreach ( $rows as $r ) {
			$documents[] = array(
				'id'            => (int) $r['id'],
				'title'         => $r['title'],
				'slug'          => $r['slug'],
				'document_type' => $r['document_type'],
				'synopsis'      => $r['synopsis'],
				'key_facts'     => json_decode( $r['key_facts'] ?? '[]', true ),
				'key_entities'  => json_decode( $r['key_entities'] ?? '{}', true ),
				'tags'          => $r['tags'],
				'user_context'  => $r['user_context'],
				'file_size'     => (int) $r['file_size'],
				'created_at'    => $r['created_at'],
				'updated_at'    => $r['updated_at'],
				'file_url'      => zkv_secure_url( (int) $r['id'], $r['slug'] ),
			);
		}

		return rest_ensure_response( array(
			'success' => true,
			'data'    => array(
				'documents' => $documents,
				'count'     => count( $documents ),
			),
		) );
	}

	/**
	 * GET {ZDZ_REST_NS}/vault/deep-search — Search actual document content chunks.
	 *
	 * Unlike /vault/search (which searches AI-generated summaries), this
	 * endpoint searches the raw extracted text stored in wp_zkv_chunks.
	 * Returns matched excerpts with document metadata.
	 *
	 * Use case: a specific product-and-size cost question
	 * → Finds the exact pricing table row in the supplier PDF content.
	 *
	 * @since 1.3.0
	 * @return WP_REST_Response
	 */
	public static function handle_deep_search( $request ) {
		global $wpdb;
		$q     = $request->get_param( 'q' );
		$limit = min( (int) ( $request->get_param( 'limit' ) ?: 10 ), 25 );
		$vis   = self::visibility_sql( 'd' );

		// Search content chunks via FULLTEXT.
		$chunks = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.document_id, c.chunk_index, c.chunk_text,
			        MATCH(c.search_text) AGAINST(%s IN NATURAL LANGUAGE MODE) as relevance
			 FROM {$wpdb->prefix}zkv_chunks c
			 JOIN {$wpdb->prefix}zkv_documents d ON d.id = c.document_id
			 WHERE MATCH(c.search_text) AGAINST(%s IN NATURAL LANGUAGE MODE){$vis}
			 ORDER BY relevance DESC LIMIT %d",
			$q, $q, $limit
		), ARRAY_A );

		if ( empty( $chunks ) ) {
			return rest_ensure_response( array(
				'success' => true,
				'data'    => array( 'results' => array(), 'count' => 0 ),
			) );
		}

		// Fetch document metadata for matched chunks.
		$doc_ids = array_unique( array_map( function( $c ) { return (int) $c['document_id']; }, $chunks ) );
		$placeholders = implode( ',', array_fill( 0, count( $doc_ids ), '%d' ) );
		$docs = $wpdb->get_results( $wpdb->prepare(
			"SELECT d.id, d.title, d.slug, d.mime_type, i.document_type, i.synopsis
			 FROM {$wpdb->prefix}zkv_documents d
			 JOIN {$wpdb->prefix}zkv_index i ON i.document_id = d.id AND i.is_current = 1
			 WHERE d.id IN ({$placeholders}) AND d.status = 'indexed'{$vis}",
			...$doc_ids
		), ARRAY_A );

		$doc_map = array();
		foreach ( $docs as $d ) {
			$doc_map[ (int) $d['id'] ] = $d;
		}

		// Build results.
		$results = array();
		foreach ( $chunks as $c ) {
			$did = (int) $c['document_id'];
			$doc = $doc_map[ $did ] ?? null;
			if ( ! $doc ) { continue; }

			$results[] = array(
				'document_id'   => $did,
				'title'         => $doc['title'],
				'document_type' => $doc['document_type'],
				'synopsis'      => $doc['synopsis'],
				'chunk_index'   => (int) $c['chunk_index'],
				'excerpt'       => mb_substr( trim( $c['chunk_text'] ), 0, 800, 'UTF-8' ),
				'relevance'     => (float) $c['relevance'],
				'file_url'      => zkv_secure_url( $did, $doc['slug'] ?? '' ),
			);
		}

		return rest_ensure_response( array(
			'success' => true,
			'data'    => array(
				'results' => $results,
				'count'   => count( $results ),
			),
		) );
	}
}
