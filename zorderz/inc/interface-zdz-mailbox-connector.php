<?php
namespace Zorderz;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mailbox_Connector — the Zorderz Core seam for reading a person's mailbox.
 *
 * Zorderz Core knows HOW to connect a mailbox, pull message metadata, and fetch
 * one body on demand; it does not know WHICH provider a business uses. A
 * connector implements this contract for one provider. The Microsoft Graph
 * implementation ships with the Inbox app; another provider (Gmail / IMAP) can
 * be added later by registering a second connector on the `zdz_mailbox_connectors`
 * filter — with no change to the app that consumes it.
 *
 * TWO-STAGE READ (a hard privacy boundary, not an optimization). The header
 * methods (`backfill_page`, `changes_since`) return METADATA ONLY — participants,
 * subject, a short preview, timestamps — never a body. That lets the classifier
 * decide in-scope / out-of-scope from the participants alone. A full body is
 * fetched (`fetch_body`) ONLY for an in-scope message, so an out-of-scope
 * message's body is never pulled into the system.
 *
 * SAFE NO-OP UNTIL CONFIGURED. Credentials are read from the platform's
 * Connections binding, never hardcoded. Every method returns cleanly (a WP_Error
 * or an empty result) until the connector is configured AND the person has
 * connected their own mailbox — the "dark by default" posture the whole
 * subsystem ships in.
 *
 * All identity is per-person: a mailbox is personal, so every method takes the
 * acting user id and a connector resolves it to that user's own stored grant.
 * The shared kiosk is never admitted (a device owns no mailbox).
 *
 * @since 1.8.0
 * @package Zorderz
 */
interface Mailbox_Connector {

	/** Machine id for this provider, e.g. 'microsoft_graph'. */
	public function provider(): string;

	/** Human label for the connect card, e.g. 'Microsoft 365'. */
	public function label(): string;

	/**
	 * Are the platform credentials present (from the Connections binding)?
	 * False keeps every surface a no-op.
	 */
	public function is_configured(): bool;

	/** Has this person connected their own mailbox? */
	public function is_connected( int $user_id ): bool;

	/**
	 * Begin the per-user OAuth connect. Returns the provider sign-in URL the
	 * Connect button navigates to (nonce-armed, single-use signed state).
	 *
	 * @param int $user_id
	 * @return string|\WP_Error
	 */
	public function connect_start_url( int $user_id );

	/**
	 * Best-effort revoke, then delete this person's stored grant + encrypted
	 * tokens (and, once ingestion is on, purge their indexed mail).
	 *
	 * @param int $user_id
	 * @return bool
	 */
	public function disconnect( int $user_id ): bool;

	/**
	 * Open a forward-sync checkpoint anchored at "now" for a folder. History is
	 * the backfill's job; the checkpoint covers everything after this point.
	 *
	 * @param int    $user_id
	 * @param string $folder Logical folder, e.g. 'inbox' | 'sent'.
	 * @return string|\WP_Error Opaque cursor.
	 */
	public function open_checkpoint( int $user_id, string $folder );

	/**
	 * One page of history for a folder, no older than $since_iso, METADATA ONLY.
	 *
	 * @param int    $user_id
	 * @param string $folder
	 * @param string $since_iso ISO-8601 UTC lower bound.
	 * @param string $cursor    A prior page cursor, or '' for the first page.
	 * @return array|\WP_Error { messages: array[], next: string }
	 */
	public function backfill_page( int $user_id, string $folder, string $since_iso, string $cursor = '' );

	/**
	 * Changes since an opaque cursor (new/changed + removed ids), METADATA ONLY.
	 * A returned `checkpoint` is the durable cursor to store for next time; a
	 * returned `next` means more pages remain in this burst.
	 *
	 * @param int    $user_id
	 * @param string $cursor
	 * @return array|\WP_Error { items: array[], removed: string[], next: string, checkpoint: string }
	 */
	public function changes_since( int $user_id, string $cursor );

	/**
	 * Stage two: fetch ONE message's body. Only ever called for an in-scope
	 * message the classifier has already admitted.
	 *
	 * @param int    $user_id
	 * @param string $message_id Provider message id.
	 * @return array|\WP_Error { content: string, format: 'text'|'html' }
	 */
	public function fetch_body( int $user_id, string $message_id );

	/**
	 * The connected account's own identity — the immutable external id plus the
	 * address and any aliases it owns — for the mailbox-identity resolver. Never
	 * returns tokens.
	 *
	 * @param int $user_id
	 * @return array { external_id: string, address: string, aliases: string[] }
	 */
	public function owner_identity( int $user_id ): array;
}
