<?php
/**
 * ZIB_Tags — the tag REGISTRY (definitions) + owner-scoped ASSIGNMENTS.
 *
 * DOCTRINE (the covert-channel guard — Session P6 owner catch):
 *   • A tag ASSIGNMENT (which of a user's messages carry a tag) is ALWAYS owner-scoped
 *     and NEVER crosses users — same as a mail body.
 *   • The tag VOCABULARY is the only thing that can be shared, and even its *existence*
 *     is an egress surface: a string like "HideFromAlex", or one encoding data from a
 *     poisoned email, would leak the instant another user saw it in a list or was suggested
 *     it. So GLOBAL tags are a CURATED, human-authored vocabulary only; a user- or
 *     synth-proposed tag is registered PRIVATE to its owner (scope 'user'), is never shown
 *     or suggested to any other user, and reaches 'global' only by an explicit admin
 *     re-authoring — never by auto-promoting the raw string. vocabulary_for_owner() returns
 *     `global + this owner's own tags` and NOTHING else.
 *
 * normalize_key() and looks_like_pii_or_directive() are PURE (unit-tested). The registry
 * read/write methods are the WP/DB seam.
 *
 * @since 0.6.0 (P6a — enrichment foundation; user-tag creation is exercised in P6b)
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIB_Tags {

	private static function t_tag(): string { global $wpdb; return $wpdb->prefix . 'zib_tags'; }
	private static function t_mtag(): string { global $wpdb; return $wpdb->prefix . 'zib_message_tags'; }

	// ── PURE helpers (unit-tested) ──────────────────────────────────

	/** Canonical tag key: lowercase, hyphenated, [a-z0-9-] only, collapsed, capped. PURE. */
	public static function normalize_key( string $label ): string {
		$k = strtolower( trim( $label ) );
		$k = preg_replace( '/[\s_]+/', '-', $k );
		$k = preg_replace( '/[^a-z0-9\-]/', '', (string) $k );
		$k = preg_replace( '/-{2,}/', '-', (string) $k );
		$k = trim( (string) $k, '-' );
		return ( strlen( $k ) > 64 ) ? substr( $k, 0, 64 ) : $k;
	}

	/**
	 * Is a proposed tag UNSAFE to ever share (or even to store as a clean category)?
	 * Runs on the RAW label + definition (before normalization) so it can catch capitalized
	 * names and directive phrasing. Returns TRUE = not-clean → keep private, never promotable.
	 * Conservative on purpose: a false "unsafe" only keeps a tag private (fail-closed). PURE.
	 */
	public static function looks_like_pii_or_directive( string $raw_label, string $definition = '' ): bool {
		$label = trim( $raw_label );
		$all   = $label . ' ' . $definition;

		// Hard PII / exfil shapes anywhere.
		if ( preg_match( '/[\w.+-]+@[\w-]+\.[\w.]+/', $all ) ) { return true; }   // email
		if ( preg_match( '#https?://|\bwww\.#i', $all ) ) { return true; }         // url
		if ( preg_match( '/\d{5,}/', $all ) ) { return true; }                     // phone / long id
		if ( preg_match( '/[<>{}\[\]|]/', $label ) ) { return true; }              // markup/fence chars in a label

		// A category label that reads as an IMPERATIVE or names/addresses a person is not a category.
		if ( preg_match( '/\b(hide|ignore|tell|show|send|forward|delete|remember|reply|notify|alert|dm|message|call|text|email)\b/i', $label ) ) { return true; }
		if ( preg_match( '/\b(from|to|for|re|cc|about)\b\s*[A-Z][a-z]+/', $label ) ) { return true; } // "from Alex"
		if ( preg_match( '/\b(secret|private|confidential|internal-?only|do-?not)\b/i', $label ) ) { return true; }

		// A camel/Title-cased personal-name-ish token squashed together ("HideFromAlex",
		// "AlexSecret") — 2+ capitalized humps in a single token.
		if ( preg_match( '/\b[A-Z][a-z]+[A-Z][a-z]+/', $label ) ) { return true; }

		return false;
	}

	// ── ASSIGNMENT (owner-scoped) ───────────────────────────────────

	/**
	 * Assign a set of GLOBAL seed tag_keys to a message. Owner-scoped, idempotent
	 * (clears this message's prior assignments first, so re-enrich is clean).
	 *
	 * @param string[] $keys global seed tag_keys.
	 */
	public static function assign_seed( int $message_id, int $owner, array $keys, string $source = 'deterministic' ): void {
		global $wpdb;
		if ( $message_id <= 0 || $owner <= 0 ) {
			return;
		}
		$wpdb->delete( self::t_mtag(), array( 'message_id' => $message_id ), array( '%d' ) );
		if ( empty( $keys ) ) {
			return;
		}
		foreach ( self::resolve_global_ids( $keys ) as $tag_id ) {
			$wpdb->query( $wpdb->prepare(
				'INSERT INTO ' . self::t_mtag() . ' (message_id, owner_user_id, tag_id, source, confidence, created_at)
				 VALUES (%d, %d, %d, %s, %f, %s)
				 ON DUPLICATE KEY UPDATE source = VALUES(source)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$message_id, $owner, $tag_id, $source, 0.80, gmdate( 'Y-m-d H:i:s' )
			) );
		}
	}

	/** Map global seed tag_keys → ids (active, scope=global). @return int[] */
	public static function resolve_global_ids( array $keys ): array {
		global $wpdb;
		$keys = array_values( array_unique( array_filter( array_map( 'strval', $keys ) ) ) );
		if ( empty( $keys ) ) {
			return array();
		}
		$ph  = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$ids = $wpdb->get_col( $wpdb->prepare(
			'SELECT id FROM ' . self::t_tag() . " WHERE scope = 'global' AND owner_user_id = 0 AND status = 'active' AND tag_key IN ($ph)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			...$keys
		) );
		return array_map( 'intval', (array) $ids );
	}

	// ── VOCABULARY (covert-channel-safe scoping) ────────────────────

	/**
	 * The tag vocabulary a given owner may see / be suggested / have the synthesizer prefer:
	 * GLOBAL (curated) + THIS owner's own tags — and nothing from any other user.
	 *
	 * @return array<int,array{key:string,label:string,definition:string,scope:string}>
	 */
	public static function vocabulary_for_owner( int $owner ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT tag_key, label, definition, scope FROM ' . self::t_tag() . "
			 WHERE status = 'active' AND ( scope = 'global' OR ( scope = 'user' AND owner_user_id = %d ) )
			 ORDER BY scope DESC, tag_key ASC", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			(int) $owner
		) );
		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'key'        => (string) $r->tag_key,
				'label'      => (string) $r->label,
				'definition' => (string) $r->definition,
				'scope'      => (string) $r->scope,
			);
		}
		return $out;
	}

	/**
	 * Register a NEW tag PRIVATE to one owner (P6b synth / manual). Never global. The PII/
	 * directive screen sets pii_clean (promotability); a hard-unsafe shape is refused
	 * outright. Returns the tag id, or 0 if refused. Idempotent by (owner, key).
	 */
	public static function register_user_tag( int $owner, string $label, string $definition, int $created_by, string $source = 'llm' ): int {
		global $wpdb;
		$key = self::normalize_key( $label );
		if ( $owner <= 0 || '' === $key ) {
			return 0;
		}
		$unsafe = self::looks_like_pii_or_directive( $label, $definition );
		// Refuse the hardest shapes (email/url/phone/markup) even as a private tag.
		if ( $unsafe && preg_match( '/[\w.+-]+@[\w-]+\.[\w.]+|https?:\/\/|\d{5,}|[<>{}\[\]|]/i', $label . ' ' . $definition ) ) {
			return 0;
		}
		$existing = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . self::t_tag() . " WHERE scope = 'user' AND owner_user_id = %d AND tag_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$owner, $key
		) );
		if ( $existing > 0 ) {
			return $existing;
		}
		$wpdb->insert( self::t_tag(), array(
			'tag_key'       => $key,
			'label'         => ( strlen( $label ) > 64 ) ? substr( $label, 0, 64 ) : $label,
			'definition'    => ( strlen( $definition ) > 255 ) ? substr( $definition, 0, 255 ) : $definition,
			'scope'         => 'user',
			'owner_user_id' => $owner,
			'pii_clean'     => $unsafe ? 0 : 1,
			'status'        => 'active',
			'source'        => $source,
			'created_by'    => $created_by,
		) );
		return (int) $wpdb->insert_id;
	}
}
