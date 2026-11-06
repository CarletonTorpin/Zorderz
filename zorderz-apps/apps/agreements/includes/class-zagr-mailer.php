<?php
/**
 * ZAGR_Mailer - the emails, plain text through wp_mail, from the Business Profile's "documents" sender. [CORE]
 *
 *   the code            to the buyer's address on the estimate, never anywhere else
 *   the signed copy     to the buyer, the PDF attached, at the moment of signing ("copy received" when wp_mail takes it)
 *   the cancellation    to the cancellations address with what the business must do now and the record's
 *                       fingerprint, and a confirmation to the buyer
 *   the change order    to the buyer, its PDF attached
 *   codes held back     to the cancellations address: codes paused, or many codes asked for
 * Replies go to the cancellations address. No link into the billing system, ever.
 *
 * The PDF is attached from memory (PHPMailer's addStringAttachment, through phpmailer_init), so no plaintext copy is
 * ever written to disk. An email that says its PDF is attached never goes without it: if PHPMailer refuses the
 * attachment, the hook clears the recipients, so PHPMailer sends nothing; and when wp_mail isn't WordPress's own (a
 * mail plugin that replaces it may never run phpmailer_init), an email with a PDF isn't sent at all. Either way it
 * counts as not sent, and the signed copy waits on the page.
 *
 * @package Zorderz\Agreements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAGR_Mailer {

	/** The sender for agreement emails: the profile's "documents" sender, else the legal name and the site's address. */
	public static function from_name(): string {
		$n = class_exists( 'ZDZ_Business_Profile' ) ? (string) ( ZDZ_Business_Profile::sender( 'documents' )['name'] ?? '' ) : '';
		if ( '' === $n ) {
			$n = ZAGR_Strings::company();
		}
		return (string) apply_filters( 'zagr_email_from_name', $n );
	}

	public static function from_email(): string {
		$e = class_exists( 'ZDZ_Business_Profile' ) ? (string) ( ZDZ_Business_Profile::sender( 'documents' )['email'] ?? '' ) : '';
		if ( '' === $e || ! is_email( $e ) ) {
			$e = (string) get_option( 'admin_email' );
		}
		return (string) apply_filters( 'zagr_email_from_email', $e );
	}

	/**
	 * Send one plain-text email. $pdf: [ bytes, file name ] or null. True when wp_mail accepted it.
	 */
	public static function send( string $to, string $subject, string $body, ?array $pdf = null ): bool {
		if ( ! is_email( $to ) ) {
			return false;
		}
		$facts   = ZAGR_Settings::facts();
		$headers = array( 'From: ' . self::from_name() . ' <' . self::from_email() . '>' );
		if ( is_email( $facts['cancel_email'] ) ) {
			$headers[] = 'Reply-To: ' . $facts['cancel_email'];
		}
		$attached = false;
		$attach   = null;
		if ( $pdf && ! self::core_mailer() ) {
			return false;   // no way to know the PDF would be attached
		}
		if ( $pdf ) {
			$name   = (string) preg_replace( '/[^A-Za-z0-9._-]/', '-', (string) $pdf[1] );
			$bytes  = (string) $pdf[0];
			$attach = function ( $mailer ) use ( $bytes, $name, &$attached ) {
				if ( is_object( $mailer ) && method_exists( $mailer, 'addStringAttachment' ) ) {
					try {
						$attached = false !== $mailer->addStringAttachment( $bytes, $name, 'base64', 'application/pdf' );
					} catch ( \Throwable $e ) {
						$attached = false;
					}
				}
				if ( ! $attached && is_object( $mailer ) && method_exists( $mailer, 'clearAllRecipients' ) ) {
					$mailer->clearAllRecipients();   // PHPMailer then sends nothing (no recipients)
				}
			};
			add_action( 'phpmailer_init', $attach, 99 );
		}
		$plain = function () { return 'text/plain'; };
		add_filter( 'wp_mail_content_type', $plain, 99 );
		try {
			$ok = (bool) wp_mail( $to, $subject, $body, $headers );
		} catch ( \Throwable $e ) {
			$ok = false;
		}
		remove_filter( 'wp_mail_content_type', $plain, 99 );
		if ( null !== $attach ) {
			remove_action( 'phpmailer_init', $attach, 99 );
		}
		return $ok && ( null === $pdf || $attached );
	}

	/**
	 * Is wp_mail WordPress's own (wp-includes/pluggable.php), which always runs phpmailer_init before it sends? Filter
	 * zagr_mailer_is_core for a mail plugin known to run it (one that keeps WordPress's wp_mail passes as it is).
	 */
	public static function core_mailer(): bool {
		$core = false;
		try {
			$f    = (string) ( new ReflectionFunction( 'wp_mail' ) )->getFileName();
			$core = '' !== $f && 'pluggable.php' === basename( $f ) && 'wp-includes' === basename( dirname( $f ) );
		} catch ( \Throwable $e ) {
			$core = false;
		}
		return (bool) apply_filters( 'zagr_mailer_is_core', $core );
	}

	/** The contractor's legal name, as frozen in the agreement; Settings' (or the profile's) when the agreement has none. */
	private static function company( array $facts ): string {
		$c = trim( (string) ( $facts['company_name'] ?? '' ) );
		return '' !== $c ? $c : ZAGR_Strings::company();
	}

	private static function address( array $facts ): string {
		return $facts['address_lines'] ? implode( ', ', $facts['address_lines'] ) : 'the address on your agreement';
	}

	public static function code( string $to, string $code, array $facts ): bool {
		return self::send( $to, ZAGR_Strings::t( 'mail_code_subject', array( 'company' => self::company( $facts ) ) ), ZAGR_Strings::t( 'mail_code_body', array(
			'code' => $code, 'phone' => $facts['help_phone'], 'company' => self::company( $facts ),
		) ) );
	}

	public static function signed_copy( string $to, string $pdf, string $file, array $facts, string $signed_long, string $cancel_by_long, string $url ): bool {
		return self::send( $to, ZAGR_Strings::t( 'mail_signed_subject', array( 'company' => self::company( $facts ) ) ), ZAGR_Strings::t( 'mail_signed_body', array(
			'signed' => $signed_long, 'cancel_by' => $cancel_by_long, 'cancel_email' => $facts['cancel_email'],
			'address' => self::address( $facts ), 'url' => $url, 'phone' => $facts['help_phone'], 'company' => self::company( $facts ),
		) ), array( $pdf, $file ) );
	}

	public static function change_copy( string $to, string $pdf, string $file, array $facts, string $signed_long, string $url ): bool {
		return self::send( $to, ZAGR_Strings::t( 'mail_change_subject', array( 'company' => self::company( $facts ) ) ), ZAGR_Strings::t( 'mail_change_body', array(
			'signed' => $signed_long, 'url' => $url, 'phone' => $facts['help_phone'], 'company' => self::company( $facts ),
		) ), array( $pdf, $file ) );
	}

	private static function staff_to( array $facts ): string {
		return is_email( $facts['cancel_email'] ) ? $facts['cancel_email'] : (string) get_option( 'admin_email' );
	}

	/**
	 * The cancellations address, at once, with what the business must do now (the refund time from the agreement's legal
	 * packs) and the record's fingerprint after the cancellation (a copy of the head outside the database).
	 */
	public static function cancel_staff( array $facts, string $number, int $client_id, string $when, string $how, bool $test, array $head = array( 0, '' ) ): bool {
		return self::send( self::staff_to( $facts ), ZAGR_Strings::t( 'mail_staff_subject', array( 'number' => $number ) ), ZAGR_Strings::t( 'mail_staff_body', array(
			'number' => $number, 'client' => $client_id, 'when' => $when, 'how' => ZAGR_Strings::t( 'how_' . $how ),
			'head' => (string) $head[1], 'steps' => (int) $head[0],
			'test' => $test ? "\n\n" . ZAGR_Strings::t( 'alert_test' ) : '', 'refund' => ZAGR_Strings::refund_words( (array) ( $facts['packs'] ?? array() ) ),
		) ) );
	}

	/**
	 * The customer, when the agreement's cap stops sending codes: why, and (signed) how to cancel without one, by email
	 * or letter.
	 */
	public static function codes_paused( string $to, array $facts, bool $signed, string $cancel_by_long ): bool {
		$v = array(
			'company' => self::company( $facts ), 'phone' => $facts['help_phone'], 'cancel_email' => $facts['cancel_email'],
			'address' => self::address( $facts ), 'deadline' => '' !== $cancel_by_long ? ZAGR_Strings::t( 'mail_paused_deadline', array( 'cancel_by' => $cancel_by_long ) ) : '',
		);
		return self::send( $to, ZAGR_Strings::t( 'mail_paused_subject', $v ), ZAGR_Strings::t( $signed ? 'mail_paused_signed' : 'mail_paused_unsigned', $v ) );
	}

	/** Codes held back on an agreement: $kind locked (too many wrong codes from one place), limited (too many asked for), or ceiling (too many wrong codes today from places no code was used from). */
	public static function codes_staff( string $kind, array $facts, string $number, int $client_id, string $purpose, string $until_long, bool $test ): bool {
		$what = array( 'sign' => 'signing the agreement', 'change' => 'signing the change order', 'manage' => 'opening the signed copy and cancelling online' )[ $purpose ] ?? 'this agreement';
		$k    = array( 'locked' => 'mail_codes_locked', 'ceiling' => 'mail_codes_ceiling' )[ $kind ] ?? 'mail_codes_limited';
		return self::send( self::staff_to( $facts ), ZAGR_Strings::t( $k . '_subject', array( 'number' => $number ) ), ZAGR_Strings::t( $k . '_body', array(
			'number' => $number, 'client' => $client_id, 'what' => $what, 'until' => $until_long, 'n' => 'ceiling' === $kind ? ZAGR_Codes::WRONG_PER_DAY : ZAGR_Codes::LOCK_AFTER,
			'per_hour' => ZAGR_Codes::PER_HOUR, 'per_day' => ZAGR_Codes::PER_DAY,
			'test' => $test ? "\n\n" . ZAGR_Strings::t( 'alert_test' ) : '',
		) ) );
	}

	public static function cancel_buyer( string $to, array $facts, string $number, string $when ): bool {
		return self::send( $to, ZAGR_Strings::t( 'mail_cancel_subject', array( 'company' => self::company( $facts ) ) ), ZAGR_Strings::t( 'mail_cancel_body', array(
			'when' => $when, 'number' => $number, 'phone' => $facts['help_phone'], 'company' => self::company( $facts ), 'refund' => ZAGR_Strings::refund_words( (array) ( $facts['packs'] ?? array() ) ),
		) ) );
	}
}
