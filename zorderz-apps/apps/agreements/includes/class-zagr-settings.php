<?php
/**
 * ZAGR_Settings - the mode, the business facts an agreement prints, lead times, the template in use and its
 * approval, and the readiness list. [CORE mechanism; the values are the business's]
 *
 * Mode: off | test | on, default test (only estimates marked as tests get agreements). "on" is refused until every
 * item the readiness list needs for "on" is done. In test, an agreement needs only what signing itself needs (the
 * key, a template that loads, a legal pack, the email for cancellations, the help phone, and what the pack needs
 * even to walk the flow); a fact still missing prints "Not set (test mode)", so the flow can be walked before every
 * fact is in.
 *
 * Facts: stored in zagr_facts. Empty ones fall back to the Business Profile where the profile has the same thing (the
 * legal name, the postal address, the main phone). The email for cancellations and a license number never fall back:
 * they must be entered here on purpose.
 *
 * Lead times: the business's own groups of work (zagr_lead_groups), each with the days after the cancellation period
 * ends before work can start (min to max) and the days the work takes (per unit, or once). An estimate names the
 * groups on it; the agreement prints approximate start and completion dates from them. No groups: the dates read
 * "To be confirmed".
 *
 * The template's approval is recorded per template version WITH the template files' SHA-256 at the time, and with the
 * name of the person who approved it (a lawyer, usually; whoever the business says): a template edited after approval
 * is no longer approved.
 *
 * @package Zorderz\Agreements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAGR_Settings {

	const OPT_MODE     = 'zagr_mode';
	const OPT_FACTS    = 'zagr_facts';
	const OPT_LEAD     = 'zagr_lead_groups';
	const OPT_TEMPLATE = 'zagr_template';
	const OPT_TEST     = 'zagr_test_fixture';

	const MODES            = array( 'off', 'test', 'on' );
	const DEFAULT_TEMPLATE = 'starter-1';

	/** The facts, all empty: nothing about any business is assumed. */
	const DEFAULT_FACTS = array(
		'legal_name'               => '',
		'address_lines'            => array(),
		'license'                  => '',
		'cancel_email'             => '',
		'help_phone'               => '',
		'substantial_commencement' => '',
		'cgl_insurer'              => '',
		'cgl_phone'                => '',
		'subcontractors'           => '',   // 'yes' | 'no' | '' (not answered: never assumed)
	);

	/** The neutral name a test estimate's customer goes by on test pages and alerts (zagr_test_fixture). */
	const DEFAULT_TEST_FIXTURE = 'Test Customer';

	public static function mode(): string {
		$m = (string) get_option( self::OPT_MODE, 'test' );
		return in_array( $m, self::MODES, true ) ? $m : 'test';
	}

	/** Set the mode; "on" only when the readiness list is complete. { ok, message } */
	public static function set_mode( string $mode ): array {
		if ( ! in_array( $mode, self::MODES, true ) ) {
			return array( 'ok' => false, 'message' => __( 'That isn\'t a mode.', 'zorderz' ) );
		}
		if ( 'on' === $mode ) {
			$missing = self::missing_for( 'on' );
			if ( $missing ) {
				return array( 'ok' => false, 'message' => __( 'Agreements can\'t go to customers yet. Still needed:', 'zorderz' ) . ' ' . self::oxford( $missing ) . '.' );
			}
		}
		update_option( self::OPT_MODE, $mode );
		$words = array(
			'off'  => __( 'Agreements are off.', 'zorderz' ),
			'test' => __( 'Test mode: only test estimates get agreements.', 'zorderz' ),
			'on'   => __( 'Agreements are on for customers.', 'zorderz' ),
		);
		return array( 'ok' => true, 'message' => $words[ $mode ] );
	}

	/** The name test pages and alerts use for a test customer. */
	public static function test_fixture(): string {
		$n = trim( (string) get_option( self::OPT_TEST, '' ) );
		return '' !== $n ? $n : self::DEFAULT_TEST_FIXTURE;
	}

	/* ---------------------------------------------------------------- facts */

	/** The facts as stored (the form shows these). */
	public static function stored_facts(): array {
		$f   = get_option( self::OPT_FACTS, array() );
		$f   = is_array( $f ) ? $f : array();
		$out = self::DEFAULT_FACTS;
		foreach ( $out as $k => $v ) {
			if ( array_key_exists( $k, $f ) ) {
				$out[ $k ] = $f[ $k ];
			}
		}
		$out['address_lines'] = array_values( array_filter( array_map( 'trim', array_map( 'strval', (array) $out['address_lines'] ) ), 'strlen' ) );
		foreach ( array_keys( self::DEFAULT_FACTS ) as $k ) {
			if ( 'address_lines' !== $k ) {
				$out[ $k ] = trim( (string) $out[ $k ] );
			}
		}
		if ( ! in_array( $out['subcontractors'], array( 'yes', 'no' ), true ) ) {
			$out['subcontractors'] = '';
		}
		return $out;
	}

	/** What the Business Profile offers for a fact left empty here. */
	public static function profile_fallbacks(): array {
		if ( ! class_exists( 'ZDZ_Business_Profile' ) ) {
			return array();
		}
		$a     = (array) ZDZ_Business_Profile::get( 'contact.address', array() );
		$line2 = trim( trim( (string) ( $a['locality'] ?? '' ) ) . ( '' !== trim( (string) ( $a['region'] ?? '' ) ) ? ', ' . trim( (string) $a['region'] ) : '' ) . ' ' . trim( (string) ( $a['postal'] ?? '' ) ) );
		$lines = array_values( array_filter( array( trim( (string) ( $a['street'] ?? '' ) ), trim( $line2, ' ,' ) ), 'strlen' ) );
		return array(
			'legal_name'    => trim( (string) ZDZ_Business_Profile::get( 'identity.legal_name', '' ) ),
			'address_lines' => $lines,
			'help_phone'    => trim( (string) ZDZ_Business_Profile::get( 'contact.phone', '' ) ),
		);
	}

	/** The facts as an agreement prints them: stored, else the profile's. */
	public static function facts(): array {
		$out = self::stored_facts();
		foreach ( self::profile_fallbacks() as $k => $v ) {
			if ( ( is_array( $out[ $k ] ) ? array() === $out[ $k ] : '' === $out[ $k ] ) && ! empty( $v ) ) {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	public static function save_facts( array $in ): void {
		$lines = $in['address_lines'] ?? array();
		if ( is_string( $lines ) ) {
			$lines = preg_split( '/\r\n|\r|\n/', $lines );
		}
		$subs = (string) ( $in['subcontractors'] ?? '' );
		update_option( self::OPT_FACTS, array(
			'legal_name'               => self::clean( $in['legal_name'] ?? '', 120 ),
			'address_lines'            => array_slice( array_values( array_filter( array_map( function ( $l ) { return self::clean( $l, 120 ); }, (array) $lines ), 'strlen' ) ), 0, 4 ),
			'license'                  => self::clean( $in['license'] ?? '', 30 ),
			'cancel_email'             => self::clean( $in['cancel_email'] ?? '', 120 ),
			'help_phone'               => self::clean( $in['help_phone'] ?? '', 30 ),
			'substantial_commencement' => self::clean( $in['substantial_commencement'] ?? '', 300 ),
			'cgl_insurer'              => self::clean( $in['cgl_insurer'] ?? '', 120 ),
			'cgl_phone'                => self::clean( $in['cgl_phone'] ?? '', 30 ),
			'subcontractors'           => in_array( $subs, array( 'yes', 'no' ), true ) ? $subs : '',
		) );
	}

	/* ---------------------------------------------------------------- lead-time groups */

	/** One group, cleaned: { id, label, min, max, install, per_unit } or null. Days are 0 to 365 or '' (blank). */
	public static function clean_group( $g ): ?array {
		if ( ! is_array( $g ) ) {
			return null;
		}
		$id = strtolower( trim( (string) ( $g['id'] ?? '' ) ) );
		if ( ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,29}$/', $id ) ) {
			return null;
		}
		$n = static function ( $v ) {
			return ( is_numeric( $v ) && (int) $v >= 0 && (int) $v <= 365 ) ? (int) $v : '';
		};
		$label = self::clean( $g['label'] ?? '', 60 );
		return array(
			'id'       => $id,
			'label'    => '' !== $label ? $label : $id,
			'min'      => $n( $g['min'] ?? '' ),
			'max'      => $n( $g['max'] ?? '' ),
			'install'  => $n( $g['install'] ?? '' ),
			'per_unit' => ! empty( $g['per_unit'] ),
		);
	}

	/** id => group. */
	public static function lead(): array {
		$l   = get_option( self::OPT_LEAD, array() );
		$out = array();
		foreach ( is_array( $l ) ? $l : array() as $g ) {
			$c = self::clean_group( $g );
			if ( null !== $c && ! isset( $out[ $c['id'] ] ) && count( $out ) < 20 ) {
				$out[ $c['id'] ] = $c;
			}
		}
		return $out;
	}

	/** At least one group, every group's days set, and each range running forward. */
	public static function lead_set(): bool {
		$all = self::lead();
		if ( ! $all ) {
			return false;
		}
		foreach ( $all as $l ) {
			if ( '' === $l['min'] || '' === $l['max'] || '' === $l['install'] || $l['max'] < $l['min'] ) {
				return false;
			}
		}
		return true;
	}

	/** $in: a list of groups (strings from the form are fine). */
	public static function save_lead( array $in ): void {
		$out = array();
		foreach ( $in as $g ) {
			$c = self::clean_group( $g );
			if ( null !== $c && ! isset( $out[ $c['id'] ] ) && count( $out ) < 20 ) {
				$out[ $c['id'] ] = $c;
			}
		}
		update_option( self::OPT_LEAD, array_values( $out ) );
	}

	/* ---------------------------------------------------------------- the template version */

	private static function template_opt(): array {
		$t = get_option( self::OPT_TEMPLATE, array() );
		return is_array( $t ) ? $t : array();
	}

	public static function template_version(): string {
		$v = (string) ( self::template_opt()['version'] ?? self::DEFAULT_TEMPLATE );
		return preg_match( '/^[A-Za-z0-9._-]{1,40}$/', $v ) ? $v : self::DEFAULT_TEMPLATE;
	}

	public static function set_template_version( string $v ): bool {
		if ( ! preg_match( '/^[A-Za-z0-9._-]{1,40}$/', $v ) || ! in_array( $v, ZAGR_Template::versions(), true ) ) {
			return false;
		}
		$t            = self::template_opt();
		$t['version'] = $v;
		update_option( self::OPT_TEMPLATE, $t );
		return true;
	}

	/** The approval recorded for $version: { version, files_hash, by, approver, at }, or null. */
	public static function approval( string $version ): ?array {
		$a = self::template_opt()['approvals'][ $version ] ?? null;
		return is_array( $a ) ? $a : null;
	}

	/**
	 * Record the approval of a template: its own step, confirmed, for the version in use and the exact files the
	 * screen showed ($files_hash), naming who approved it ($approver: the business's lawyer, as the business says).
	 * A version not in use, files changed since, no approver named, or no confirmation: refused. Choosing a version
	 * never approves it, and a Save never approves edited files. { ok, message }
	 */
	public static function approve_template( string $version, string $files_hash, int $uid, bool $confirmed, string $approver ): array {
		if ( ! ZAGR_People::is_admin( $uid ) ) {
			return array( 'ok' => false, 'message' => __( 'Only an administrator can record a template\'s approval.', 'zorderz' ) );
		}
		$approver = self::clean( $approver, 120 );
		if ( '' === $approver ) {
			return array( 'ok' => false, 'message' => __( 'Nothing was recorded. Name the person who approved these words.', 'zorderz' ) );
		}
		if ( ! $confirmed ) {
			return array( 'ok' => false, 'message' => __( 'Nothing was recorded. Tick the box to confirm that these exact words were approved.', 'zorderz' ) );
		}
		if ( $version !== self::template_version() ) {
			return array( 'ok' => false, 'message' => __( 'Nothing was recorded. Only the version in use can be approved; choose it and save first.', 'zorderz' ) );
		}
		try {
			$tpl = ZAGR_Template::load( $version );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'message' => __( 'That template doesn\'t load, so it can\'t be approved:', 'zorderz' ) . ' ' . $e->getMessage() );
		}
		if ( ! empty( $tpl['starter'] ) ) {
			return array( 'ok' => false, 'message' => __( 'Nothing was recorded. The starter template is a skeleton, not an agreement: have your lawyer write your terms in a template of your own.', 'zorderz' ) );
		}
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $files_hash ) || ! hash_equals( (string) $tpl['files_hash'], $files_hash ) ) {
			return array( 'ok' => false, 'message' => __( 'Nothing was recorded. The template\'s files changed since the page was drawn; look again, and approve only the words that were reviewed.', 'zorderz' ) );
		}
		$t                          = self::template_opt();
		$t['approvals'][ $version ] = array( 'version' => $version, 'files_hash' => $files_hash, 'by' => $uid, 'approver' => $approver, 'at' => ZAGR_Clock::utc() );
		update_option( self::OPT_TEMPLATE, $t );
		/* translators: 1: template version, 2: who approved it, 3: the first characters of the files' fingerprint */
		return array( 'ok' => true, 'message' => sprintf( __( 'Recorded: template %1$s approved by %2$s (files fingerprint %3$s).', 'zorderz' ), $version, $approver, substr( $files_hash, 0, 12 ) ) );
	}

	/** Take the approval of $version back. { ok, message } */
	public static function unapprove_template( string $version, int $uid ): array {
		if ( ! ZAGR_People::is_admin( $uid ) ) {
			return array( 'ok' => false, 'message' => __( 'Only an administrator can change a template\'s approval.', 'zorderz' ) );
		}
		$t = self::template_opt();
		unset( $t['approvals'][ $version ] );
		update_option( self::OPT_TEMPLATE, $t );
		/* translators: %s: template version */
		return array( 'ok' => true, 'message' => sprintf( __( 'The approval of template %s is taken back.', 'zorderz' ), $version ) );
	}

	/** Is $version (the one in use by default) approved, and are its files still the ones approved? */
	public static function template_approved( string $version = '' ): bool {
		$v = '' !== $version ? $version : self::template_version();
		$a = self::approval( $v );
		if ( ! $a ) {
			return false;
		}
		try {
			$tpl = ZAGR_Template::load( $v );
		} catch ( \Throwable $e ) {
			return false;
		}
		return empty( $tpl['starter'] ) && $v === (string) ( $a['version'] ?? $v ) && hash_equals( (string) ( $a['files_hash'] ?? '' ), $tpl['files_hash'] );
	}

	/** The template, loaded, or null. */
	private static function template_or_null( string $version ): ?array {
		try {
			return ZAGR_Template::load( $version );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/* ---------------------------------------------------------------- readiness */

	/** The packs the settings screen and the global switch check: the chosen pack, and the business's own state's. */
	public static function home_stack(): array {
		$region = '';
		if ( class_exists( 'ZDZ_Business_Profile' ) ) {
			$region = ZAGR_Pack::region_of( array( 'region' => (string) ZDZ_Business_Profile::get( 'contact.address.region', '' ) ) );
		}
		return ZAGR_Pack::stack_for( $region );
	}

	/**
	 * The readiness list for $mode: the app's own items, then the legal packs' items. With $doc (one agreement's frozen
	 * document) the packs are that agreement's and the per-agreement items count. Rows: { id, label, ok, needed,
	 * per_agreement, citation, pack, kind }. Labels only, never a value.
	 */
	public static function checklist( string $mode, ?array $doc = null ): array {
		$f       = null === $doc ? self::facts() : self::doc_facts( $doc );
		$version = null === $doc ? self::template_version() : (string) ( $doc['facts']['template_version'] ?? self::template_version() );
		$tpl     = self::template_or_null( $version );
		$stack   = null === $doc ? self::home_stack() : array_values( array_map( 'strval', (array) ( $doc['packs'] ?? array() ) ) );
		$packs   = $stack ? ZAGR_Pack::packs( $stack ) : null;
		$row     = static function ( string $id, string $label, bool $ok, array $needed_in, string $citation = '' ) use ( $mode ) {
			return array( 'id' => $id, 'label' => $label, 'ok' => $ok, 'needed' => in_array( $mode, $needed_in, true ), 'per_agreement' => false, 'citation' => $citation, 'pack' => '', 'kind' => 'core' );
		};
		$both = array( 'test', 'on' );
		$rows = array(
			$row( 'pack', __( 'a legal pack that loads (Business Profile, Legal pack)', 'zorderz' ), null !== $packs && array() !== $packs, $both ),
			$row( 'key', __( 'the agreements key', 'zorderz' ), ZAGR_Key::usable(), $both ),
			$row( 'template', __( 'a template that loads', 'zorderz' ), null !== $tpl, $both ),
			$row( 'cancel_email', __( 'the email address for cancellations', 'zorderz' ), ZAGR_Checklist::fact_ok( 'cancel_email', $f['cancel_email'] ?? '' ), $both ),
			$row( 'help_phone', __( 'the help phone', 'zorderz' ), ZAGR_Checklist::fact_ok( 'help_phone', $f['help_phone'] ?? '' ), $both ),
			$row( 'legal_name', __( 'the legal business name', 'zorderz' ), ZAGR_Checklist::fact_ok( 'legal_name', $f['legal_name'] ?? '' ), array( 'on' ) ),
			$row( 'address', __( 'the business address', 'zorderz' ), ZAGR_Checklist::fact_ok( 'address_lines', $f['address_lines'] ?? array() ), array( 'on' ) ),
			$row( 'complete', __( 'a template with no words still to come (marked "to come"): the starter template is only a skeleton', 'zorderz' ), null !== $tpl && empty( $tpl['has_todo'] ) && empty( $tpl['starter'] ), array( 'on' ) ),
			$row( 'approved', __( 'the recorded approval of the template in use', 'zorderz' ), self::template_approved( $version ), array( 'on' ) ),
		);
		if ( $packs ) {
			$rows = array_merge( $rows, ZAGR_Checklist::rows( $packs, $mode, array(
				'facts'             => $f,
				'template_uses'     => null !== $tpl ? (array) $tpl['uses'] : array(),
				'template_declares' => null !== $tpl ? (array) $tpl['statutory'] : array(),
				'lead_groups_ok'    => null === $doc ? self::lead_set() : self::doc_lead_set( $doc ),
				'doc'               => $doc,
			) ) );
		}
		return $rows;
	}

	/** An agreement's frozen facts in the shape the checklist reads. */
	private static function doc_facts( array $doc ): array {
		$f = (array) ( $doc['facts'] ?? array() );
		return array(
			'legal_name'               => (string) ( $f['company_name'] ?? '' ),
			'address_lines'            => (array) ( $f['address_lines'] ?? array() ),
			'license'                  => (string) ( $f['license'] ?? '' ),
			'cancel_email'             => (string) ( $f['cancel_email'] ?? '' ),
			'help_phone'               => (string) ( $f['help_phone'] ?? '' ),
			'substantial_commencement' => (string) ( $f['substantial_commencement'] ?? '' ),
			'cgl_insurer'              => (string) ( $f['cgl_insurer'] ?? '' ),
			'cgl_phone'                => (string) ( $f['cgl_phone'] ?? '' ),
			'subcontractors'           => (string) ( $f['subcontractors'] ?? '' ),
		);
	}

	/** The lead times frozen into an agreement cover every group it names. */
	private static function doc_lead_set( array $doc ): bool {
		$lead = (array) ( $doc['facts']['lead'] ?? array() );
		if ( ! $lead ) {
			return false;
		}
		foreach ( (array) ( $doc['families'] ?? array() ) as $g ) {
			$l = $lead[ $g['family'] ] ?? null;
			if ( ! is_array( $l ) || '' === ( $l['min'] ?? '' ) || '' === ( $l['max'] ?? '' ) || '' === ( $l['install'] ?? '' ) ) {
				return false;
			}
		}
		return true;
	}

	/** Labels of what $mode still needs (in list order); with $doc, for that agreement. */
	public static function missing_for( string $mode, ?array $doc = null ): array {
		if ( 'off' === $mode ) {
			return array();
		}
		return ZAGR_Checklist::missing( self::checklist( $mode, $doc ) );
	}

	/* ---------------------------------------------------------------- helpers */

	public static function clean( $s, int $max ): string {
		$s = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', (string) $s ) );
		$s = (string) preg_replace( '/\s+/u', ' ', $s );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}

	/** "a, b, and c" (Oxford comma). */
	public static function oxford( array $items ): string {
		$items = array_values( $items );
		$n     = count( $items );
		if ( 0 === $n ) {
			return '';
		}
		if ( 1 === $n ) {
			return $items[0];
		}
		if ( 2 === $n ) {
			return $items[0] . ' ' . __( 'and', 'zorderz' ) . ' ' . $items[1];
		}
		return implode( ', ', array_slice( $items, 0, -1 ) ) . ', ' . __( 'and', 'zorderz' ) . ' ' . $items[ $n - 1 ];
	}
}
