<?php
/**
 * ZEST_Added_Measurements - measurements added after the estimate was made.
 *
 * An estimate is sometimes made before anyone has measured: a price is quoted, the billing system gets the estimate,
 * and the CRM lead gets no sizes. The prep app cuts from the measurement block on the lead, so it has nothing to cut
 * from. "Add measurements" reads what the person types or says now, and puts one measurement block on THAT estimate's
 * lead. Nothing is written to the billing system: the estimate and any invoice stay exactly as they are, and the block
 * carries no price.
 *
 *   zest_add_measurements {id, text, step: preview}   the block as it would be written (nothing written)
 *   zest_add_measurements {id, text, step: apply, token}
 *                                                      the same block, written as a note on the estimate's lead, only
 *                                                      when the lead is positively this estimate's (lead_matches());
 *                                                      otherwise refused, never guessed.
 *
 * The block's markers are the prep app's own contract (ZPREP_CONTRACT_MEAS_MARKER / _MARKER2 when the prep app is here),
 * plus ADDED_MARKER, which says this block replaces any earlier one on the lead.
 *
 * The pure parts (note_item_lines(), build_note(), lead_matches(), ...) touch no service and are tested on their own.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Added_Measurements {

	/** Says this block replaces any earlier measurement block on the lead. */
	const ADDED_MARKER = 'MEASUREMENTS ADDED';

	/** The line the time the block was added is read from (the newest block wins). */
	const ADDED_AT_LABEL = 'Measurements added:';

	public static function init(): void {
		add_action( 'wp_ajax_zest_add_measurements', array( __CLASS__, 'ajax' ) );
	}

	/** The block's first line: the prep contract's markers, then this block's own. */
	public static function header(): string {
		$a = defined( 'ZPREP_CONTRACT_MEAS_MARKER' ) ? (string) constant( 'ZPREP_CONTRACT_MEAS_MARKER' ) : 'ZDZ Measurements';
		$b = defined( 'ZPREP_CONTRACT_MEAS_MARKER2' ) ? (string) constant( 'ZPREP_CONTRACT_MEAS_MARKER2' ) : 'FULL MEASUREMENTS';
		return '=== ' . $a . ' - ' . $b . ' - ' . self::ADDED_MARKER . ' ===';
	}

	/* ------------------------------------------------------------------ the block (pure) */

	/** A line that carries the address, the appointment or a reference, never a measured piece. */
	public static function is_metadata_line( array $item ): bool {
		$desc = (string) ( $item['description'] ?? '' );
		if ( class_exists( 'ZDZ_Doc_Conventions' ) && method_exists( 'ZDZ_Doc_Conventions', 'is_metadata_line' ) && ZDZ_Doc_Conventions::is_metadata_line( $desc, 0 ) ) {
			return true;
		}
		return (bool) preg_match( '/\b(location|property location|appt\.?\s*summary|reference\s*info)\b/i', $desc );
	}

	/**
	 * One line of text: newlines and runs of space become one space, so words from a model can never start a line of
	 * their own in the note (a second "Estimate #:" line, a fake header, a counted "x N" row). The multiplication sign
	 * is kept for the quantity this class appends.
	 */
	public static function one_line( string $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', str_replace( "\u{00D7}", 'x', $text ) ) );
	}

	/** Money amounts out: the block is not a price, and a figure in it would read as if it had been re-priced. */
	public static function strip_money( string $text ): string {
		if ( false === strpos( $text, '$' ) ) {
			return $text;
		}
		$out = preg_replace( '/\s*[@=]?\s*\$\s*\d[\d,]*(?:\.\d+)?/', '', $text );
		return trim( (string) preg_replace( '/[ \t]{2,}/', ' ', (string) $out ) );
	}

	/**
	 * The numbered lines: "N. <name> - <description> [<size>] × <qty>", no price; a line's own note on a row of its own
	 * ("   Note: ..."), and its team-only door-handle words ("   Team: ...") where the door-handle reader kept them.
	 *
	 * @return string[]
	 */
	public static function note_item_lines( array $items ): array {
		$lines = array();
		$n     = 0;
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || self::is_metadata_line( $item ) ) {
				continue;
			}
			$desc = self::one_line( self::strip_money( (string) ( $item['description'] ?? '' ) ) );
			if ( '' === $desc ) {
				continue;
			}
			if ( isset( $item['quantity'] ) && is_numeric( $item['quantity'] ) && (int) $item['quantity'] <= 0 ) {
				continue;   // explicitly counted as none: nothing to cut
			}
			$qty  = isset( $item['quantity'] ) && is_numeric( $item['quantity'] ) ? (int) $item['quantity'] : 1;
			$n++;
			$line = $n . '. ' . $desc;
			$sub  = self::one_line( self::strip_money( (string) ( $item['sub_description'] ?? '' ) ) );
			if ( '' !== $sub ) {
				$line .= ' - ' . $sub;
			}
			$dims = self::one_line( (string) ( $item['dimensions'] ?? ( $item['measurements'] ?? '' ) ) );
			if ( '' !== $dims ) {
				$line .= ' [' . $dims . ']';
			}
			$line .= " \u{00D7} " . $qty;
			$note  = self::one_line( self::strip_money( (string) ( $item['notes'] ?? '' ) ) );
			if ( '' !== $note ) {
				$line .= "\n   Note: " . $note;
			}
			$team = is_array( $item['door'] ?? null ) ? self::one_line( (string) ( $item['door']['text'] ?? '' ) ) : '';
			if ( '' !== $team ) {
				$line .= "\n   Team: " . $team;
			}
			$lines[] = $line;
		}
		return $lines;
	}

	/**
	 * The whole block, or '' when there is nothing measured to add (the caller refuses rather than post an empty block).
	 *
	 * @param array    $ctx   { est_num (required), customer_name, address, salesperson, invoice_num, added_by, added_at }
	 * @param array    $items the parsed lines
	 * @param string[] $image_urls photos taken with the measurements (gated addresses only)
	 */
	public static function build_note( array $ctx, array $items, array $image_urls = array() ): string {
		$est_num = self::one_line( (string) ( $ctx['est_num'] ?? '' ) );
		$rows    = self::note_item_lines( $items );
		if ( '' === $est_num || ! $rows ) {
			return '';
		}
		$out = array( self::header() );
		foreach ( array(
			'customer_name' => __( 'Customer:', 'zorderz' ),
			'address'       => __( 'Address:', 'zorderz' ),
		) as $k => $label ) {
			$v = self::one_line( (string) ( $ctx[ $k ] ?? '' ) );
			if ( '' !== $v ) {
				$out[] = $label . ' ' . $v;
			}
		}
		$out[] = 'Estimate #: ' . $est_num;
		$sp    = self::one_line( (string) ( $ctx['salesperson'] ?? '' ) );
		if ( '' !== $sp ) {
			$out[] = __( 'Salesperson:', 'zorderz' ) . ' ' . $sp;
		}
		$inv = self::one_line( (string) ( $ctx['invoice_num'] ?? '' ) );
		if ( '' !== $inv ) {
			$out[] = 'Invoice #: ' . $inv;
		}
		$at    = self::one_line( (string) ( $ctx['added_at'] ?? '' ) );
		$by    = self::one_line( (string) ( $ctx['added_by'] ?? '' ) );
		$out[] = self::ADDED_AT_LABEL . ' ' . ( '' !== $at ? $at : __( 'unknown time', 'zorderz' ) ) . ( '' !== $by ? ' ' . sprintf( __( 'by %s', 'zorderz' ), $by ) : '' );
		$out[] = __( '(Measured after the estimate was made. The estimate and any invoice were NOT changed, and there are no prices here. This block replaces any earlier measurement block on this lead.)', 'zorderz' );
		$out[] = '';
		$out[] = __( '-- LINE ITEMS (with measurements) --', 'zorderz' );
		foreach ( $rows as $l ) {
			$out[] = $l;
		}
		$urls = array_values( array_filter( array_map( 'strval', $image_urls ) ) );
		if ( $urls ) {
			$out[] = '';
			$out[] = __( '-- PHOTOS AND SKETCHES --', 'zorderz' );
			foreach ( $urls as $i => $u ) {
				$out[] = sprintf( __( 'Photo %d:', 'zorderz' ), $i + 1 ) . ' ' . self::one_line( $u );
			}
			$out[] = __( '(Measurement photos for the team, not the customer)', 'zorderz' );
		}
		return implode( "\n", $out );
	}

	/* ------------------------------------------------------------------ is it this estimate's lead? (pure) */

	/** Does this text name this estimate number? The number must end there: "#10" never matches "#1042". */
	public static function text_names_estimate( string $text, string $est_num ): bool {
		$est_num = trim( $est_num );
		if ( '' === $text || '' === $est_num || ! ctype_digit( $est_num ) ) {
			return false;
		}
		return (bool) preg_match( '/\b(?:estimate|est\.?)\s*(?:number|num|no\.?)?\s*#?\s*:?\s*0*' . preg_quote( ltrim( $est_num, '0' ), '/' ) . '(?!\d)/i', $text );
	}

	/** Every note body on a CRM lead, whatever the field is called. */
	public static function lead_note_bodies( array $lead ): array {
		$bodies = array();
		foreach ( (array) ( $lead['notes'] ?? array() ) as $note ) {
			if ( is_string( $note ) ) {
				$bodies[] = $note;
				continue;
			}
			if ( ! is_array( $note ) ) {
				continue;
			}
			foreach ( array( 'body', 'noteBody', 'htmlBody', 'note', 'text', 'content', 'noteMarkup' ) as $f ) {
				if ( ! empty( $note[ $f ] ) && is_string( $note[ $f ] ) ) {
					$bodies[] = $note[ $f ];
					break;
				}
			}
		}
		return $bodies;
	}

	/** The lead's estimate-number custom field ($fields: the names or ids it may be under), in either shape. */
	public static function lead_estimate_field( array $lead, array $fields ): string {
		$cf = $lead['customFields'] ?? array();
		if ( ! is_array( $cf ) || ! $fields ) {
			return '';
		}
		foreach ( $fields as $f ) {
			$f = (string) $f;
			if ( '' !== $f && isset( $cf[ $f ] ) && is_scalar( $cf[ $f ] ) ) {
				return trim( (string) $cf[ $f ] );
			}
		}
		foreach ( $cf as $v ) {
			if ( ! is_array( $v ) || ! isset( $v['value'] ) || ! is_scalar( $v['value'] ) ) {
				continue;
			}
			foreach ( $fields as $f ) {
				if ( '' !== (string) $f && ( 0 === strcasecmp( (string) ( $v['name'] ?? '' ), (string) $f ) || (string) ( $v['id'] ?? '' ) === (string) $f ) ) {
					return trim( (string) $v['value'] );
				}
			}
		}
		return '';
	}

	/** Contact ids on a lead. */
	public static function lead_contact_ids( array $lead ): array {
		$ids = array();
		foreach ( (array) ( $lead['contacts'] ?? array() ) as $c ) {
			if ( is_array( $c ) && isset( $c['id'] ) ) {
				$ids[] = (string) (int) $c['id'];
			} elseif ( is_scalar( $c ) ) {
				$ids[] = (string) (int) $c;
			}
		}
		return array_values( array_filter( $ids, static function ( $v ) { return '0' !== $v; } ) );
	}

	/**
	 * Is this lead positively the one for this estimate? A note is written only onto the same customer's record; when that
	 * cannot be shown, the write is refused, not guessed. Any one is enough: the lead's estimate-number field is this
	 * number; a note on the lead names this estimate; the lead carries the contact this app recorded for the estimate.
	 * The lead id alone is not evidence: it is the claim being checked. A field naming ANOTHER estimate refuses outright.
	 *
	 * @return array{ok:bool, why:string}
	 */
	public static function lead_matches( array $lead, string $est_num, string $contact_id = '', array $fields = array() ): array {
		$est_num = ltrim( trim( $est_num ), '0' );
		if ( '' === $est_num || ! $lead ) {
			return array( 'ok' => false, 'why' => 'no lead or no estimate number' );
		}
		$field = ltrim( self::lead_estimate_field( $lead, $fields ), '0#' );
		if ( '' !== $field ) {
			return $field === $est_num ? array( 'ok' => true, 'why' => 'estimate-number field' ) : array( 'ok' => false, 'why' => 'the lead names another estimate' );
		}
		foreach ( self::lead_note_bodies( $lead ) as $body ) {
			if ( self::text_names_estimate( $body, $est_num ) ) {
				return array( 'ok' => true, 'why' => 'a note names the estimate' );
			}
		}
		$contact_id = trim( $contact_id );
		if ( '' !== $contact_id && '0' !== $contact_id && in_array( (string) (int) $contact_id, self::lead_contact_ids( $lead ), true ) ) {
			return array( 'ok' => true, 'why' => 'same contact as the estimate' );
		}
		return array( 'ok' => false, 'why' => 'nothing on the lead ties it to this estimate' );
	}

	/** The custom field the estimate number is kept in on a lead: the CRM mapping's id and name. */
	public static function estimate_fields(): array {
		$map = function_exists( 'apply_filters' ) ? (array) apply_filters( 'zdz_crm_mappings', array() ) : array();
		$row = (array) ( $map['custom_fields']['billing_document_number'] ?? array() );
		return array_values( array_filter( array( (string) ( $row['external_id'] ?? '' ), (string) ( $row['name'] ?? '' ) ), 'strlen' ) );
	}

	/* ------------------------------------------------------------------ the endpoint */

	public static function ajax(): void {
		list( $uid, $row ) = ZEST_Access::load_owned( 'id', true );
		$text = isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in ZEST_Access::load_owned() above
		$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : 'preview'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in ZEST_Access::load_owned() above
		if ( '' === trim( $text ) ) {
			wp_send_json_error( array( 'message' => __( 'Type or say the measurements first.', 'zorderz' ) ) );
		}
		$est_num = (string) ( $row['billing_doc_num'] ?? '' );
		if ( '' === $est_num ) {
			wp_send_json_error( array( 'message' => __( 'This estimate has no number yet, so measurements can\'t be added to it.', 'zorderz' ) ) );
		}
		// the words are parsed as an operator's stub would be: no prices are worked out, none are kept
		$engine = new ZEST_Estimate_Engine();
		$parsed = $engine->parse( $text, array( 'is_operator_mode' => true, 'is_new_estimate' => false, 'user_id' => $uid ) );
		if ( empty( $parsed['ok'] ) ) {
			wp_send_json_error( array( 'message' => '' !== (string) ( $parsed['error'] ?? '' ) ? (string) $parsed['error'] : __( 'The measurements could not be read.', 'zorderz' ) ) );
		}
		$items = (array) ( $parsed['estimate']['line_items'] ?? array() );
		$ctx   = array(
			'est_num'       => $est_num,
			'customer_name' => (string) ( $row['customer_name'] ?? '' ),
			'address'       => trim( (string) ( $row['customer_street'] ?? '' ) . ', ' . (string) ( $row['customer_city'] ?? '' ), ', ' ),
			'salesperson'   => (string) ( $row['salesperson'] ?? '' ),
			'added_by'      => class_exists( 'ZEST_Salesperson' ) ? ZEST_Salesperson::initials_of( $uid ) : '',
			'added_at'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);
		$note = self::build_note( $ctx, $items );
		if ( '' === $note ) {
			wp_send_json_error( array( 'message' => __( 'Nothing measured was found in those words, so nothing was added.', 'zorderz' ) ) );
		}
		$note  = class_exists( 'ZEST_Sealed' ) ? ZEST_Sealed::cut( $note ) : $note;
		$token = hash( 'sha256', (int) $row['id'] . '|' . $text );
		if ( 'apply' !== $step ) {
			wp_send_json_success( array( 'note' => $note, 'token' => $token, 'lines' => count( self::note_item_lines( $items ) ) ) );
		}
		if ( ! hash_equals( $token, isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in ZEST_Access::load_owned() above
			wp_send_json_error( array( 'message' => __( 'The words changed since the preview. Look at it again, then add it.', 'zorderz' ) ) );
		}
		$client  = class_exists( 'ZEST_Nutshell' ) ? ZEST_Nutshell::client() : null;
		$lead_id = (int) preg_replace( '/\D/', '', (string) ( $row['crm_lead_id'] ?? '' ) );
		if ( ! $client || $lead_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'This estimate has no CRM lead the app knows of, so the measurements were not added. Nothing changed.', 'zorderz' ) ) );
		}
		$lead  = method_exists( $client, 'read_lead' ) ? $client->read_lead( $lead_id ) : null;
		$match = is_array( $lead ) ? self::lead_matches( $lead, $est_num, (string) ( $row['crm_contact_id'] ?? '' ), self::estimate_fields() ) : array( 'ok' => false, 'why' => 'lead not read' );
		if ( empty( $match['ok'] ) ) {
			error_log( 'Zorderz Estimates: measurements for estimate id ' . (int) $row['id'] . ' not added: ' . $match['why'] . '.' );
			wp_send_json_error( array( 'message' => __( 'The CRM lead could not be shown to be this estimate\'s, so the measurements were not added. Nothing changed.', 'zorderz' ) ) );
		}
		$body = class_exists( 'ZDZ_Answer_Authority' ) && method_exists( 'ZDZ_Answer_Authority', 'sanitize_outbound' ) ? ZDZ_Answer_Authority::sanitize_outbound( $note ) : $note;
		$res  = $client->add_note( array( 'entity' => array( 'entityType' => 'Leads', 'id' => $lead_id ), 'note' => $body ) );
		if ( null === $res || false === $res ) {
			wp_send_json_error( array( 'message' => __( 'The CRM did not take the note just now. Try again in a minute.', 'zorderz' ) ) );
		}
		wp_send_json_success( array( 'added' => true, 'message' => __( 'The measurements are on the lead. The estimate was not changed.', 'zorderz' ) ) );
	}
}
