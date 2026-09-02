<?php
/**
 * ZIB_Connector_Microsoft — the Microsoft Graph implementation of the Core
 * `\Zorderz\Mailbox_Connector` seam.
 *
 * A thin adapter over ZIB_Graph / ZIB_OAuth / ZIB_Connections: it resolves the
 * acting user to THEIR OWN stored mailbox grant and delegates. The two-stage
 * read (metadata via backfill/changes, one body via fetch_body) and the
 * read-only, no-send scope boundary live in ZIB_Graph and are unchanged here.
 *
 * Registered on the `zdz_mailbox_connectors` filter (from app.php, on
 * after_setup_theme once the theme interface exists) so a future provider —
 * Gmail / IMAP — can slot in beside it with no change to the consuming code.
 *
 * @since 1.8.0
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIB_Connector_Microsoft implements \Zorderz\Mailbox_Connector {

	public function provider(): string {
		return 'microsoft_graph';
	}

	public function label(): string {
		return 'Microsoft 365';
	}

	public function is_configured(): bool {
		return class_exists( 'ZIB_Settings' ) && ZIB_Settings::feature_enabled();
	}

	public function is_connected( int $user_id ): bool {
		return $this->account_id( $user_id ) > 0;
	}

	public function connect_start_url( int $user_id ) {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'zib_unconfigured', 'The mailbox connector is not configured.' );
		}
		if ( ! class_exists( 'ZIB_OAuth' ) ) {
			return new \WP_Error( 'zib_no_oauth', 'The mailbox OAuth surface is unavailable.' );
		}
		return ZIB_OAuth::start_url();
	}

	public function disconnect( int $user_id ): bool {
		$acct = $this->account_id( $user_id );
		if ( $acct <= 0 || ! class_exists( 'ZIB_OAuth' ) ) {
			return false;
		}
		return ZIB_OAuth::disconnect( $user_id, $acct );
	}

	public function open_checkpoint( int $user_id, string $folder ) {
		$acct = $this->require_account( $user_id );
		if ( is_wp_error( $acct ) ) {
			return $acct;
		}
		return ZIB_Graph::delta_init( $acct, $folder );
	}

	public function backfill_page( int $user_id, string $folder, string $since_iso, string $cursor = '' ) {
		$acct = $this->require_account( $user_id );
		if ( is_wp_error( $acct ) ) {
			return $acct;
		}
		return ZIB_Graph::backfill_page( $acct, $folder, $since_iso, $cursor );
	}

	public function changes_since( int $user_id, string $cursor ) {
		$acct = $this->require_account( $user_id );
		if ( is_wp_error( $acct ) ) {
			return $acct;
		}
		$r = ZIB_Graph::delta_page( $acct, $cursor );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		// Map the Graph shape onto the Core contract: the deltaLink is the durable
		// checkpoint to store; a nextLink means more pages remain in this burst.
		return array(
			'items'      => isset( $r['items'] ) ? (array) $r['items'] : array(),
			'removed'    => isset( $r['removed'] ) ? (array) $r['removed'] : array(),
			'next'       => (string) ( $r['next'] ?? '' ),
			'checkpoint' => (string) ( $r['delta'] ?? '' ),
		);
	}

	public function fetch_body( int $user_id, string $message_id ) {
		$acct = $this->require_account( $user_id );
		if ( is_wp_error( $acct ) ) {
			return $acct;
		}
		return ZIB_Graph::fetch_body( $acct, $message_id );
	}

	public function owner_identity( int $user_id ): array {
		$out = array(
			'external_id' => '',
			'address'     => '',
			'aliases'     => array(),
		);
		if ( ! class_exists( 'ZIB_Connections' ) ) {
			return $out;
		}
		$conn = ZIB_Connections::get_for_user( $user_id );
		if ( ! $conn ) {
			return $out;
		}
		$out['address'] = (string) ( $conn['upn'] ?? ( $conn['email_label'] ?? '' ) );
		return $out;
	}

	/** Resolve the acting user to their own mailbox account id (0 if none). */
	private function account_id( int $user_id ): int {
		if ( $user_id <= 0 || ! class_exists( 'ZIB_Connections' ) ) {
			return 0;
		}
		$conn = ZIB_Connections::get_for_user( $user_id );
		return $conn ? (int) $conn['id'] : 0;
	}

	/** Account id, or a WP_Error when the person has no connected mailbox / no client. */
	private function require_account( int $user_id ) {
		$acct = $this->account_id( $user_id );
		if ( $acct <= 0 ) {
			return new \WP_Error( 'zib_not_connected', 'No mailbox is connected for this user.' );
		}
		if ( ! class_exists( 'ZIB_Graph' ) ) {
			return new \WP_Error( 'zib_no_graph', 'The mailbox client is unavailable.' );
		}
		return $acct;
	}
}
