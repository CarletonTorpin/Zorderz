<?php
/**
 * Zorderz Leads — CRM Port (recover-a-failed-lead)
 *
 * The narrow set of CRM operations the duplicate-safe recovery flow is allowed to
 * perform. It is deliberately SMALL and, by construction, contains NO contact-mutating
 * verb: there is no `edit_contact` here, so a recovery can never blank a good phone or
 * address over the top of live CRM data. "Read-only contact resolve" is thus not a
 * discipline the caller must remember — it is a property of the type.
 *
 * Every method is a pure port: an adapter binds it to a concrete CRM client
 * (ZL_Nutshell → ZL_Nutshell_Crm_Port), and a fake binds it for the no-WP harness. The
 * recovery mechanism (ZL_Lead_Recovery) depends only on this interface, so its
 * duplicate-safety and read-only guarantees are provable without WordPress or a network.
 *
 * @package Zorderz\Leads
 * @since   2.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface ZL_Crm_Port {

	/**
	 * READ-ONLY. Resolve an existing contact id by exact email, or null when none.
	 * Must never create or mutate a contact.
	 *
	 * @param string $email
	 * @return int|null
	 */
	public function find_contact_id_by_email( string $email ): ?int;

	/**
	 * READ-ONLY. Fetch a contact record by id (for existence/verification), or null.
	 * Must never mutate the contact.
	 *
	 * @param int $contact_id
	 * @return array|null
	 */
	public function get_contact( int $contact_id ): ?array;

	/**
	 * Create a NEW contact from a saved lead row. Called only when no contact was
	 * resolved read-only — creating a fresh record is not a mutation of existing
	 * CRM data. Returns the new contact id, or null on failure.
	 *
	 * @param array $lead
	 * @return int|null
	 */
	public function create_contact( array $lead ): ?int;

	/**
	 * Adopt-not-twin duplicate guard. Bounded (≤ $limit) read-only scan of the leads
	 * already attached to $contact_id for one that carries this batch's tag. Returns
	 * that lead's id when a prior attempt already created it (so the recovery adopts
	 * the id instead of creating a second lead), or null when none exists.
	 *
	 * @param int    $contact_id
	 * @param string $batch_tag
	 * @param int    $limit       Max leads to inspect (default 15).
	 * @return int|null
	 */
	public function find_existing_lead_for_contact( int $contact_id, string $batch_tag, int $limit = 15 ): ?int;

	/**
	 * Create a CRM lead for $contact_id, tagged with $batch_tag, from the saved lead
	 * row. Returns the new lead id, or null on a soft failure. May throw on a hard
	 * transport/auth error — the recovery mechanism catches and classifies it.
	 *
	 * @param array  $lead
	 * @param int    $contact_id
	 * @param string $batch_tag
	 * @return int|null
	 */
	public function create_lead( array $lead, int $contact_id, string $batch_tag ): ?int;
}
