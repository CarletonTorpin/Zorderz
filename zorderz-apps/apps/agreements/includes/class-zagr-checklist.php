<?php
/**
 * ZAGR_Checklist - what still holds an agreement back from "ready to sign", item by item. [CORE]
 *
 * Each legal pack lists what its law needs before a customer signs (checklist.json). An item is one of:
 *
 *   fact               a business fact in Settings (the email for cancellations, the help phone, a license number…)
 *   template_uses      the template in use prints a placeholder (the pack's notice, the date signed…)
 *   template_declares  the template's manifest declares it carries a statutory block the pack can't supply itself
 *                      (its "statutory" list); the business's lawyer writes those words
 *   lead_groups        lead times are set, so approximate dates can be printed (and, for one agreement, it names them)
 *   answer             a staff answer the pack asks for, given on this agreement (yes or no; never assumed)
 *   job_region         the job's state could be read from its address, so the right state layer applies
 *
 * needed_in says in which mode an item holds things back: "test" (the flow can't be walked without it) and/or "on"
 * (customers can't sign without it). An item about one agreement (answer, job_region) only holds back that agreement;
 * on the settings screen it shows as asked per agreement.
 *
 * Pure: everything comes in through $ctx, so the decisions are testable without WordPress.
 *
 * @package Zorderz\Agreements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAGR_Checklist {

	/** Does one fact hold a usable value? */
	public static function fact_ok( string $key, $v ): bool {
		if ( is_array( $v ) ) {
			return (bool) array_filter( array_map( 'trim', array_map( 'strval', $v ) ), 'strlen' );
		}
		$v = trim( (string) $v );
		switch ( $key ) {
			case 'cancel_email':
				return '' !== $v && (bool) preg_match( '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $v );
			case 'help_phone':
				return (bool) preg_match( '/\d{3}.*\d{4}/', $v );
			case 'subcontractors':
				return in_array( $v, array( 'yes', 'no' ), true );
			case 'license':
				return (bool) preg_match( '/^[A-Za-z0-9][A-Za-z0-9 .\/-]{2,29}$/', $v );
			default:
				return '' !== $v;
		}
	}

	/**
	 * The rows for a set of loaded packs in $mode. $ctx:
	 *   facts             key => value (ZAGR_Settings::facts())
	 *   template_uses     placeholders the template in use prints
	 *   template_declares the template manifest's "statutory" list
	 *   lead_groups_ok    lead times are set for every group
	 *   doc               one agreement's frozen document, or null for the settings screen
	 * Each row: { id, label, ok, needed, per_agreement, citation, pack, kind }.
	 */
	public static function rows( array $packs, string $mode, array $ctx ): array {
		$facts = (array) ( $ctx['facts'] ?? array() );
		$uses  = (array) ( $ctx['template_uses'] ?? array() );
		$decl  = (array) ( $ctx['template_declares'] ?? array() );
		$doc   = isset( $ctx['doc'] ) && is_array( $ctx['doc'] ) ? $ctx['doc'] : null;
		$out   = array();
		$seen  = array();
		foreach ( $packs as $p ) {
			foreach ( (array) ( $p['checklist'] ?? array() ) as $it ) {
				$id = (string) $it['id'];
				if ( isset( $seen[ $id ] ) ) {
					continue;
				}
				$seen[ $id ] = true;
				$kind        = (string) ( $it['kind'] ?? '' );
				$key         = (string) ( $it['key'] ?? '' );
				$per         = false;
				switch ( $kind ) {
					case 'fact':
						$ok = self::fact_ok( $key, $facts[ $key ] ?? '' );
						break;
					case 'template_uses':
						$ok = in_array( $key, $uses, true );
						break;
					case 'template_declares':
						$ok = in_array( $key, $decl, true );
						break;
					case 'lead_groups':
						$ok = ! empty( $ctx['lead_groups_ok'] ) && ( null === $doc || ! empty( $doc['families'] ) );
						break;
					case 'answer':
						$per = true;
						$ok  = null === $doc || in_array( (string) ( $doc['answers'][ $key ] ?? '' ), array( 'yes', 'no' ), true );
						break;
					case 'job_region':
						$per = true;
						$ok  = null === $doc || '' !== (string) ( $doc['region'] ?? '' );
						break;
					default:
						$ok = false;   // an item kind this version doesn't know never passes
				}
				$out[] = array(
					'id'            => $id,
					'label'         => (string) $it['label'],
					'ok'            => $ok,
					'needed'        => in_array( $mode, (array) ( $it['needed_in'] ?? array( 'on' ) ), true ),
					'per_agreement' => $per,
					'citation'      => (string) ( $it['citation'] ?? '' ),
					'pack'          => (string) ( $it['pack'] ?? '' ),
					'kind'          => $kind,
				);
			}
		}
		return $out;
	}

	/** The labels of the rows that are needed and not met, in order. */
	public static function missing( array $rows ): array {
		$out = array();
		foreach ( $rows as $r ) {
			if ( ! empty( $r['needed'] ) && empty( $r['ok'] ) ) {
				$out[] = (string) $r['label'];
			}
		}
		return array_values( array_unique( $out ) );
	}
}
