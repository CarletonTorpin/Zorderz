<?php
/**
 * Zorderz Leads — Nutshell CRM adapter for the recovery port (D-03 / S6-01)
 *
 * Binds {@see ZL_Crm_Port} to the concrete CRM client ({@see ZL_Nutshell}). This is the
 * ONLY place the recovery flow touches the network. It uses only the CRM's read and
 * create primitives — never `edit_contact` — so read-only contact resolution is
 * guaranteed by what this class can call, not by a caller's discipline.
 *
 * The recovery-created lead carries a compact `[batch_tag]` marker in its note so a
 * later retry can find it again (adopt-not-twin) when a previous attempt created the
 * lead but lost the local id write-back.
 *
 * Not exercised by the no-WP harness (that uses a fake port); this is the production
 * binding. The mechanism it serves ({@see ZL_Lead_Recovery::recover()}) is proven
 * separately against the interface.
 *
 * @package Zorderz\Leads
 * @since   2.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The plugin loads includes via a sorted glob, where 'class-*' sorts before
// 'interface-*'. This class `implements ZL_Crm_Port`, which must be defined at class
// declaration time — so pull the interface in explicitly (idempotent) rather than rely
// on load order.
require_once __DIR__ . '/interface-zl-crm-port.php';

class ZL_Nutshell_Crm_Port implements ZL_Crm_Port {

	/** @var ZL_Nutshell */
	private $ns;

	/**
	 * @param ZL_Nutshell $ns A CRM-only client (already credentialed).
	 */
	public function __construct( ZL_Nutshell $ns ) {
		$this->ns = $ns;
	}

	/**
	 * READ-ONLY. Resolve a contact id by exact email.
	 *
	 * @param string $email
	 * @return int|null
	 */
	public function find_contact_id_by_email( string $email ): ?int {
		$email = trim( $email );
		if ( $email === '' || strpos( $email, '@' ) === false ) {
			return null;
		}
		$search = $this->ns->search_by_email( $email );
		$id     = $this->ns->extract_contact_id_or_null( $search );
		return $id ? (int) $id : null;
	}

	/**
	 * READ-ONLY. Fetch a contact record by id.
	 *
	 * @param int $contact_id
	 * @return array|null
	 */
	public function get_contact( int $contact_id ): ?array {
		if ( $contact_id <= 0 ) {
			return null;
		}
		try {
			$c = $this->ns->get_contact( $contact_id );
			return is_array( $c ) ? $c : null;
		} catch ( \Throwable $e ) {
			// A read failure is "unknown", not "gone" — surface as null so the caller
			// re-resolves rather than trusting a stale id.
			error_log( 'ZL Recovery: get_contact failed for #' . $contact_id . ': ' . $e->getMessage() );
			return null;
		}
	}

	/**
	 * Create a NEW contact from the saved lead row (name/phone/email/address).
	 *
	 * @param array $lead
	 * @return int|null
	 */
	public function create_contact( array $lead ): ?int {
		$contact = array(
			'name' => array(
				'givenName'  => trim( (string) ( $lead['first_name'] ?? '' ) ),
				'familyName' => trim( (string) ( $lead['last_name'] ?? '' ) ),
			),
		);

		$phone = self::normalize_phone( (string) ( $lead['phone'] ?? '' ) );
		if ( $phone !== '' ) {
			$contact['phone'] = array( array( 'number' => $phone ) );
		}

		$email = trim( (string) ( $lead['email'] ?? '' ) );
		if ( $email !== '' ) {
			$contact['email'] = array( array( 'address' => $email ) );
		}

		$address = self::parse_city_to_address( (string) ( $lead['city'] ?? '' ) );
		if ( ! empty( $address ) ) {
			$contact['address'] = array( $address );
		}

		// Business name from the profile (site-name fallback) — never a hardcoded literal.
		$biz = (string) apply_filters( 'zl_business_name', get_bloginfo( 'name' ) );
		$contact['description'] = trim( ( $biz !== '' ? $biz . ' ' : '' ) . 'customer — recovered by the lead generator' );

		try {
			$res = $this->ns->new_contact( $contact );
			if ( is_array( $res ) && isset( $res['id'] ) ) {
				return (int) $res['id'];
			}
		} catch ( \Throwable $e ) {
			error_log( 'ZL Recovery: create_contact failed: ' . $e->getMessage() );
			throw $e; // let the mechanism classify it
		}
		return null;
	}

	/**
	 * Adopt-not-twin guard. Bounded full-text search for the batch tag; a
	 * recovery-created lead carries the tag in its note, so a prior partial attempt is
	 * found and adopted rather than twinned.
	 *
	 * @param int    $contact_id
	 * @param string $batch_tag
	 * @param int    $limit
	 * @return int|null
	 */
	public function find_existing_lead_for_contact( int $contact_id, string $batch_tag, int $limit = 15 ): ?int {
		$batch_tag = trim( $batch_tag );
		// A too-short/empty tag is not specific enough to adopt safely — skip the guard
		// (idempotency on the stored lead id remains the primary protection).
		if ( strlen( $batch_tag ) < 6 || $contact_id <= 0 ) {
			return null;
		}
		try {
			$hits = $this->ns->search_leads( $batch_tag, max( 1, (int) $limit ) );
		} catch ( \Throwable $e ) {
			error_log( 'ZL Recovery: dup-guard search_leads failed: ' . $e->getMessage() );
			return null;
		}
		if ( ! is_array( $hits ) ) {
			return null;
		}
		$scanned = 0;
		foreach ( $hits as $hit ) {
			if ( $scanned++ >= $limit ) {
				break;
			}
			$id = is_array( $hit ) ? (int) ( $hit['id'] ?? 0 ) : 0;
			if ( $id <= 0 ) {
				continue;
			}
			// The batch tag is unique per batch and only this batch's recovery writes it
			// into a lead's note, so a full-text hit on the tag is this batch's lead.
			return $id;
		}
		return null;
	}

	/**
	 * Create the CRM lead for a resolved contact and stamp the batch tag into its note.
	 *
	 * @param array  $lead
	 * @param int    $contact_id
	 * @param string $batch_tag
	 * @return int|null
	 */
	public function create_lead( array $lead, int $contact_id, string $batch_tag ): ?int {
		$full_name = trim( (string) ( $lead['first_name'] ?? '' ) . ' ' . (string) ( $lead['last_name'] ?? '' ) );

		// Prefer the description already refined for Nutshell's <101-char limit during
		// the batch; fall back to a neutral summary. Never invent product nouns.
		$desc = trim( (string) ( $lead['purchase_summary'] ?? '' ) );
		if ( $desc === '' ) {
			$desc = $full_name !== '' ? ( 'Follow-up: ' . $full_name ) : 'Recovered lead';
		}
		if ( function_exists( 'mb_substr' ) ) {
			$desc = mb_substr( $desc, 0, 100 );
		} else {
			$desc = substr( $desc, 0, 100 );
		}

		$lead_params = array(
			'description' => $desc,
			'contacts'    => array( array( 'id' => (int) $contact_id ) ),
		);

		try {
			$res = $this->ns->new_lead( $lead_params );
		} catch ( \Throwable $e ) {
			error_log( 'ZL Recovery: new_lead failed: ' . $e->getMessage() );
			throw $e;
		}
		if ( ! is_array( $res ) || ! isset( $res['id'] ) ) {
			return null;
		}
		$lead_id = (int) $res['id'];

		// Stamp the batch tag into a note so adopt-not-twin can find this lead later.
		// A note failure is non-fatal — the lead exists; log and move on.
		if ( $batch_tag !== '' ) {
			try {
				$note = 'Recovered lead. Batch: ' . $batch_tag;
				if ( $full_name !== '' ) {
					$note .= ' — ' . $full_name;
				}
				$this->ns->new_note( array( 'entityType' => 'Leads', 'id' => $lead_id ), $note );
			} catch ( \Throwable $e ) {
				error_log( 'ZL Recovery: note stamp failed for lead #' . $lead_id . ': ' . $e->getMessage() );
			}
		}

		return $lead_id;
	}

	// ── small, self-contained payload helpers (no dependence on the generator) ──

	/**
	 * Normalize a phone number to digits (with a leading + preserved). Empty when there
	 * is nothing usable.
	 *
	 * @param string $raw
	 * @return string
	 */
	private static function normalize_phone( string $raw ): string {
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return '';
		}
		$plus   = ( strpos( $raw, '+' ) === 0 ) ? '+' : '';
		$digits = preg_replace( '/[^0-9]/', '', $raw );
		if ( $digits === '' ) {
			return '';
		}
		return $plus . $digits;
	}

	/**
	 * Parse a "City, ST ZIP" string into a Nutshell address array, or [] when nothing
	 * useful can be extracted.
	 *
	 * @param string $city_field
	 * @return array
	 */
	private static function parse_city_to_address( string $city_field ): array {
		$city_field = trim( $city_field );
		if ( $city_field === '' ) {
			return array();
		}
		$out = array();
		// ZIP (5 digits) if present.
		if ( preg_match( '/\b(\d{5})(?:-\d{4})?\b/', $city_field, $m ) ) {
			$out['postalCode'] = $m[1];
		}
		// "City, ST" prefix.
		if ( preg_match( '/^([^,]+),\s*([A-Za-z]{2})\b/', $city_field, $m ) ) {
			$out['city']  = trim( $m[1] );
			$out['state'] = strtoupper( $m[2] );
		} elseif ( strpos( $city_field, ',' ) !== false ) {
			$parts = explode( ',', $city_field );
			$out['city'] = trim( $parts[0] );
		} else {
			$out['city'] = $city_field;
		}
		return $out;
	}
}
