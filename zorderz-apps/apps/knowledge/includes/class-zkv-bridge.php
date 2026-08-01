<?php
/**
 * ZKV_Bridge — Pricing Oracle bridge for cross-plugin integration.
 *
 * Provides pricing authority document context to other plugins.
 * Used by:
 *   - TS Commission Calculator (TSCC) for cost-of-goods context
 *   - Integration Health Check (check #11) for deployment verification
 *   - TSA Brain Bot for pricing-aware responses
 *
 * Usage in other plugins:
 *   if ( class_exists( 'ZKV_Bridge' ) ) {
 *       $pricing = ZKV_Bridge::get_pricing_context();
 *       // $pricing['doc_ids']  → array of document IDs
 *       // $pricing['block']    → formatted text block for AI injection
 *       // $pricing['facts']    → flat array of pricing facts
 *   }
 *
 * @since 1.2.8
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'ZKV_Bridge' ) ) { return; }

class ZKV_Bridge {

	/** Transient key for pricing context cache. */
	private static $cache_key = 'zkv_pricing_context';

	/** Cache TTL: 1 hour. */
	private static $cache_ttl = HOUR_IN_SECONDS;

	/**
	 * Get pricing authority context — the primary public API.
	 *
	 * Returns an array with:
	 *   'doc_ids'  → array of document IDs marked as pricing authority
	 *   'block'    → formatted text block for AI system prompt injection
	 *   'facts'    → flat array of extracted pricing facts
	 *   'docs'     → array of document metadata (id, title, type, synopsis)
	 *
	 * @return array Pricing context data (always has 'doc_ids' key, even if empty).
	 */
	public static function get_pricing_context() {
		$cached = get_transient( self::$cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT d.id, d.title, d.slug, d.user_context,
			        i.synopsis, i.key_facts, i.document_type
			 FROM {$wpdb->prefix}zkv_documents d
			 JOIN {$wpdb->prefix}zkv_index i ON i.document_id = d.id AND i.is_current = 1
			 WHERE d.is_pricing_authority = 1
			   AND d.status = 'indexed'
			 ORDER BY d.updated_at DESC",
			ARRAY_A
		);

		$result = array(
			'doc_ids' => array(),
			'block'   => '',
			'facts'   => array(),
			'docs'    => array(),
		);

		if ( empty( $rows ) ) {
			set_transient( self::$cache_key, $result, self::$cache_ttl );
			return $result;
		}

		$block = "\n═══ PRICING AUTHORITY DOCUMENTS ═══\n";
		$block .= "These documents are designated pricing sources. Use their data for cost/price questions.\n\n";

		foreach ( $rows as $r ) {
			$result['doc_ids'][] = (int) $r['id'];

			$result['docs'][] = array(
				'id'            => (int) $r['id'],
				'title'         => $r['title'],
				'document_type' => $r['document_type'],
				'synopsis'      => $r['synopsis'],
			);

			$slug = ! empty( $r['slug'] ) ? $r['slug'] : $r['id'];
			$block .= "PRICING-{$r['id']}: {$r['title']} [{$r['document_type']}]\n";
			$block .= "  {$r['synopsis']}\n";

			if ( ! empty( $r['user_context'] ) ) {
				$block .= "  Context: {$r['user_context']}\n";
			}

			$facts = json_decode( $r['key_facts'] ?? '[]', true );
			if ( is_array( $facts ) && ! empty( $facts ) ) {
				$result['facts'] = array_merge( $result['facts'], $facts );
				$block .= "  Facts: " . implode( '; ', array_slice( $facts, 0, 8 ) ) . "\n";
			}

			$block .= '  Source: ' . ( function_exists( 'zkv_secure_url' ) ? zkv_secure_url( (int) $r['id'], is_string( $slug ) ? $slug : '' ) : home_url( '/vault/' . $slug ) ) . "\n\n";
		}

		$result['block'] = $block;

		set_transient( self::$cache_key, $result, self::$cache_ttl );
		return $result;
	}

	/**
	 * Invalidate the pricing context cache.
	 * Call when: pricing authority is toggled, a pricing doc is re-indexed or deleted.
	 */
	public static function invalidate_cache() {
		delete_transient( self::$cache_key );
	}

	/**
	 * Check if a specific document is a pricing authority.
	 *
	 * @param int $document_id
	 * @return bool
	 */
	public static function is_pricing_authority( $document_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT is_pricing_authority FROM {$wpdb->prefix}zkv_documents WHERE id = %d",
			(int) $document_id
		) );
	}

	/**
	 * Get count of pricing authority documents.
	 *
	 * @return int
	 */
	public static function get_count() {
		global $wpdb;
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}zkv_documents WHERE is_pricing_authority = 1 AND status = 'indexed'"
		);
	}
}
