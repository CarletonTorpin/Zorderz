<?php
/**
 * ZANA_Fact_Starters — blank entries for the business's facts that the chat checks a document against.
 *
 * A document that states facts (ZANA_Answer_Rules, "documents that state facts") is checked against the business's own
 * standing: its legal names, its registrations and licences, and who may sign for it. Those are the business's facts,
 * typed by the business on Zorderz → Chat → The business's facts. Core ships none of them. What it ships is two blank
 * entries, each a list of field labels with nothing after them:
 *
 *   business-entities      legal names, kind of entity, registrations, licences, insurance
 *   business-signatories   who may sign and in what role, who reads a document before it leaves the business
 *
 * Choosing one fills the "add a fact" form with its labels. Nothing is stored until the administrator saves it, and a
 * label left as it came (nothing typed after it) is dropped at save, so an entry never states an empty field. Both are
 * for the operator level and above. A business or its pack adds its own blank entries on the filter
 * `zana_fact_starters` ( id => { category, tier_limit, label, fields: [ string ] } ).
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Fact_Starters {

	const FIELDS_MAX = 20;

	/** Every blank entry: id => { id, category, tier_limit, label, fields[] }. Field labels only; never a value. */
	public static function all(): array {
		$core = array(
			'business-entities'    => array(
				'category'   => 'business',
				'tier_limit' => 'operator+',
				'label'      => __( 'Legal entities, registrations and licences', 'zorderz' ),
				'fields'     => array(
					__( 'Legal name of the business, exactly as registered', 'zorderz' ),
					__( 'Kind of entity', 'zorderz' ),
					__( 'Registration number, and where it is registered', 'zorderz' ),
					__( 'Trading names it uses', 'zorderz' ),
					__( 'Licences it holds (name, class, number, who holds each)', 'zorderz' ),
					__( 'Insurance on record (kind, insurer, policy number)', 'zorderz' ),
					__( 'Related businesses, if any (legal name, kind, registration, what each does)', 'zorderz' ),
					__( 'Always looked up live before it is stated (status, expiry dates)', 'zorderz' ),
				),
			),
			'business-signatories' => array(
				'category'   => 'people',
				'tier_limit' => 'operator+',
				'label'      => __( 'Who signs for the business', 'zorderz' ),
				'fields'     => array(
					__( 'Who may sign for the business, and in what role', 'zorderz' ),
					__( 'Who signs payroll and employment papers', 'zorderz' ),
					__( 'Who signs when the usual signer is away', 'zorderz' ),
					__( 'Who reads a document before it leaves the business', 'zorderz' ),
				),
			),
		);
		$more = function_exists( 'apply_filters' ) ? apply_filters( 'zana_fact_starters', array() ) : array();
		$out  = array();
		foreach ( $core + ( is_array( $more ) ? $more : array() ) as $id => $s ) {
			$id = substr( (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $id ) ), 0, 64 );
			if ( '' === $id || ! is_array( $s ) ) {
				continue;
			}
			$fields = array();
			foreach ( (array) ( $s['fields'] ?? array() ) as $f ) {
				$f = is_string( $f ) ? self::label( $f ) : '';
				if ( '' !== $f ) {
					$fields[] = $f;
				}
			}
			$label = is_string( $s['label'] ?? null ) ? self::label( $s['label'] ) : '';
			if ( '' === $label || ! $fields ) {
				continue;
			}
			$out[ $id ] = array(
				'id'         => $id,
				'category'   => in_array( $s['category'] ?? '', ZANA_Company_Facts::CATEGORIES, true ) ? (string) $s['category'] : 'business',
				'tier_limit' => in_array( $s['tier_limit'] ?? '', ZANA_Company_Facts::TIER_LIMITS, true ) ? (string) $s['tier_limit'] : 'operator+',
				'label'      => $label,
				'fields'     => array_slice( array_values( array_unique( $fields ) ), 0, self::FIELDS_MAX ),
			);
		}
		return $out;
	}

	/** One line of plain words, no colon at its end (the form writes "label: "). */
	private static function label( string $s ): string {
		$s = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $s ) ) );
		return mb_substr( rtrim( $s, ": \t" ), 0, 160 );
	}

	public static function get( string $id ): ?array {
		return self::all()[ $id ] ?? null;
	}

	/** The form's text for a blank entry: one "label: " a line, nothing after any of them. '' for an unknown id. */
	public static function template( string $id ): string {
		$s = self::get( $id );
		if ( null === $s ) {
			return '';
		}
		return implode( "\n", array_map( static function ( $f ) {
			return $f . ': ';
		}, $s['fields'] ) );
	}

	/**
	 * What was typed into a blank entry, without the labels left as they came. A line that is one of the entry's own
	 * labels with nothing after it is dropped; every other line stays as typed. For an id that is no blank entry the
	 * text comes back unchanged.
	 */
	public static function filled( string $id, string $value ): string {
		$s = self::get( $id );
		if ( null === $s ) {
			return $value;
		}
		$blank = array();
		foreach ( $s['fields'] as $f ) {
			$blank[ self::fold( $f ) ] = true;
		}
		$keep = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $value ) as $line ) {
			if ( '' !== trim( $line ) && isset( $blank[ self::fold( $line ) ] ) ) {
				continue;
			}
			$keep[] = rtrim( $line );
		}
		return trim( implode( "\n", $keep ) );
	}

	/** A line as it is compared: lower case, one space between words, no colon or space at its end. */
	private static function fold( string $line ): string {
		$line = trim( (string) preg_replace( '/\s+/', ' ', $line ) );
		$line = rtrim( $line, ": \t" );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $line ) : strtolower( $line );
	}

	/**
	 * The entry chosen on the settings screen (?zana_fact_starter=<id>), or null. Reading it changes nothing: it only
	 * chooses which labels fill the form, and the save that follows is checked on its own.
	 */
	public static function chosen(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: picks which blank labels fill the form; the save has its own nonce
		$id = isset( $_GET['zana_fact_starter'] ) ? sanitize_key( wp_unslash( (string) $_GET['zana_fact_starter'] ) ) : '';
		return '' !== $id ? self::get( $id ) : null;
	}

	/** The "Start from" line above the form: one link for each entry the business has not saved yet. Escaped HTML. */
	public static function links_html( string $page_url ): string {
		$links = array();
		foreach ( self::all() as $id => $s ) {
			if ( ZANA_Company_Facts::get( $id ) ) {
				continue;   // already one of the business's facts
			}
			$links[] = '<a href="' . esc_url( add_query_arg( 'zana_fact_starter', $id, $page_url ) ) . '">' . esc_html( $s['label'] ) . '</a>';
		}
		if ( ! $links ) {
			return '';
		}
		return '<p>' . esc_html__( 'Start from a blank entry (labels only; nothing is saved until you fill it in and save):', 'zorderz' ) . ' ' . implode( ' · ', $links ) . '</p>';
	}
}
