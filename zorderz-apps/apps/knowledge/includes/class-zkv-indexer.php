<?php
/**
 * ZKV_Indexer — AI document indexing via Poe AI singleton.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ZKV_Indexer {

	public static function process_document( $document_id ) {
		global $wpdb;
		$doc = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}zkv_documents WHERE id = %d", (int) $document_id
		), ARRAY_A );

		if ( ! $doc ) {
			return array( 'success' => false, 'error' => 'Document not found.' );
		}

		// Update status.
		$wpdb->update( $wpdb->prefix . 'zkv_documents',
			array( 'status' => 'processing', 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => (int) $document_id ),
			array( '%s', '%s' ), array( '%d' )
		);

		// Extract text.
		$text = self::extract_text( $doc );
		$text = self::validate_text_quality( $text );
		$has_text = ! empty( trim( $text ) );

		// Route large PDFs to chunked processor (6B).
		$large_pdf_threshold = 5 * 1024 * 1024; // 5 MB
		if ( $has_text && strlen( $text ) > 12000 && (int) $doc['file_size'] > $large_pdf_threshold ) {
			return self::process_large_pdf( $document_id, $text, $doc );
		}

		// Get Poe API key.
		$api_key = '';
		if ( class_exists( 'ZKV_Dashboard' ) ) {
			$api_key = ZKV_Dashboard::get_poe_api_key();
		}
		if ( empty( $api_key ) ) {
			$wpdb->update( $wpdb->prefix . 'zkv_documents',
				array( 'status' => 'failed', 'processing_error' => 'Poe API key not configured.', 'updated_at' => current_time( 'mysql' ) ),
				array( 'id' => (int) $document_id ), array( '%s', '%s', '%s' ), array( '%d' )
			);
			// v1.4.0: email-sourced docs reply to the forwarder instead of failing silently.
			if ( class_exists( 'ZKV_Email_Ingest' ) ) { ZKV_Email_Ingest::after_failed( (int) $document_id, $doc, 'Poe API key not configured.' ); }
			return array( 'success' => false, 'error' => 'Poe API key not configured.' );
		}

		$model = class_exists( 'ZKV_Dashboard' ) ? ZKV_Dashboard::get_ai_model() : 'Gemini-3.1-Pro';

		// Resolve file path (handles both filesystem paths and URLs).
		$file_path = self::resolve_file_path( $doc );

		// ── VISION PATH: For PDFs with no extractable text, or image uploads ──
		$use_vision = false;
		$page_images = array();

		if ( ! $has_text && ! empty( $file_path ) && class_exists( 'ZKV_PDF_Reader' ) ) {
			if ( $doc['mime_type'] === 'application/pdf' ) {
				// Try extracting page images from the PDF (works for both
				// truly image-only PDFs and jsPDF-generated scans).
				error_log( 'ZKV indexer [' . $document_id . ']: No text in PDF. Trying image extraction...' );
				$page_images = ZKV_PDF_Reader::extract_page_images( $file_path, 3, 1000 );

				if ( ! empty( $page_images ) ) {
					$use_vision = true;
					error_log( 'ZKV indexer [' . $document_id . ']: Vision active — ' . count( $page_images ) . ' page images extracted.' );
				} else {
					error_log( 'ZKV indexer [' . $document_id . ']: Image extraction returned 0 images.' );
				}
			} elseif ( strpos( $doc['mime_type'], 'image/' ) === 0 ) {
				// Direct image upload (JPEG, PNG, etc.) — send to vision.
				$img_data = file_get_contents( $file_path );
				if ( ! empty( $img_data ) ) {
					// Resize if too large (>2MB).
					$gd = @imagecreatefromstring( $img_data );
					if ( $gd ) {
						$w = imagesx( $gd );
						if ( $w > 1200 ) {
							$ratio = 1200 / $w;
							$new_h = (int) ( imagesy( $gd ) * $ratio );
							$resized = imagecreatetruecolor( 1200, $new_h );
							imagecopyresampled( $resized, $gd, 0, 0, 0, 0, 1200, $new_h, $w, imagesy( $gd ) );
							imagedestroy( $gd );
							$gd = $resized;
						}
						ob_start();
						imagejpeg( $gd, null, 70 );
						$jpeg = ob_get_clean();
						imagedestroy( $gd );
						$page_images[] = base64_encode( $jpeg );
						$use_vision = true;
						error_log( 'ZKV indexer [' . $document_id . ']: Image file — sending to vision.' );
					}
				}
			}
		} elseif ( ! $has_text ) {
			error_log( 'ZKV indexer [' . $document_id . ']: No text, no file path. Falling back to metadata.' );
		}

		if ( $use_vision ) {
			// Send page images to AI for visual reading.
			$prompt_text = self::build_prompt( $doc, 'DOCUMENT PAGES ARE PROVIDED AS IMAGES BELOW. Read ALL visible text from the page images and analyze the document thoroughly.' );
			$content_parts = array();
			foreach ( $page_images as $b64 ) {
				$content_parts[] = array(
					'type'      => 'image_url',
					'image_url' => array( 'url' => 'data:image/jpeg;base64,' . $b64 ),
				);
			}
			$content_parts[] = array( 'type' => 'text', 'text' => $prompt_text );
			$response = ZKV_Dashboard::poe_query( $api_key, $content_parts, $model );
		} else {
			// Text-based analysis (or metadata fallback).
			if ( ! $has_text ) {
				$text = 'FILENAME: ' . $doc['original_name'] . "\n"
				      . 'MIME TYPE: ' . $doc['mime_type'] . "\n"
				      . 'FILE SIZE: ' . size_format( (int) $doc['file_size'] ) . "\n"
				      . "\nNo text could be extracted. Write a confident synopsis based on filename and metadata. Do NOT mention extraction issues.";
			}
			$prompt = self::build_prompt( $doc, $text );
			$response = ZKV_Dashboard::poe_query( $api_key, $prompt, $model );
		}

		if ( strpos( $response, 'Error:' ) === 0 ) {
			$wpdb->update( $wpdb->prefix . 'zkv_documents',
				array( 'status' => 'failed', 'processing_error' => $response, 'updated_at' => current_time( 'mysql' ) ),
				array( 'id' => (int) $document_id ), array( '%s', '%s', '%s' ), array( '%d' )
			);
			if ( class_exists( 'ZKV_Email_Ingest' ) ) { ZKV_Email_Ingest::after_failed( (int) $document_id, $doc, $response ); }
			return array( 'success' => false, 'error' => $response );
		}

		// Parse JSON.
		$parsed = ZKV_Dashboard::parse_llm_json( $response );
		if ( ! $parsed || ! is_array( $parsed ) ) {
			$wpdb->update( $wpdb->prefix . 'zkv_documents',
				array( 'status' => 'failed', 'processing_error' => 'AI returned invalid JSON.', 'updated_at' => current_time( 'mysql' ) ),
				array( 'id' => (int) $document_id ), array( '%s', '%s', '%s' ), array( '%d' )
			);
			if ( class_exists( 'ZKV_Email_Ingest' ) ) { ZKV_Email_Ingest::after_failed( (int) $document_id, $doc, 'AI returned invalid JSON.' ); }
			return array( 'success' => false, 'error' => 'AI returned invalid JSON.' );
		}

		// Store index.
		$synopsis     = sanitize_textarea_field( $parsed['synopsis'] ?? '' );
		$key_entities = wp_json_encode( $parsed['key_entities'] ?? new \stdClass() );
		$key_facts    = wp_json_encode( $parsed['key_facts'] ?? array() );
		$tags         = sanitize_text_field( implode( ', ', (array) ( $parsed['tags'] ?? array() ) ) );
		$doc_type     = self::valid_type( $parsed['document_type'] ?? 'general' );
		$ai_title     = sanitize_text_field( $parsed['title'] ?? '' );

		// Build FULLTEXT search composite.
		$facts_flat    = implode( '. ', (array) ( $parsed['key_facts'] ?? array() ) );
		$entities_flat = '';
		if ( is_array( $parsed['key_entities'] ?? null ) ) {
			$parts = array();
			foreach ( $parsed['key_entities'] as $group ) {
				if ( is_array( $group ) ) { $parts = array_merge( $parts, $group ); }
			}
			$entities_flat = implode( ', ', $parts );
		}
		$search_text = implode( ' ', array_filter( array(
			$ai_title ?: $doc['title'], $synopsis, $entities_flat, $facts_flat, $tags,
			$doc['description'] ?? '', $doc['user_context'] ?? '',
		) ) );

		// Mark old index rows as not-current.
		$wpdb->update( $wpdb->prefix . 'zkv_index',
			array( 'is_current' => 0 ),
			array( 'document_id' => (int) $document_id ),
			array( '%d' ), array( '%d' )
		);

		$wpdb->insert( $wpdb->prefix . 'zkv_index', array(
			'document_id'   => (int) $document_id,
			'version'       => (int) ( $doc['version'] ?? 1 ),
			'is_current'    => 1,
			'summary_json'  => wp_json_encode( $parsed ),
			'synopsis'      => $synopsis,
			'key_entities'  => $key_entities,
			'key_facts'     => $key_facts,
			'document_type' => $doc_type,
			'tags'          => $tags,
			'search_text'   => $search_text,
			'tokens_used'   => strlen( $response ),
			'model_used'    => 'default',
			'created_at'    => current_time( 'mysql' ),
		), array( '%d','%d','%d','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s' ) );

		// Update document status + AI title.
		$update = array(
			'status'     => 'indexed',
			'indexed_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		);
		if ( ! empty( $ai_title ) ) { $update['title'] = $ai_title; }
		$wpdb->update( $wpdb->prefix . 'zkv_documents', $update,
			array( 'id' => (int) $document_id ),
			array_fill( 0, count( $update ), '%s' ), array( '%d' )
		);

		// Set AI-determined category + generate pretty URL slug.
		self::apply_category_and_slug( $document_id, $parsed, $ai_title ?: $doc['title'] );

		// v1.3.0: Store extracted text in searchable content chunks.
		// This enables FULLTEXT search against the ACTUAL document content
		// (not just the AI-generated summary), so specific details like
		// pricing, dimensions, and part numbers are findable.
		if ( $has_text && strlen( trim( $text ) ) > 50 ) {
			self::store_content_chunks( $document_id, $text, $doc );
		}

		// v1.5.0: Private-transcript pipeline.
		//   Opt-in docs (visibility already 'transcript_private' — set at
		//   insert by the uploader's assertion or the [transcript] mail tag):
		//   build the line rendition; run exact-match party resolution ONCE
		//   (status 'detected' only — re-indexing an active/latent transcript
		//   refreshes the rendition but never re-runs auto-resolution, so an
		//   admin's party corrections are never silently undone).
		//   Normal docs: AI's document_type + a structural check may only
		//   SUGGEST (admin queue); visibility is never changed here (D4).
		if ( class_exists( 'ZKV_Transcript' ) && class_exists( 'ZKV_ACL' ) ) {
			try {
				if ( ZKV_ACL::is_transcript_visibility( $doc['visibility'] ) ) {
					if ( 'detected' === (string) ( $doc['transcript_status'] ?? '' ) ) {
						// v1.5.2 (KV2): stage for the uploader's confirmation instead of
						// auto-granting the detected parties — nothing is shared until the
						// uploader confirms who may see it.
						$tr = ZKV_Transcript::stage_pending_confirmation( (int) $document_id, (int) $doc['uploaded_by'] );
						error_log( 'ZKV transcript [' . $document_id . ']: staged → '
							. ( $tr['status'] ?? '?' ) . ' (' . ( ! empty( $tr['detected'] ) ? count( $tr['detected'] ) : 0 ) . ' detected, '
							. ( $tr['lines'] ?? 0 ) . ' lines'
							. ( ! empty( $tr['unmatched'] ) ? ', unmatched: ' . implode( ', ', $tr['unmatched'] ) : '' ) . ').' );
					} else {
						ZKV_Transcript::store_lines( (int) $document_id, ZKV_Transcript::build_lines( $doc ) );
					}
				} elseif ( 'transcript' === $doc_type
					|| ( $has_text && ZKV_Transcript::looks_like_transcript( $text ) ) ) {
					ZKV_Transcript::suggest( (int) $document_id,
						'transcript' === $doc_type ? 'ai_document_type' : 'structural', 0 );
				}
			} catch ( \Throwable $e ) {
				error_log( 'ZKV transcript [' . $document_id . ']: pipeline error (non-fatal): ' . $e->getMessage() );
			}
		}

		// v1.2.6: Invalidate TSA bridge inventory cache so new docs appear immediately.
		if ( class_exists( 'ZKV_TSA_Bridge' ) && method_exists( 'ZKV_TSA_Bridge', 'invalidate_cache' ) ) {
			ZKV_TSA_Bridge::invalidate_cache();
		}

		// v1.4.0: email-sourced docs — deterministic 'Email Correspondence' tag
		// + original-sender search terms + forwarder confirmation email.
		if ( class_exists( 'ZKV_Email_Ingest' ) ) {
			ZKV_Email_Ingest::after_indexed( (int) $document_id, $doc );
		}

		return array( 'success' => true, 'synopsis' => $synopsis );
	}

	// ──────────────────────────────────────────────────────────────

	/**
	 * Quick text extraction for pre-analysis. Public static so the
	 * dashboard's preanalyze handler can call it before a document
	 * record exists in the DB.
	 *
	 * @param string $file_path Filesystem path.
	 * @param string $mime      MIME type.
	 * @param int    $max_chars Max chars to return (keep small for speed).
	 * @return string Extracted text preview.
	 */
	public static function quick_extract( $file_path, $mime, $max_chars = 4000 ) {
		if ( ! file_exists( $file_path ) ) { return ''; }

		$text = '';
		$ext  = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

		// Transcript/caption formats — parse and strip timestamps.
		$transcript_exts = array( 'srt', 'vtt', 'itt', 'sbv', 'ass', 'ssa', 'sub', 'lrc' );
		if ( in_array( $ext, $transcript_exts, true )
		     || in_array( $mime, array( 'text/vtt', 'application/x-subrip' ), true ) ) {
			$raw = file_get_contents( $file_path );
			$text = self::parse_transcript( $raw, $ext );
		} elseif ( in_array( $mime, array( 'text/plain', 'text/markdown', 'text/csv' ), true ) ) {
			$text = file_get_contents( $file_path );
		} elseif ( $mime === 'application/json' ) {
			// JSON transcripts (e.g., from Otter.ai, Rev, Descript).
			$raw = file_get_contents( $file_path );
			$text = self::parse_json_transcript( $raw );
		} elseif ( $mime === 'application/pdf' ) {
			// Try exec(pdftotext) — only works on hosts that allow exec().
			$exec_ok = function_exists( 'exec' ) && ! in_array( 'exec', array_map( 'trim', explode( ',', ini_get( 'disable_functions' ) ) ), true );
			if ( $exec_ok ) {
				$out = array(); $ret = -1;
				@exec( 'pdftotext ' . escapeshellarg( $file_path ) . ' - 2>/dev/null', $out, $ret );
				if ( $ret === 0 && ! empty( $out ) ) { $text = implode( "\n", $out ); }
			}
			// If no text extracted, return empty — AI will use filename instead.
			// PHP PDF reader disabled: produces binary garbage on embedded-font PDFs
			// that confuses AI worse than having no text at all.
		} elseif ( strpos( $mime, 'wordprocessingml' ) !== false || $mime === 'application/msword' ) {
			if ( class_exists( 'ZipArchive' ) ) {
				$zip = new ZipArchive();
				if ( $zip->open( $file_path ) === true ) {
					$xml = $zip->getFromName( 'word/document.xml' );
					$zip->close();
					if ( $xml ) { $text = strip_tags( $xml ); }
				}
			}
		} elseif ( strpos( $mime, 'spreadsheetml' ) !== false || strpos( $mime, 'ms-excel' ) !== false ) {
			if ( class_exists( 'ZipArchive' ) ) {
				$zip = new ZipArchive();
				if ( $zip->open( $file_path ) === true ) {
					$xml = $zip->getFromName( 'xl/sharedStrings.xml' );
					$zip->close();
					if ( $xml ) { $text = strip_tags( $xml ); }
				}
			}
		} elseif ( strpos( $mime, 'image/' ) === 0 ) {
			$text = '[IMAGE: ' . basename( $file_path ) . '] — analyze based on filename.';
		}

		$text = trim( preg_replace( '/\s+/', ' ', $text ) );
		if ( strlen( $text ) > $max_chars ) {
			$text = substr( $text, 0, $max_chars );
		}
		return $text;
	}

	private static function extract_text( $doc ) {
		$path = self::resolve_file_path( $doc );
		if ( empty( $path ) ) { return ''; }

		$mime = $doc['mime_type'];
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		// Transcript/caption formats — parse and strip timestamps.
		$transcript_exts = array( 'srt', 'vtt', 'itt', 'sbv', 'ass', 'ssa', 'sub', 'lrc' );
		if ( in_array( $ext, $transcript_exts, true )
		     || in_array( $mime, array( 'text/vtt', 'application/x-subrip' ), true ) ) {
			$raw = file_get_contents( $path );
			return self::truncate( self::parse_transcript( $raw, $ext ) );
		}

		// JSON transcripts (Otter.ai, Rev, Descript, etc.).
		if ( $ext === 'json' || $mime === 'application/json' ) {
			$raw = file_get_contents( $path );
			return self::truncate( self::parse_json_transcript( $raw ) );
		}

		// Plain text.
		if ( in_array( $mime, array( 'text/plain', 'text/markdown', 'text/csv' ), true ) ) {
			return self::truncate( file_get_contents( $path ) );
		}

		// PDF — try exec(pdftotext) only.
		if ( $mime === 'application/pdf' ) {
			$exec_ok = function_exists( 'exec' ) && ! in_array( 'exec', array_map( 'trim', explode( ',', ini_get( 'disable_functions' ) ) ), true );
			if ( $exec_ok ) {
				$out = array(); $ret = -1;
				@exec( 'pdftotext ' . escapeshellarg( $path ) . ' - 2>/dev/null', $out, $ret );
				if ( $ret === 0 && ! empty( $out ) ) {
					return self::truncate( implode( "\n", $out ) );
				}
			}
			// Return empty — AI will use filename + metadata for analysis.
			return '';
		}

		// DOCX — extract from ZIP.
		if ( strpos( $mime, 'wordprocessingml' ) !== false || $mime === 'application/msword' ) {
			if ( class_exists( 'ZipArchive' ) ) {
				$zip = new ZipArchive();
				if ( $zip->open( $path ) === true ) {
					$xml = $zip->getFromName( 'word/document.xml' );
					$zip->close();
					if ( $xml ) { return self::truncate( strip_tags( $xml ) ); }
				}
			}
			return '[DOCX: ' . basename( $path ) . ']';
		}

		// XLSX — shared strings.
		if ( strpos( $mime, 'spreadsheetml' ) !== false || strpos( $mime, 'ms-excel' ) !== false ) {
			if ( class_exists( 'ZipArchive' ) ) {
				$zip = new ZipArchive();
				if ( $zip->open( $path ) === true ) {
					$xml = $zip->getFromName( 'xl/sharedStrings.xml' );
					$zip->close();
					if ( $xml ) { return self::truncate( strip_tags( $xml ) ); }
				}
			}
			return '[Spreadsheet: ' . basename( $path ) . ']';
		}

		// Images.
		if ( strpos( $mime, 'image/' ) === 0 ) {
			return '[IMAGE: ' . basename( $path ) . '] Describe visible content and extract any text.';
		}

		return '[File: ' . basename( $path ) . ']';
	}

	private static function build_prompt( $doc, $text ) {
		// Build category context — show AI what's in each category so it can reason.
		$cat_context = self::build_category_context();

		// Runtime identity — from the Business Profile, never a typed company/place.
		$biz = function_exists( 'zkv_business_descriptor' ) ? zkv_business_descriptor() : 'the business';

		// Detect if text extraction was limited.
		$text_len = strlen( trim( $text ) );
		$extraction_note = '';
		if ( $text_len < 100 || strpos( $text, 'FILENAME:' ) === 0 ) {
			// HONESTY (do not confabulate): the text could not be extracted. Write a
			// synopsis from the filename/metadata ONLY, note that full text was not
			// available, and NEVER invent facts about what the document "typically"
			// contains. An empty key_facts list is correct when nothing is known.
			$extraction_note = '

NOTE: The document text could not be extracted (often embedded-font encoding in a PDF). Base the title and a brief synopsis on the filename and metadata alone, and state in the synopsis that the full text was not available. Do NOT invent facts, prices, names or figures that are not present — return an empty key_facts list rather than guessing.';
		} elseif ( $text_len < 500 ) {
			$extraction_note = '

NOTE: Only partial text was extracted. Use the filename and metadata to supplement your analysis, and do not invent facts beyond what the text supports.';
		}

		return 'You are a document indexer for ' . $biz . '. Analyze this document and produce a JSON object.

TITLE RULES:
- Aim for 5 words or fewer. Shorter is better.
- Keep specific, useful info: names, numbers, product types, dates.
- Normalize into a clean professional format (no ALL CAPS, no file extensions, no underscores).
- The filename may contain useful context — incorporate it if relevant.
- Prefer a short human title over a raw filename: e.g. "Q1 Pricing Sheet" not "Supplier_Q1_2026_Pricing_Quote.pdf".
- If the document is very specific, keep the specificity (e.g. a named receipt or report is fine).

CATEGORY RULES:
- You MUST choose exactly ONE category from the list below.
- Review what existing documents are already in each category.
- Place this document where it best fits alongside similar documents.
- If genuinely unsure, use "general".

AVAILABLE CATEGORIES AND THEIR CURRENT CONTENTS:
' . $cat_context . '

Produce this JSON:
{
  "title": "Short Descriptive Title",
  "category_slug": "the-slug-from-above",
  "synopsis": "2-3 sentence summary of what this document covers and its purpose",
  "document_type": "one of: invoice | policy | technical | correspondence | report | transcript | photo_note | general",
  "key_entities": {
    "people": ["names"], "companies": ["names"], "products": ["names"],
    "locations": ["places"], "amounts": ["$ amounts with context"]
  },
  "key_facts": ["Specific queryable fact 1", "Fact 2"],
  "tags": ["keyword1", "keyword2"]
}

RULES: Extract SPECIFIC facts (a concrete price, spec, part number, date or name that appears in the text) — not vague summaries like "discusses pricing". Up to 15 key_facts. Respond ONLY with JSON.' . $extraction_note . '

File: ' . $doc['original_name'] . ' (' . $doc['mime_type'] . ', ' . size_format( (int) $doc['file_size'] ) . ')' .
( ! empty( $doc['user_context'] ) ? '
User Context (store verbatim in key_facts, include in search tags — this is how the uploader identified this document):
' . $doc['user_context'] : '' ) . '
Content:
---
' . $text . '
---';
	}

	/**
	 * Build a context block showing all categories and their existing documents.
	 * This gives the AI the information it needs to make a reasoned category choice.
	 */
	private static function build_category_context() {
		global $wpdb;
		$cats = $wpdb->get_results(
			"SELECT id, slug, label, description FROM {$wpdb->prefix}zkv_categories ORDER BY sort_order ASC",
			ARRAY_A
		);
		if ( empty( $cats ) ) {
			return '(No categories defined — use "general")';
		}

		$lines = array();
		foreach ( $cats as $cat ) {
			$cat_id = (int) $cat['id'];
			// Fetch up to 8 document titles in this category for context.
			$docs = $wpdb->get_col( $wpdb->prepare(
				"SELECT title FROM {$wpdb->prefix}zkv_documents WHERE category_id = %d AND status = 'indexed' ORDER BY created_at DESC LIMIT 8",
				$cat_id
			) );
			$line = '- "' . $cat['slug'] . '" (' . $cat['label'] . '): ' . $cat['description'];
			if ( ! empty( $docs ) ) {
				$line .= "\n  Contains: " . implode( ', ', array_map( function( $t ) { return '"' . $t . '"'; }, $docs ) );
			} else {
				$line .= "\n  (empty — no documents yet)";
			}
			$lines[] = $line;
		}
		return implode( "\n", $lines );
	}

	/**
	 * After AI processing, set the category and slug on the document.
	 */
	private static function apply_category_and_slug( $document_id, $parsed, $title ) {
		global $wpdb;

		// Set category from AI's choice.
		$cat_slug = sanitize_title( $parsed['category_slug'] ?? 'general' );
		$cat = null;
		if ( class_exists( 'ZKV_Categories' ) ) {
			$cat = ZKV_Categories::get_by_slug( $cat_slug );
		}
		$category_id = $cat ? (int) $cat['id'] : null;

		// Generate pretty URL slug from the AI title.
		$slug = zkv_generate_slug( $title, $document_id );

		$wpdb->update(
			$wpdb->prefix . 'zkv_documents',
			array(
				'category_id' => $category_id,
				'slug'        => $slug,
			),
			array( 'id' => (int) $document_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Resolve a document's file to a filesystem path.
	 * Handles both direct filesystem paths and URL-based file_url values.
	 */
	private static function resolve_file_path( $doc ) {
		// Try file_url as filesystem path first.
		$path = $doc['file_url'] ?? '';
		if ( ! empty( $path ) && file_exists( $path ) ) {
			return $path;
		}

		// Convert URL to filesystem path.
		if ( ! empty( $path ) && strpos( $path, 'http' ) === 0 ) {
			$upload_dir = wp_upload_dir();
			$fs_path = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $path );
			if ( file_exists( $fs_path ) ) {
				return $fs_path;
			}
			// Try without scheme difference (http vs https).
			$fs_path2 = str_replace(
				str_replace( 'https://', 'http://', $upload_dir['baseurl'] ),
				$upload_dir['basedir'],
				str_replace( 'https://', 'http://', $path )
			);
			if ( file_exists( $fs_path2 ) ) {
				return $fs_path2;
			}
		}

		// Try via attachment_id.
		if ( ! empty( $doc['attachment_id'] ) ) {
			$att_path = get_attached_file( (int) $doc['attachment_id'] );
			if ( $att_path && file_exists( $att_path ) ) {
				return $att_path;
			}
		}

		return '';
	}

	/**
	 * Public wrapper for resolve_file_path().
	 * Used by the v1.3.0 chunk backfill cron task and other callers
	 * that need to locate a document's file on the filesystem.
	 *
	 * @since 1.3.0
	 * @param array $doc Document row from wp_zkv_documents.
	 * @return string Filesystem path, or empty string.
	 */
	public static function resolve_file_path_public( $doc ) {
		return self::resolve_file_path( $doc );
	}

	/**
	 * Public, newline-preserving full extraction (v1.5.0). The transcript
	 * line rendition (ZKV_Transcript) needs the same text the chunk store
	 * sees, WITH line structure intact — quick_extract() collapses whitespace.
	 *
	 * @since 1.5.0
	 * @param array $doc Document row from wp_zkv_documents.
	 * @return string Full extracted text (may be empty).
	 */
	public static function extract_full_text_public( $doc ) {
		return self::extract_full_text( $doc );
	}

	private static function truncate( $text ) {
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );
		if ( strlen( $text ) <= 12000 ) { return $text; }
		return substr( $text, 0, 9000 ) . "\n\n[...truncated...]\n\n" . substr( $text, -2500 );
	}

	private static function valid_type( $t ) {
		$ok = array( 'invoice','policy','technical','correspondence','report','transcript','photo_note','general' );
		return in_array( strtolower( trim( $t ) ), $ok, true ) ? strtolower( trim( $t ) ) : 'general';
	}

	/**
	 * Check if extracted text is actually readable, not binary garbage.
	 * Returns the text if it looks like real words, or empty string if it's junk.
	 */
	private static function validate_text_quality( $text ) {
		$text = trim( $text );
		if ( empty( $text ) ) { return ''; }

		// If text starts with known "no text" markers, keep as-is.
		if ( strpos( $text, '[PDF:' ) === 0 || strpos( $text, '[File:' ) === 0 || strpos( $text, 'FILENAME:' ) === 0 ) {
			return $text;
		}

		// Count readable ASCII characters vs total.
		$total    = strlen( $text );
		$readable = preg_match_all( '/[a-zA-Z0-9\s.,;:!?\'\"()\-\/]/', $text );

		// If less than 40% readable characters, it's mostly binary junk.
		if ( $total > 50 && $readable / $total < 0.4 ) {
			return '';
		}

		// Count actual English words (3+ letter sequences).
		$word_count = preg_match_all( '/[a-zA-Z]{3,}/', $text );

		// If very few real words relative to text length, it's encoded garbage.
		if ( $total > 200 && $word_count < 10 ) {
			return '';
		}

		return $text;
	}

	/**
	 * Re-index a document — wipes old index and re-processes.
	 * Callable from AJAX or admin tools.
	 */
	public static function reindex( $document_id ) {
		global $wpdb;

		// Reset status so it gets re-processed.
		$wpdb->update(
			$wpdb->prefix . 'zkv_documents',
			array( 'status' => 'pending', 'processing_error' => null, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => (int) $document_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		// Delete old index entries.
		$wpdb->delete( $wpdb->prefix . 'zkv_index', array( 'document_id' => (int) $document_id ), array( '%d' ) );

		// Process immediately.
		return self::process_document( $document_id );
	}

	/**
	 * Re-index ALL documents — for when the extraction engine improves.
	 */
	public static function reindex_all() {
		global $wpdb;
		$ids = $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}zkv_documents ORDER BY id ASC" );
		$results = array();
		foreach ( $ids as $id ) {
			$results[ $id ] = self::reindex( (int) $id );
		}
		return $results;
	}

	// ──────────────────────────────────────────────────────────────
	//  Large PDF Processing (6B Pricing Oracle)
	// ──────────────────────────────────────────────────────────────

	/**
	 * Process a large PDF by splitting into chunks and combining results.
	 *
	 * Called when a PDF's extracted text exceeds the normal indexing limit.
	 * Splits the text into overlapping chunks, sends each to AI for analysis,
	 * then merges the structured results into a single index record.
	 *
	 * @since 1.2.8
	 * @param int    $document_id The document ID.
	 * @param string $text        Full extracted text from the PDF.
	 * @param array  $doc         Document row from wp_zkv_documents.
	 * @return array Result array with 'success' key.
	 */
	public static function process_large_pdf( $document_id, $text, $doc ) {
		global $wpdb;

		// Get API key and model.
		$api_key = '';
		if ( class_exists( 'ZKV_Dashboard' ) ) {
			$api_key = ZKV_Dashboard::get_poe_api_key();
		}
		if ( empty( $api_key ) ) {
			return array( 'success' => false, 'error' => 'Poe API key not configured.' );
		}

		$model = class_exists( 'ZKV_Dashboard' ) ? ZKV_Dashboard::get_ai_model() : 'Gemini-3.1-Pro';

		// Split into chunks.
		$chunks = self::split_into_chunks( $text, 8000, 500 );

		if ( empty( $chunks ) ) {
			return array( 'success' => false, 'error' => 'Failed to split document into chunks.' );
		}

		error_log( 'ZKV indexer [' . $document_id . ']: Large PDF — processing ' . count( $chunks ) . ' chunks.' );

		// Process each chunk.
		$all_facts    = array();
		$all_entities = array( 'people' => array(), 'companies' => array(), 'products' => array(), 'locations' => array(), 'amounts' => array() );
		$synopses     = array();
		$all_tags     = array();
		$doc_type     = 'general';
		$ai_title     = '';

		$biz = function_exists( 'zkv_business_descriptor' ) ? zkv_business_descriptor() : 'the business';
		$chunk_prompt_base = 'You are a document indexer for ' . $biz . '. '
			. 'This is chunk %d of %d from a large PDF document. Analyze this section and produce a JSON object.

Produce this JSON:
{
  "title": "Short Descriptive Title (only if this chunk has enough context)",
  "synopsis": "Brief summary of THIS chunk content",
  "document_type": "one of: invoice | policy | technical | correspondence | report | transcript | photo_note | general",
  "key_entities": {
    "people": ["names"], "companies": ["names"], "products": ["names"],
    "locations": ["places"], "amounts": ["$ amounts with context"]
  },
  "key_facts": ["Specific fact 1", "Fact 2"],
  "tags": ["keyword1", "keyword2"]
}

RULES: Extract SPECIFIC facts (a concrete price, spec, part number, date or name that appears in this chunk) — not vague summaries like "discusses pricing". Respond ONLY with JSON.

File: ' . $doc['original_name'] . ' (' . $doc['mime_type'] . ', ' . size_format( (int) $doc['file_size'] ) . ')
Content (chunk %d of %d):
---
%s
---';

		$total_chunks = count( $chunks );
		foreach ( $chunks as $idx => $chunk ) {
			$chunk_num = $idx + 1;
			$prompt = sprintf( $chunk_prompt_base, $chunk_num, $total_chunks, $chunk_num, $total_chunks, $chunk );

			$response = ZKV_Dashboard::poe_query( $api_key, $prompt, $model );

			if ( strpos( $response, 'Error:' ) === 0 ) {
				error_log( 'ZKV indexer [' . $document_id . ']: Chunk ' . $chunk_num . ' failed: ' . $response );
				continue; // Skip failed chunks, use what we have.
			}

			$parsed = ZKV_Dashboard::parse_llm_json( $response );
			if ( ! $parsed || ! is_array( $parsed ) ) {
				error_log( 'ZKV indexer [' . $document_id . ']: Chunk ' . $chunk_num . ' returned invalid JSON.' );
				continue;
			}

			// Merge results.
			if ( ! empty( $parsed['synopsis'] ) ) {
				$synopses[] = $parsed['synopsis'];
			}
			if ( ! empty( $parsed['key_facts'] ) && is_array( $parsed['key_facts'] ) ) {
				$all_facts = array_merge( $all_facts, $parsed['key_facts'] );
			}
			if ( ! empty( $parsed['tags'] ) && is_array( $parsed['tags'] ) ) {
				$all_tags = array_merge( $all_tags, $parsed['tags'] );
			}
			if ( ! empty( $parsed['key_entities'] ) && is_array( $parsed['key_entities'] ) ) {
				foreach ( $all_entities as $group => &$values ) {
					if ( ! empty( $parsed['key_entities'][ $group ] ) && is_array( $parsed['key_entities'][ $group ] ) ) {
						$values = array_merge( $values, $parsed['key_entities'][ $group ] );
					}
				}
				unset( $values );
			}
			// Use first chunk's title and doc_type if available.
			if ( empty( $ai_title ) && ! empty( $parsed['title'] ) ) {
				$ai_title = $parsed['title'];
			}
			if ( $doc_type === 'general' && ! empty( $parsed['document_type'] ) ) {
				$doc_type = self::valid_type( $parsed['document_type'] );
			}
		}

		// If no chunks succeeded, fail.
		if ( empty( $synopses ) && empty( $all_facts ) ) {
			$wpdb->update( $wpdb->prefix . 'zkv_documents',
				array( 'status' => 'failed', 'processing_error' => 'All chunks failed processing.', 'updated_at' => current_time( 'mysql' ) ),
				array( 'id' => (int) $document_id ), array( '%s', '%s', '%s' ), array( '%d' )
			);
			if ( class_exists( 'ZKV_Email_Ingest' ) ) { ZKV_Email_Ingest::after_failed( (int) $document_id, $doc, 'All chunks failed processing.' ); }
			return array( 'success' => false, 'error' => 'All chunks failed processing.' );
		}

		// Deduplicate.
		$all_facts = array_unique( $all_facts );
		$all_tags  = array_unique( $all_tags );
		foreach ( $all_entities as &$values ) {
			$values = array_values( array_unique( $values ) );
		}
		unset( $values );

		// Combine synopses into one.
		$synopsis = implode( ' ', array_slice( $synopses, 0, 3 ) );
		if ( mb_strlen( $synopsis, 'UTF-8' ) > 500 ) {
			$synopsis = mb_substr( $synopsis, 0, 497, 'UTF-8' ) . '...';
		}

		// Limit facts to 20.
		$all_facts = array_slice( $all_facts, 0, 20 );

		// Build combined parsed result.
		$combined = array(
			'title'         => $ai_title ?: $doc['title'],
			'synopsis'      => $synopsis,
			'document_type' => $doc_type,
			'key_entities'  => $all_entities,
			'key_facts'     => $all_facts,
			'tags'          => array_slice( $all_tags, 0, 15 ),
			'category_slug' => 'general',
		);

		// Store index record — same logic as process_document().
		$key_entities_json = wp_json_encode( $combined['key_entities'] );
		$key_facts_json    = wp_json_encode( $combined['key_facts'] );
		$tags_str          = sanitize_text_field( implode( ', ', $combined['tags'] ) );

		// Build FULLTEXT search composite.
		$facts_flat    = implode( '. ', $combined['key_facts'] );
		$entities_flat = '';
		foreach ( $combined['key_entities'] as $group ) {
			if ( is_array( $group ) ) {
				$entities_flat .= implode( ', ', $group ) . ' ';
			}
		}
		$search_text = implode( ' ', array_filter( array(
			$combined['title'], $synopsis, trim( $entities_flat ), $facts_flat, $tags_str,
			$doc['description'] ?? '', $doc['user_context'] ?? '',
		) ) );

		// Mark old index rows as not-current.
		$wpdb->update( $wpdb->prefix . 'zkv_index',
			array( 'is_current' => 0 ),
			array( 'document_id' => (int) $document_id ),
			array( '%d' ), array( '%d' )
		);

		$wpdb->insert( $wpdb->prefix . 'zkv_index', array(
			'document_id'   => (int) $document_id,
			'version'       => (int) ( $doc['version'] ?? 1 ),
			'is_current'    => 1,
			'summary_json'  => wp_json_encode( $combined ),
			'synopsis'      => $synopsis,
			'key_entities'  => $key_entities_json,
			'key_facts'     => $key_facts_json,
			'document_type' => $doc_type,
			'tags'          => $tags_str,
			'search_text'   => $search_text,
			'tokens_used'   => mb_strlen( $search_text, 'UTF-8' ),
			'model_used'    => $model,
			'created_at'    => current_time( 'mysql' ),
		), array( '%d','%d','%d','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s' ) );

		// Update document status.
		$update = array(
			'status'     => 'indexed',
			'indexed_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		);
		if ( ! empty( $ai_title ) ) { $update['title'] = sanitize_text_field( $ai_title ); }
		$wpdb->update( $wpdb->prefix . 'zkv_documents', $update,
			array( 'id' => (int) $document_id ),
			array_fill( 0, count( $update ), '%s' ), array( '%d' )
		);

		// Apply category + slug.
		self::apply_category_and_slug( $document_id, $combined, $ai_title ?: $doc['title'] );

		// v1.3.0: Store the full extracted text in searchable content chunks.
		// Large PDFs especially benefit — their full content becomes FULLTEXT searchable.
		if ( ! empty( $text ) && strlen( trim( $text ) ) > 50 ) {
			self::store_content_chunks( $document_id, $text, $doc );
		}

		// v1.5.0: Private-transcript pipeline — same rules as process_document()
		// (large-PDF transcripts are rare but must not bypass the pipeline).
		if ( class_exists( 'ZKV_Transcript' ) && class_exists( 'ZKV_ACL' ) ) {
			try {
				if ( ZKV_ACL::is_transcript_visibility( $doc['visibility'] ) ) {
					if ( 'detected' === (string) ( $doc['transcript_status'] ?? '' ) ) {
						ZKV_Transcript::stage_pending_confirmation( (int) $document_id, (int) $doc['uploaded_by'] ); // v1.5.2 (KV2): stage, don't auto-grant
					} else {
						ZKV_Transcript::store_lines( (int) $document_id, ZKV_Transcript::build_lines( $doc ) );
					}
				} elseif ( 'transcript' === $doc_type
					|| ZKV_Transcript::looks_like_transcript( $text ) ) {
					ZKV_Transcript::suggest( (int) $document_id,
						'transcript' === $doc_type ? 'ai_document_type' : 'structural', 0 );
				}
			} catch ( \Throwable $e ) {
				error_log( 'ZKV transcript [' . $document_id . ']: pipeline error (non-fatal): ' . $e->getMessage() );
			}
		}

		// Invalidate caches.
		if ( class_exists( 'ZKV_TSA_Bridge' ) ) { ZKV_TSA_Bridge::invalidate_cache(); }
		if ( class_exists( 'ZKV_Bridge' ) ) { ZKV_Bridge::invalidate_cache(); }

		// v1.4.0: email-sourced docs — deterministic tag + forwarder confirmation.
		if ( class_exists( 'ZKV_Email_Ingest' ) ) {
			ZKV_Email_Ingest::after_indexed( (int) $document_id, $doc );
		}

		error_log( 'ZKV indexer [' . $document_id . ']: Large PDF indexed successfully (' . count( $chunks ) . ' chunks, ' . count( $all_facts ) . ' facts).' );
		return array( 'success' => true, 'synopsis' => $synopsis );
	}

	// ──────────────────────────────────────────────────────────────
	//  Content Chunk Storage (v1.3.0)
	//
	//  Stores raw extracted text in searchable FULLTEXT chunks.
	//  Unlike the zkv_index.search_text (which contains AI-generated
	//  summaries + key_facts), these chunks contain the ACTUAL document
	//  content — so specific details (a per-unit price, a dimension, a
	//  part number) are directly searchable via MySQL FULLTEXT.
	// ──────────────────────────────────────────────────────────────

	/**
	 * Store document content as searchable chunks in wp_zkv_chunks.
	 *
	 * Extracts the FULL text from the document file (not the truncated
	 * version used for AI prompts), splits it into overlapping chunks,
	 * and stores each with a FULLTEXT index. If the extracted text passed
	 * in is already truncated (process_document flow), this method
	 * re-extracts from the source file for full coverage.
	 *
	 * @since 1.3.0
	 * @param int    $document_id  Document ID.
	 * @param string $text         Extracted text (may be truncated — used as fallback).
	 * @param array  $doc          Document row from wp_zkv_documents.
	 * @return int   Number of chunks stored.
	 */
	public static function store_content_chunks( $document_id, $text, $doc ) {
		global $wpdb;
		$table = $wpdb->prefix . 'zkv_chunks';

		// Delete old chunks for this document (handles re-index cleanly).
		$wpdb->delete( $table, array( 'document_id' => (int) $document_id ), array( '%d' ) );

		// Try to get FULL text from the source file (not truncated).
		$full_text = self::extract_full_text( $doc );
		if ( empty( trim( $full_text ) ) ) {
			// Fallback to whatever was passed in (may be truncated for AI).
			$full_text = $text;
		}

		$full_text = trim( $full_text );
		if ( empty( $full_text ) || strlen( $full_text ) < 50 ) {
			error_log( 'ZKV chunks [' . $document_id . ']: extract_full_text returned '
				. strlen( $full_text ) . ' chars — too short for chunk storage. '
				. 'PDF extraction may have failed (exec disabled?).' );
			return 0;
		}

		// Add user_context and description to the first chunk's search text
		// so those terms are also findable via chunk search.
		$context_prefix = '';
		if ( ! empty( $doc['user_context'] ) ) {
			$context_prefix .= $doc['user_context'] . ' ';
		}
		if ( ! empty( $doc['description'] ) ) {
			$context_prefix .= $doc['description'] . ' ';
		}

		// Split into chunks: 2000 chars with 200 char overlap.
		// Smaller chunks = more precise FULLTEXT matches.
		$chunks = self::split_into_chunks( $full_text, 2000, 200 );

		if ( empty( $chunks ) ) {
			return 0;
		}

		$now   = current_time( 'mysql' );
		$count = 0;

		foreach ( $chunks as $idx => $chunk_text ) {
			$chunk_text = trim( $chunk_text );
			if ( empty( $chunk_text ) ) { continue; }

			// Build search_text: the chunk itself + doc title for relevance.
			// First chunk also gets user_context and description for discoverability.
			$search = $doc['title'] . ' ' . ( $idx === 0 ? $context_prefix : '' ) . $chunk_text;

			$wpdb->insert( $table, array(
				'document_id' => (int) $document_id,
				'chunk_index' => $idx,
				'chunk_text'  => $chunk_text,
				'search_text' => $search,
				'created_at'  => $now,
			), array( '%d', '%d', '%s', '%s', '%s' ) );

			$count++;
		}

		if ( $count > 0 ) {
			error_log( 'ZKV chunks [' . $document_id . ']: Stored ' . $count . ' content chunks (' . strlen( $full_text ) . ' chars total).' );
		}

		return $count;
	}

	/**
	 * Extract FULL text from a document file — no truncation.
	 * Used for content chunk storage where we need every word searchable.
	 *
	 * @since 1.3.0
	 * @param array $doc Document row from wp_zkv_documents.
	 * @return string Full extracted text (may be very large for big PDFs).
	 */
	private static function extract_full_text( $doc ) {
		$path = self::resolve_file_path( $doc );
		if ( empty( $path ) || ! file_exists( $path ) ) { return ''; }

		$mime = $doc['mime_type'];
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		// Transcript/caption formats.
		$transcript_exts = array( 'srt', 'vtt', 'itt', 'sbv', 'ass', 'ssa', 'sub', 'lrc' );
		if ( in_array( $ext, $transcript_exts, true )
		     || in_array( $mime, array( 'text/vtt', 'application/x-subrip' ), true ) ) {
			$raw = file_get_contents( $path );
			return self::parse_transcript( $raw, $ext );
		}

		// JSON transcripts.
		if ( $ext === 'json' || $mime === 'application/json' ) {
			$raw = file_get_contents( $path );
			return self::parse_json_transcript( $raw );
		}

		// Plain text / markdown / CSV — read the whole thing.
		if ( in_array( $mime, array( 'text/plain', 'text/markdown', 'text/csv' ), true ) ) {
			return file_get_contents( $path );
		}

		// PDF — try pdftotext for full extraction.
		if ( $mime === 'application/pdf' ) {
			$exec_ok = function_exists( 'exec' )
				&& ! in_array( 'exec', array_map( 'trim', explode( ',', ini_get( 'disable_functions' ) ) ), true );
			if ( $exec_ok ) {
				$out = array(); $ret = -1;
				@exec( 'pdftotext ' . escapeshellarg( $path ) . ' - 2>/dev/null', $out, $ret );
				if ( $ret === 0 && ! empty( $out ) ) {
					return implode( "\n", $out );
				}
				error_log( 'ZKV indexer: pdftotext failed (exit=' . $ret . ') for ' . basename( $path ) );
			} else {
				error_log( 'ZKV indexer: exec() disabled — cannot run pdftotext for ' . basename( $path )
					. ' (' . round( filesize( $path ) / 1048576, 1 ) . ' MB). Chunks will be empty.'
					. ' Use the chunk seeder script to populate chunks from a machine with pdftotext.' );
			}
			return '';
		}

		// DOCX.
		if ( strpos( $mime, 'wordprocessingml' ) !== false || $mime === 'application/msword' ) {
			if ( class_exists( 'ZipArchive' ) ) {
				$zip = new ZipArchive();
				if ( $zip->open( $path ) === true ) {
					$xml = $zip->getFromName( 'word/document.xml' );
					$zip->close();
					if ( $xml ) { return strip_tags( $xml ); }
				}
			}
			return '';
		}

		// XLSX — shared strings.
		if ( strpos( $mime, 'spreadsheetml' ) !== false || strpos( $mime, 'ms-excel' ) !== false ) {
			if ( class_exists( 'ZipArchive' ) ) {
				$zip = new ZipArchive();
				if ( $zip->open( $path ) === true ) {
					$xml = $zip->getFromName( 'xl/sharedStrings.xml' );
					$zip->close();
					if ( $xml ) { return strip_tags( $xml ); }
				}
			}
			return '';
		}

		return '';
	}

	/**
	 * Split text into overlapping chunks for processing.
	 *
	 * @since 1.2.8
	 * @param string $text       The full text to split.
	 * @param int    $chunk_size Max characters per chunk (default 8000).
	 * @param int    $overlap    Overlap between chunks in characters (default 500).
	 * @return array Array of text chunks.
	 */
	public static function split_into_chunks( $text, $chunk_size = 8000, $overlap = 500 ) {
		$text = trim( $text );
		if ( empty( $text ) ) {
			return array();
		}

		// Use mb_* functions for UTF-8 safety (supplier PDFs often contain
		// accented characters, currency symbols, etc.)
		$text_len = mb_strlen( $text, 'UTF-8' );

		// If text fits in a single chunk, return as-is.
		if ( $text_len <= $chunk_size ) {
			return array( $text );
		}

		$chunks = array();
		$pos    = 0;

		while ( $pos < $text_len ) {
			$end = $pos + $chunk_size;

			if ( $end >= $text_len ) {
				// Last chunk — take everything remaining.
				$chunks[] = mb_substr( $text, $pos, null, 'UTF-8' );
				break;
			}

			// Try to break at a paragraph boundary (double newline).
			$window    = mb_substr( $text, $pos, $chunk_size, 'UTF-8' );
			$break_pos = mb_strrpos( $window, "\n\n", 0, 'UTF-8' );
			if ( $break_pos !== false && $break_pos > $chunk_size * 0.5 ) {
				$end = $pos + $break_pos + 2; // Include the double newline.
			} else {
				// Try to break at a sentence boundary (. followed by space/newline).
				$break_pos = mb_strrpos( $window, '. ', 0, 'UTF-8' );
				if ( $break_pos !== false && $break_pos > $chunk_size * 0.5 ) {
					$end = $pos + $break_pos + 2;
				} else {
					// Try to break at a word boundary (space).
					$break_pos = mb_strrpos( $window, ' ', 0, 'UTF-8' );
					if ( $break_pos !== false && $break_pos > $chunk_size * 0.5 ) {
						$end = $pos + $break_pos + 1;
					}
					// Otherwise, hard break at chunk_size.
				}
			}

			$chunks[] = mb_substr( $text, $pos, $end - $pos, 'UTF-8' );

			// Advance position with overlap.
			$pos = $end - $overlap;
			if ( $pos <= 0 ) {
				$pos = $end; // Safety: prevent infinite loop.
			}
		}

		return $chunks;
	}

	// ──────────────────────────────────────────────────────────────
	//  Transcript / caption file parsers
	//  Strips timestamps, sequence numbers, and formatting tags.
	//  Returns clean spoken text.
	// ──────────────────────────────────────────────────────────────

	/**
	 * Parse a transcript/caption file and extract spoken text.
	 *
	 * @param string $raw Raw file content.
	 * @param string $ext File extension (srt, vtt, itt, sbv, ass, lrc, etc.).
	 * @return string Clean spoken text.
	 */
	public static function parse_transcript( $raw, $ext ) {
		$raw = str_replace( "\r\n", "\n", $raw );

		switch ( $ext ) {
			case 'srt':
				return self::parse_srt( $raw );
			case 'vtt':
				return self::parse_vtt( $raw );
			case 'itt':
				return self::parse_itt( $raw );
			case 'sbv':
				return self::parse_sbv( $raw );
			case 'ass':
			case 'ssa':
				return self::parse_ass( $raw );
			case 'lrc':
				return self::parse_lrc( $raw );
			case 'sub':
				// .sub can be MicroDVD or SubViewer — try both patterns.
				return self::parse_sub( $raw );
			default:
				// Unknown format — strip obvious timestamps and return.
				$text = preg_replace( '/\d{1,2}:\d{2}:\d{2}[\.,]\d{3}\s*-->\s*\d{1,2}:\d{2}:\d{2}[\.,]\d{3}/', '', $raw );
				$text = preg_replace( '/^\d+\s*$/m', '', $text );
				return trim( $text );
		}
	}

	/**
	 * SRT: "1\n00:00:01,000 --> 00:00:04,000\nHello world.\n\n2\n..."
	 */
	private static function parse_srt( $raw ) {
		$blocks = preg_split( '/\n\n+/', trim( $raw ) );
		$lines  = array();
		foreach ( $blocks as $block ) {
			$parts = explode( "\n", trim( $block ) );
			// Skip sequence number (first line) and timestamp (second line).
			$text_parts = array_slice( $parts, 2 );
			$text = implode( ' ', $text_parts );
			$text = strip_tags( $text ); // Remove <i>, <b>, <font> etc.
			$text = trim( $text );
			if ( ! empty( $text ) ) { $lines[] = $text; }
		}
		return implode( "\n", $lines );
	}

	/**
	 * VTT: "WEBVTT\n\n00:00:01.000 --> 00:00:04.000\nHello world.\n\n..."
	 */
	private static function parse_vtt( $raw ) {
		// Remove WEBVTT header and any metadata.
		$raw = preg_replace( '/^WEBVTT.*?\n\n/s', '', $raw );
		// Remove NOTE blocks.
		$raw = preg_replace( '/^NOTE\n.*?\n\n/sm', '', $raw );
		// Remove STYLE blocks.
		$raw = preg_replace( '/^STYLE\n.*?\n\n/sm', '', $raw );

		$blocks = preg_split( '/\n\n+/', trim( $raw ) );
		$lines  = array();
		foreach ( $blocks as $block ) {
			$parts = explode( "\n", trim( $block ) );
			$text_parts = array();
			foreach ( $parts as $line ) {
				// Skip timestamp lines and cue identifiers.
				if ( preg_match( '/\d{2}:\d{2}[\.:]\d{3}\s*-->/', $line ) ) { continue; }
				if ( preg_match( '/^\d+$/', trim( $line ) ) ) { continue; }
				$text_parts[] = strip_tags( $line );
			}
			$text = trim( implode( ' ', $text_parts ) );
			if ( ! empty( $text ) ) { $lines[] = $text; }
		}
		return implode( "\n", $lines );
	}

	/**
	 * ITT (iTunes Timed Text): XML with <p> elements.
	 */
	private static function parse_itt( $raw ) {
		// Simple XML approach — extract text from all <p> and <span> elements.
		$text = strip_tags( $raw );
		// Remove XML declarations and attributes that leaked through.
		$text = preg_replace( '/xmlns[^ ]*/', '', $text );
		$text = preg_replace( '/\s+/', ' ', $text );
		return trim( $text );
	}

	/**
	 * SBV (YouTube): "0:00:01.000,0:00:04.000\nHello world.\n\n..."
	 */
	private static function parse_sbv( $raw ) {
		$blocks = preg_split( '/\n\n+/', trim( $raw ) );
		$lines  = array();
		foreach ( $blocks as $block ) {
			$parts = explode( "\n", trim( $block ) );
			$text_parts = array();
			foreach ( $parts as $line ) {
				if ( preg_match( '/^\d+:\d{2}:\d{2}\.\d{3},\d+:\d{2}:\d{2}\.\d{3}$/', trim( $line ) ) ) { continue; }
				$text_parts[] = trim( $line );
			}
			$text = trim( implode( ' ', $text_parts ) );
			if ( ! empty( $text ) ) { $lines[] = $text; }
		}
		return implode( "\n", $lines );
	}

	/**
	 * ASS/SSA: "Dialogue: 0,0:00:01.00,0:00:04.00,Default,,0,0,0,,Hello world."
	 */
	private static function parse_ass( $raw ) {
		$lines = array();
		foreach ( explode( "\n", $raw ) as $line ) {
			if ( strpos( $line, 'Dialogue:' ) === 0 ) {
				// Text is after the 9th comma.
				$parts = explode( ',', $line, 10 );
				if ( isset( $parts[9] ) ) {
					$text = $parts[9];
					// Remove ASS override tags like {\b1}, {\an8}, {\pos(x,y)}.
					$text = preg_replace( '/\{[^}]*\}/', '', $text );
					// Replace \N (ASS line break) with space.
					$text = str_replace( array( '\\N', '\\n' ), ' ', $text );
					$text = trim( $text );
					if ( ! empty( $text ) ) { $lines[] = $text; }
				}
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * LRC: "[00:01.00]Hello world."
	 */
	private static function parse_lrc( $raw ) {
		$lines = array();
		foreach ( explode( "\n", $raw ) as $line ) {
			// Remove timestamp tags like [00:01.00] or [mm:ss.xx].
			$text = preg_replace( '/\[\d{2}:\d{2}[\.:]\d{2,3}\]/', '', $line );
			// Remove metadata tags like [ar:Artist], [ti:Title].
			$text = preg_replace( '/^\[.+?\]/', '', $text );
			$text = trim( $text );
			if ( ! empty( $text ) ) { $lines[] = $text; }
		}
		return implode( "\n", $lines );
	}

	/**
	 * SUB: MicroDVD "{100}{200}Hello world." or SubViewer "00:00:01.00,00:00:04.00\nHello"
	 */
	private static function parse_sub( $raw ) {
		$lines = array();
		foreach ( explode( "\n", $raw ) as $line ) {
			// MicroDVD format: {start}{end}text
			$text = preg_replace( '/^\{\d+\}\{\d+\}/', '', $line );
			// SubViewer timestamps.
			if ( preg_match( '/^\d{2}:\d{2}:\d{2}\.\d{2},\d{2}:\d{2}:\d{2}\.\d{2}$/', trim( $text ) ) ) { continue; }
			// Remove formatting tags.
			$text = strip_tags( $text );
			$text = str_replace( '|', ' ', $text ); // MicroDVD uses | for line breaks.
			$text = trim( $text );
			if ( ! empty( $text ) && ! preg_match( '/^\[.*\]$/', $text ) ) {
				$lines[] = $text;
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * JSON transcripts — handles common formats from Otter.ai, Rev, Descript, etc.
	 */
	public static function parse_json_transcript( $raw ) {
		$data = json_decode( $raw, true );
		if ( ! $data || ! is_array( $data ) ) {
			return trim( $raw ); // Not valid JSON — treat as plain text.
		}

		$text = '';

		// Otter.ai format: { "transcript": [...], "utterances": [...] }
		if ( isset( $data['utterances'] ) && is_array( $data['utterances'] ) ) {
			foreach ( $data['utterances'] as $u ) {
				$speaker = $u['speaker'] ?? '';
				$words   = $u['text'] ?? $u['transcript'] ?? '';
				if ( ! empty( $words ) ) {
					$text .= ( $speaker ? $speaker . ': ' : '' ) . $words . "\n";
				}
			}
			return trim( $text );
		}

		// Rev / generic: { "monologues": [{ "speaker": ..., "elements": [{ "value": "word" }] }] }
		if ( isset( $data['monologues'] ) && is_array( $data['monologues'] ) ) {
			foreach ( $data['monologues'] as $mono ) {
				$speaker = $mono['speaker']['name'] ?? $mono['speaker'] ?? '';
				$words = '';
				foreach ( $mono['elements'] ?? array() as $el ) {
					$words .= $el['value'] ?? '';
				}
				if ( ! empty( trim( $words ) ) ) {
					$text .= ( $speaker ? $speaker . ': ' : '' ) . trim( $words ) . "\n";
				}
			}
			return trim( $text );
		}

		// Descript / simple: { "segments": [{ "text": "...", "speaker": "..." }] }
		if ( isset( $data['segments'] ) && is_array( $data['segments'] ) ) {
			foreach ( $data['segments'] as $seg ) {
				$speaker = $seg['speaker'] ?? '';
				$words   = $seg['text'] ?? '';
				if ( ! empty( $words ) ) {
					$text .= ( $speaker ? $speaker . ': ' : '' ) . $words . "\n";
				}
			}
			return trim( $text );
		}

		// Flat text array: ["line 1", "line 2", ...]
		if ( isset( $data[0] ) && is_string( $data[0] ) ) {
			return implode( "\n", $data );
		}

		// Unknown JSON structure — just stringify it.
		return trim( $raw );
	}
}
