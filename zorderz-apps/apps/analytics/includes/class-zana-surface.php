<?php
/**
 * ZANA_Surface — the chat page's own settings, handed to chat.js on zanaChat.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Surface {

	/**
	 * The admin-ajax actions behind the optional features the page reaches through admin-ajax (Privacy View's road and the
	 * Send press). The page posts one only when config() found it registered (a feature whose server side is not
	 * installed draws nothing), so no button posts to nothing.
	 */
	const OPTIONAL_AJAX = array(
		'memory' => array( 'zana_list_memories' ),
		'dm'     => array( 'zana_confirm_zim_dm' ),
	);

	/**
	 * The services behind the features the page reaches through REST: the class and method that answer each.
	 */
	const OPTIONAL_SERVICES = array(
		'memory'       => array( 'ZANA_REST_More', 'memory_list' ),
		'memoryDelete' => array( 'ZANA_REST_More', 'memory_delete' ),
		'compose'      => array( 'ZANA_REST_More', 'compose' ),
		'notify'       => array( 'ZANA_REST_More', 'turn_notify' ),
		'docs'         => array( 'ZANA_Invoice_Doc', 'open' ),
		'docLink'      => array( 'ZANA_Invoice_Doc', 'link' ),
		'starters'     => array( 'ZANA_Starters', 'for_user' ),
	);

	/** Features that write or open records: never on the shared device, never for a Privacy View account. */
	const WRITES = array( 'memoryDelete', 'compose', 'notify', 'docs', 'docLink' );

	/** @var callable|null tests: function( string $kind ('ajax'|'service'), string $name, string $method ): bool */
	public static $probe = null;

	private static function answers( string $kind, string $name, string $method = '' ): bool {
		if ( is_callable( self::$probe ) ) {
			return (bool) call_user_func( self::$probe, $kind, $name, $method );
		}
		if ( 'ajax' === $kind ) {
			return function_exists( 'has_action' ) && false !== has_action( 'wp_ajax_' . $name );
		}
		return class_exists( $name ) && method_exists( $name, $method );
	}

	/**
	 * What the chat page needs besides its routes: which of its features this server answers, the starter questions, the
	 * hold's life for a Confirm card, and the words. A feature whose server side is not installed is off, so the page
	 * draws no button for it (never one that fails); a filter may turn one off but never on; the shared device gets no
	 * feature at all and a Privacy View account none that writes or opens records (it keeps its own sentences, read only,
	 * and its one direct-message partner). The routes behind each feature check the person again on every call.
	 *
	 * Filters: zana_chat_features (array of booleans, per person), zana_chat_starters (the starter questions: the chat's
	 * own list for the person's role when that service is installed, else none), zana_chat_prompt_weights (question =>
	 * 0..1; a question nothing records yet can weigh less).
	 *
	 * @return array{features:array<string,bool>,starters:string[],weights:array<string,float>,confirm_ttl:int,i18n:array<string,string>}
	 */
	public static function config( int $uid, bool $is_kiosk, bool $pv ): array {
		$features = array();
		foreach ( self::OPTIONAL_SERVICES as $feature => $svc ) {
			$features[ $feature ] = self::answers( 'service', $svc[0], $svc[1] );
		}
		foreach ( self::OPTIONAL_AJAX as $feature => $actions ) {
			$ok = array() !== $actions;
			foreach ( $actions as $a ) {
				$ok = $ok && self::answers( 'ajax', $a );
			}
			// the list's admin-ajax road is Privacy View's only one; the REST road serves everyone else
			$features[ $feature ] = 'memory' === $feature ? ( $pv ? $ok : ! empty( $features['memory'] ) ) : $ok;
		}
		$on  = (array) apply_filters( 'zana_chat_features', $features, $uid );
		$out = array();
		foreach ( array( 'memory', 'memoryDelete', 'dm', 'compose', 'notify', 'docs', 'docLink' ) as $k ) {
			// a filter may turn a feature off; never on when its server side does not answer
			$out[ $k ] = ! empty( $features[ $k ] ) && ! empty( $on[ $k ] );
		}
		if ( $is_kiosk ) {
			// the shared device keeps no memory, reads Messages only and opens no records
			$out = array_fill_keys( array_keys( $out ), false );
		}
		if ( $pv ) {
			foreach ( self::WRITES as $k ) {
				$out[ $k ] = false;
			}
		}

		$base = ( ! $is_kiosk && ! empty( $features['starters'] ) && $uid > 0 ) ? (array) self::service_starters( $uid ) : array();
		$starters = array();
		foreach ( (array) apply_filters( 'zana_chat_starters', $base, $uid ) as $q ) {
			if ( is_string( $q ) && '' !== trim( $q ) && count( $starters ) < 50 ) {
				$starters[] = mb_substr( trim( preg_replace( '/\s+/', ' ', $q ) ), 0, 200 );
			}
		}
		$weights = array();
		foreach ( (array) apply_filters( 'zana_chat_prompt_weights', array(), $uid ) as $q => $w ) {
			if ( is_string( $q ) && is_numeric( $w ) ) {
				$weights[ $q ] = max( 0.0, min( 1.0, (float) $w ) );
			}
		}

		return array(
			'features'    => $out,
			'starters'    => $is_kiosk ? array() : array_values( array_unique( $starters ) ),
			'weights'     => $weights,
			'confirm_ttl' => class_exists( 'ZANA_Actions' ) && defined( 'ZANA_Actions::TTL' ) ? (int) ZANA_Actions::TTL : 600,
			'i18n'        => self::words(),
		);
	}

	/** The chat's own starter questions for the person's role, when that service is installed. */
	private static function service_starters( int $uid ): array {
		if ( is_callable( self::$probe ) || ! class_exists( 'ZANA_Starters' ) ) {
			return array();
		}
		try {
			return (array) ZANA_Starters::for_user( $uid );
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/** The chat surface's words (app.php's localize call adds the base set). */
	public static function words(): array {
		return array(
			'newChat'          => __( 'New chat', 'zorderz' ),
			'chats'            => __( 'Chats', 'zorderz' ),
			'noChats'          => __( 'No earlier chats yet.', 'zorderz' ),
			'untitled'         => __( 'Chat', 'zorderz' ),
			'loading'          => __( 'Loading…', 'zorderz' ),
			'close'            => __( 'Close', 'zorderz' ),
			'copy'             => __( 'Copy', 'zorderz' ),
			'copied'           => __( 'Copied', 'zorderz' ),
			'copyFailed'       => __( 'Copy did not work here.', 'zorderz' ),
			'answerReady'      => __( 'Answer ready', 'zorderz' ),
			'instant'          => __( '⚡ Instant · worked out from your records, no Ai step', 'zorderz' ),
			'done'             => __( 'Done.', 'zorderz' ),
			'notDone'          => __( 'That did not go through.', 'zorderz' ),
			'cancelled'        => __( 'Cancelled. Nothing was changed.', 'zorderz' ),
			'confirmNoWrite'   => __( 'Changes cannot be made from this account.', 'zorderz' ),
			'expiredCard'      => __( 'This confirmation has expired and nothing was run. Ask again and it will be set up fresh.', 'zorderz' ),
			/* translators: %s: an app's name */
			'openIn'           => __( 'Open in %s', 'zorderz' ),
			'messagesApp'      => __( 'Messages', 'zorderz' ),
			/* translators: %s: a teammate's handle, e.g. @alex */
			'draftTo'          => __( 'Draft message to %s', 'zorderz' ),
			/* translators: %s: a teammate's handle */
			'sendTo'           => __( 'Send to %s', 'zorderz' ),
			'sending'          => __( 'Sending…', 'zorderz' ),
			'dmSent'           => __( 'Sent.', 'zorderz' ),
			'draftCancelled'   => __( 'Draft cancelled.', 'zorderz' ),
			'readOnlyMessages' => __( 'Messages is read-only on this shared device.', 'zorderz' ),
			'copyToSend'       => __( 'Copy it to send it yourself.', 'zorderz' ),
			/* translators: %s: a teammate's name */
			'sentToIn'         => __( 'Sent to %s in Messages', 'zorderz' ),
			'teammate'         => __( 'your teammate', 'zorderz' ),
			'openInMessages'   => __( 'Open in Messages', 'zorderz' ),
			/* translators: %s: a teammate's name */
			'threadWith'       => __( 'Your messages with %s', 'zorderz' ),
			'emailDraft'       => __( 'Email draft', 'zorderz' ),
			'emailTo'          => __( 'To', 'zorderz' ),
			'emailSubject'     => __( 'Subject', 'zorderz' ),
			'openInMail'       => __( 'Open in Mail', 'zorderz' ),
			'openInMailAdd'    => __( 'Open in Mail (add recipient)', 'zorderz' ),
			'miniLabel'        => __( 'A small chart of the rows under this answer', 'zorderz' ),
			/* translators: %s: a page's title */
			'openPage'         => __( 'Open %s', 'zorderz' ),
			/* translators: %s: a document's number, e.g. #1001 */
			'opening'          => __( 'Opening %s…', 'zorderz' ),
			'docClosed'        => __( 'That one could not be opened.', 'zorderz' ),
			'noLink'           => __( 'No customer link came back just now. Try again in a minute.', 'zorderz' ),
			'invoice'          => __( 'Invoice', 'zorderz' ),
			'estimate'         => __( 'Estimate', 'zorderz' ),
			'aboutYou'         => __( 'About you', 'zorderz' ),
			'memIntro'         => __( 'Short sentences the assistant keeps about you, so its answers fit how you work. Each one is yours to delete. Your chats are not affected. To add one, tell the assistant "remember that I…".', 'zorderz' ),
			'memDelete'        => __( 'Delete', 'zorderz' ),
			'memEmpty'         => __( 'Nothing saved about you yet.', 'zorderz' ),
			'memOff'           => __( 'The assistant keeps no sentences about you on this site.', 'zorderz' ),
			/* translators: %s: a channel, e.g. #general */
			'draftFor'         => __( 'Draft for %s', 'zorderz' ),
			'openInEmail'      => __( 'Open in Email', 'zorderz' ),
			'bellOn'           => __( 'Notifications are on for this device (tap to turn off)', 'zorderz' ),
			'bellOff'          => __( 'Turn on notifications', 'zorderz' ),
		);
	}
}
