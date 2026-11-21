<?php
/**
 * ZANA_Drafts_Page — what the chat page adds for drafts: Edit on a draft card, "How you write" in the "About you"
 * sheet, and the request behind a link from a Messages reply.
 *
 * One small script (assets/js/chat-drafts.js) and its style sheet ride on the chat's own, on the same page and only
 * for someone they can do something for:
 *   style   an administrator the style rules are on for (ZANA_Style_Memory::enabled_for): Edit on an email, direct
 *           message or channel post draft, and the "How you write" list;
 *   ask     anyone signed in who is not the shared device or an account in Privacy View: a link of the form
 *           <home>/?zana_ask=<id> under a draft in a Messages reply opens the chat and sends the request it stands for
 *           (the server gives the words only to the person the link was made for, once).
 * The page's settings and words are handed over as window.zanaDrafts. Nothing here decides what a person may do: the
 * routes behind every button check it again.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Drafts_Page {

	public static function boot(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 21 );   // after the chat's own scripts (20)
	}

	/** What the page may offer this person. */
	public static function features( int $uid ): array {
		$shared = class_exists( 'ZANA_LLM_Client' ) && ZANA_LLM_Client::is_shared_device( $uid );
		$pv     = class_exists( 'ZDZ_Privacy_View' ) && ZDZ_Privacy_View::is_on( $uid );
		return array(
			'style' => class_exists( 'ZANA_Style_Memory' ) && ZANA_Style_Memory::enabled_for( $uid ),
			'ask'   => $uid > 0 && ! $shared && ! $pv && class_exists( 'ZANA_PV_DM_Agent' ),
		);
	}

	/** window.zanaDrafts. */
	public static function config( int $uid ): array {
		return self::features( $uid ) + array(
			'askParam' => class_exists( 'ZANA_PV_DM_Agent' ) ? ZANA_PV_DM_Agent::ASK_PARAM : 'zana_ask',
			'i18n'     => array(
				'edit'      => __( 'Edit', 'zorderz' ),
				'editTitle' => __( 'Change the draft. The assistant learns from what you change.', 'zorderz' ),
				'editLabel' => __( 'The draft\'s words', 'zorderz' ),
				'save'      => __( 'Save', 'zorderz' ),
				'cancel'    => __( 'Cancel', 'zorderz' ),
				'saved'     => __( 'Saved your edit. The assistant will write more like this.', 'zorderz' ),
				'notSaved'  => __( 'That change could not be saved.', 'zorderz' ),
				'howTitle'  => __( 'How you write', 'zorderz' ),
				'howIntro'  => __( 'Short style rules the assistant follows when it drafts for you, by who will read the draft. They come from your edits to its drafts and the corrections you type; your own rules come first.', 'zorderz' ),
				'howEmpty'  => __( 'No rules yet. Edit a draft, or add a rule of your own.', 'zorderz' ),
				/* translators: %d: a number of edits and corrections */
				'howEdits'  => __( 'Edits and corrections kept: %d', 'zorderz' ),
				'yours'     => __( 'yours', 'zorderz' ),
				'learned'   => __( 'learned', 'zorderz' ),
				'delete'    => __( 'Delete', 'zorderz' ),
				'add'       => __( 'Add rule', 'zorderz' ),
				'addFor'    => __( 'Who the rule is for', 'zorderz' ),
				'addWords'  => __( 'A rule in one sentence, about style', 'zorderz' ),
				'clear'     => __( 'Clear everything', 'zorderz' ),
				'clearAsk'  => __( 'Delete every rule and every kept edit? This cannot be undone.', 'zorderz' ),
				'loading'   => __( 'Loading…', 'zorderz' ),
				'askGone'   => __( 'That link has expired or is not yours.', 'zorderz' ),
			),
		);
	}

	public static function enqueue(): void {
		if ( ! is_user_logged_in() || ! is_front_page() || ! wp_script_is( 'zana-chat', 'enqueued' ) ) {
			return;
		}
		$cfg = self::config( (int) get_current_user_id() );
		if ( ! $cfg['style'] && ! $cfg['ask'] ) {
			return;
		}
		wp_enqueue_style( 'zana-chat-drafts', ZANA_URL . 'assets/css/chat-drafts.css', array( 'zana-chat' ), zana_asset_ver( 'assets/css/chat-drafts.css' ) );
		wp_enqueue_script( 'zana-chat-drafts', ZANA_URL . 'assets/js/chat-drafts.js', array( 'zana-chat' ), zana_asset_ver( 'assets/js/chat-drafts.js' ), true );
		wp_add_inline_script( 'zana-chat-drafts', 'window.zanaDrafts = ' . wp_json_encode( $cfg ) . ';', 'before' );
	}
}
