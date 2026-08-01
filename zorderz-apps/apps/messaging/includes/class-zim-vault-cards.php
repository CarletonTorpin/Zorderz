<?php
/**
 * Knowledge Vault preview cards in messages.
 *
 * Detects vault references in message bodies and renders preview cards
 * showing the document title, synopsis, type, and a link. Mirrors the
 * ZIM_Preview_Cards pattern for FreshBooks #NNNNN references.
 *
 * Detection patterns (any of these trigger a vault card):
 *   - [VAULT-{id}]         — Brain Bot citation format
 *   - /vault/{slug}        — pretty URL path
 *   - your site's /vault/{slug} — full URL
 *
 * Data comes from TSKV's REST endpoint `/zorderz/v1/vault/preview/{id}`
 * via internal REST dispatch (no external HTTP). Graceful fallback
 * when TSKV isn't active.
 *
 * v1.0.20: Initial implementation.
 *
 * @package TSIM
 */

defined( 'ABSPATH' ) || exit;

class ZIM_Vault_Cards {

	/** Transient TTL for successful vault previews. */
	const CACHE_TTL = 10 * MINUTE_IN_SECONDS;

	/** Transient TTL for negative lookups. */
	const NEG_CACHE_TTL = 2 * MINUTE_IN_SECONDS;

	/**
	 * Fetch (and cache) the preview card for a vault document by ID.
	 *
	 * @param int $doc_id Vault document ID.
	 * @return array|WP_Error Card payload on success; WP_Error on unresolved.
	 */
	public static function get_card( $doc_id ) {
		$doc_id = absint( $doc_id );
		if ( $doc_id <= 0 ) {
			return new WP_Error( 'zim_vault_bad_id', 'Invalid vault document ID.' );
		}

		$key    = 'zim_vault_preview_' . $doc_id;
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			if ( isset( $cached['__miss'] ) ) {
				return new WP_Error( 'zim_vault_not_found', 'Vault document not found.' );
			}
			return $cached;
		}

		// TSKV REST endpoint available?
		$card = self::fetch_via_rest( $doc_id );

		if ( is_wp_error( $card ) ) {
			if ( 'zim_vault_not_found' === $card->get_error_code() ) {
				set_transient( $key, array( '__miss' => true ), self::NEG_CACHE_TTL );
			}
			return $card;
		}

		set_transient( $key, $card, self::CACHE_TTL );
		return $card;
	}

	/**
	 * Resolve a vault slug to a document ID.
	 *
	 * Falls back to a direct DB query against the TSKV documents table.
	 * Returns 0 if not found or TSKV tables don't exist.
	 *
	 * @param string $slug Vault document slug.
	 * @return int Document ID, or 0 if not found.
	 */
	public static function resolve_slug( $slug ) {
		global $wpdb;
		$slug  = sanitize_title( $slug );
		$table = $wpdb->prefix . 'tskv_documents';

		// Guard: table might not exist if TSKV is deactivated.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$exists = $wpdb->get_var(
			$wpdb->prepare( "SHOW TABLES LIKE %s", $table )
		);
		if ( empty( $exists ) ) {
			return 0;
		}

		$id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE slug = %s AND status = 'indexed' LIMIT 1",
			$slug
		) );
		return $id ? (int) $id : 0;
	}

	/**
	 * Call TSKV's REST preview endpoint via internal dispatch.
	 */
	private static function fetch_via_rest( $doc_id ) {
		$req  = new WP_REST_Request( 'GET', '/zorderz/v1/vault/preview/' . $doc_id );
		$resp = rest_do_request( $req );

		if ( $resp->is_error() ) {
			$status = $resp->get_status();
			if ( 404 === $status ) {
				return new WP_Error( 'zim_vault_not_found', 'Vault document not found.' );
			}
			$err = $resp->as_error();
			return new WP_Error(
				'zim_vault_rest_error',
				$err ? $err->get_error_message() : 'Vault preview failed.'
			);
		}

		$data = $resp->get_data();
		if ( ! is_array( $data ) || empty( $data['success'] ) || empty( $data['data'] ) ) {
			return new WP_Error( 'zim_vault_bad_payload', 'Vault returned no data.' );
		}

		return self::normalize_card( $data['data'] );
	}

	/**
	 * Normalize the TSKV response into the compact form our renderer expects.
	 *
	 * v1.0.21: Use the vault PAGE URL (/vault/{slug}) instead of the raw
	 * file_url. The file_url points to the physical upload (PDF/DOCX) which
	 * browsers download rather than display. The vault page renders the
	 * document inline with full formatting, navigation, and metadata —
	 * exactly what team members expect when clicking a link in chat.
	 */
	private static function normalize_card( $data ) {
		$slug = sanitize_title( $data['slug'] ?? '' );

		// Build the vault page URL from the slug — this opens in-browser.
		// Fall back to file_url only if slug is missing (legacy documents).
		$vault_url = '';
		if ( $slug ) {
			$vault_url = home_url( '/vault/' . $slug );
		} elseif ( ! empty( $data['file_url'] ) ) {
			$vault_url = (string) $data['file_url'];
		}

		return array(
			'id'            => (int) ( $data['id'] ?? 0 ),
			'title'         => (string) ( $data['title'] ?? 'Untitled' ),
			'slug'          => $slug,
			'synopsis'      => (string) ( $data['synopsis'] ?? '' ),
			'document_type' => (string) ( $data['document_type'] ?? 'general' ),
			'uploader_name' => (string) ( $data['uploader_name'] ?? '' ),
			'uploaded_at'   => (string) ( $data['uploaded_at'] ?? '' ),
			'url'           => $vault_url,
			'source'        => 'tskv',
		);
	}
}
