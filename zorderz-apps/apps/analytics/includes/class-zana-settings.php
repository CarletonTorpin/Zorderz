<?php
/**
 * ZANA_Settings — Zorderz → Chat: the chat's own settings, the business's facts, and the answer times.
 *
 *   "About you"              on (Core) / off; the weekly pass off (Core) / on
 *   Outreach rest period     days between outreach emails to one customer; 0 (Core) = off
 *   Mailbox words            supplies this business buys by email; admin topics that live in its mail (one a line)
 *   Website pages            the page list the chat may open (JSON: primary_pages / tools / company_utility)
 *   Starter questions        per role, one a line; empty = Core's neutral questions
 *   Facts                    the business's own facts (add, edit, delete; CSV through the REST routes)
 *   Answer times             the last seven days by kind (ZANA_Turn_Timings)
 * Administrators only (manage_options); every option has its own sanitiser.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Settings {

	const PAGE  = 'zana-settings';
	const GROUP = 'zana_settings_group';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 45 );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_zana_fact_save', array( __CLASS__, 'post_fact_save' ) );
		add_action( 'admin_post_zana_fact_delete', array( __CLASS__, 'post_fact_delete' ) );
	}

	public static function menu(): void {
		$parent = menu_page_url( 'zdz-core-settings', false ) ? 'zdz-core-settings' : 'options-general.php';
		add_submenu_page( $parent, __( 'Chat', 'zorderz' ), __( 'Chat', 'zorderz' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function register(): void {
		register_setting( self::GROUP, ZANA_User_Memory::ENABLED_OPT, array( 'type' => 'string', 'default' => '1', 'sanitize_callback' => array( __CLASS__, 'bool01' ) ) );
		register_setting( self::GROUP, ZANA_User_Memory::WEEKLY_OPT, array( 'type' => 'string', 'default' => '0', 'sanitize_callback' => array( __CLASS__, 'bool01' ) ) );
		register_setting( self::GROUP, ZANA_Email_Cooldown::DAYS_OPT, array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => array( 'ZANA_Email_Cooldown', 'sanitize_days' ) ) );
		register_setting( self::GROUP, ZANA_Mail_Relevance::SUPPLY_OPT, array( 'type' => 'array', 'default' => array(), 'sanitize_callback' => array( __CLASS__, 'word_lines' ) ) );
		register_setting( self::GROUP, ZANA_Mail_Relevance::ADMIN_OPT, array( 'type' => 'array', 'default' => array(), 'sanitize_callback' => array( __CLASS__, 'word_lines' ) ) );
		register_setting( self::GROUP, ZANA_Page_Nav::OPT, array( 'type' => 'string', 'default' => '', 'sanitize_callback' => array( __CLASS__, 'page_map' ) ) );
		foreach ( array_keys( ZANA_Starters::BUCKETS ) as $b ) {
			register_setting( self::GROUP, ZANA_Starters::OPTION_PREFIX . $b, array( 'type' => 'string', 'default' => '', 'sanitize_callback' => array( 'ZANA_Starters', 'sanitize' ) ) );
		}
	}

	public static function bool01( $v ): string {
		return empty( $v ) || '0' === (string) $v ? '0' : '1';
	}

	/** One word or phrase a line (letters, digits, spaces, hyphens, apostrophes; 2–40 characters; at most 80). */
	public static function word_lines( $v ): array {
		$v   = is_array( $v ) ? $v : preg_split( '/[\r\n,]+/', (string) $v );
		$out = array();
		foreach ( (array) $v as $w ) {
			$w = strtolower( trim( (string) $w ) );
			if ( preg_match( '/^[a-z0-9][a-z0-9 \'\-]{1,39}$/', $w ) ) {
				$out[] = $w;
			}
		}
		return array_slice( array_values( array_unique( $out ) ), 0, 80 );
	}

	/** The page list: valid JSON with the known groups, http(s) addresses only; anything else keeps the old value. */
	public static function page_map( $v ): string {
		$raw = trim( (string) $v );
		if ( '' === $raw ) {
			return '';
		}
		$d = json_decode( wp_unslash( $raw ), true );
		if ( ! is_array( $d ) ) {
			add_settings_error( ZANA_Page_Nav::OPT, 'zana_page_map', __( 'The website pages list is not valid JSON; the previous list was kept.', 'zorderz' ) );
			return (string) get_option( ZANA_Page_Nav::OPT, '' );
		}
		$out = array();
		foreach ( ZANA_Page_Nav::GROUPS as $g ) {
			foreach ( (array) ( $d[ $g ] ?? array() ) as $e ) {
				if ( is_array( $e ) && ! empty( $e['id'] ) && ! empty( $e['name'] ) && preg_match( '#^https?://[^\s<>"\']+$#i', (string) ( $e['url'] ?? '' ) ) ) {
					$out[ $g ][] = array(
						'id'          => sanitize_key( (string) $e['id'] ),
						'name'        => sanitize_text_field( (string) $e['name'] ),
						'url'         => esc_url_raw( (string) $e['url'] ),
						'aliases'     => array_values( array_map( 'sanitize_text_field', array_map( 'strval', (array) ( $e['aliases'] ?? array() ) ) ) ),
						'category'    => sanitize_key( (string) ( $e['category'] ?? '' ) ),
						'description' => sanitize_text_field( (string) ( $e['description'] ?? '' ) ),
					);
				}
			}
		}
		return $out ? (string) wp_json_encode( $out ) : '';
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$labels = ZANA_Starters::labels();
		echo '<div class="wrap"><h1>' . esc_html__( 'Chat', 'zorderz' ) . '</h1>';
		settings_errors();
		echo '<form method="post" action="options.php">';
		settings_fields( self::GROUP );
		echo '<table class="form-table" role="presentation">';
		$row = function ( $label, $html, $desc = '' ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $html . ( '' !== $desc ? '<p class="description">' . esc_html( $desc ) . '</p>' : '' ) . '</td></tr>';   // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $html is built from escaped parts below
		};
		$check = function ( $opt, $on ) {
			return '<input type="hidden" name="' . esc_attr( $opt ) . '" value="0" /><label><input type="checkbox" name="' . esc_attr( $opt ) . '" value="1"' . checked( $on, true, false ) . ' /> ' . esc_html__( 'On', 'zorderz' ) . '</label>';
		};
		$row( __( '"About you"', 'zorderz' ), $check( ZANA_User_Memory::ENABLED_OPT, ZANA_User_Memory::enabled() ), __( 'People can ask the chat to remember a sentence about how they like to work, and see and delete each one.', 'zorderz' ) );
		$row( __( '"About you": weekly pass', 'zorderz' ), $check( ZANA_User_Memory::WEEKLY_OPT, ZANA_User_Memory::weekly_enabled() ), __( 'Once a week, at most one new sentence a person, from a habit seen on three different days. Uses the Ai router.', 'zorderz' ) );
		$row( __( 'Outreach rest period (days)', 'zorderz' ), '<input type="number" min="0" max="' . (int) ZANA_Email_Cooldown::MAX_DAYS . '" class="small-text" name="' . esc_attr( ZANA_Email_Cooldown::DAYS_OPT ) . '" value="' . (int) ZANA_Email_Cooldown::days() . '" />', __( '0 = off. Days between one outreach email to a customer and the next. Job emails and replies are always allowed.', 'zorderz' ) );
		$row( __( 'Supplies bought by email', 'zorderz' ), '<textarea rows="3" cols="50" name="' . esc_attr( ZANA_Mail_Relevance::SUPPLY_OPT ) . '">' . esc_textarea( implode( "\n", self::word_lines( get_option( ZANA_Mail_Relevance::SUPPLY_OPT, array() ) ) ) ) . '</textarea>', __( 'One a line. "How many … have I ordered?" with one of these is answered from the person\'s mail.', 'zorderz' ) );
		$row( __( 'Admin topics in the mailbox', 'zorderz' ), '<textarea rows="3" cols="50" name="' . esc_attr( ZANA_Mail_Relevance::ADMIN_OPT ) . '">' . esc_textarea( implode( "\n", self::word_lines( get_option( ZANA_Mail_Relevance::ADMIN_OPT, array() ) ) ) ) . '</textarea>', __( 'One a line, added to insurance, licence, bonding, workers\' comp and payroll tax.', 'zorderz' ) );
		$map = get_option( ZANA_Page_Nav::OPT, '' );
		$row( __( 'Website pages', 'zorderz' ), '<textarea rows="6" cols="80" class="code" name="' . esc_attr( ZANA_Page_Nav::OPT ) . '">' . esc_textarea( is_string( $map ) ? $map : (string) wp_json_encode( $map ) ) . '</textarea>', __( 'JSON: {"primary_pages":[{"id","name","url","aliases":[],"category","description"}],"tools":[…],"company_utility":[…]}. Empty = the chat opens no pages.', 'zorderz' ) );
		foreach ( $labels as $b => $label ) {
			/* translators: %s: a role */
			$row( sprintf( __( 'Starter questions: %s', 'zorderz' ), $label ), '<textarea rows="3" cols="80" name="' . esc_attr( ZANA_Starters::OPTION_PREFIX . $b ) . '" placeholder="' . esc_attr( implode( "\n", ZANA_Starters::defaults( $b ) ) ) . '">' . esc_textarea( (string) get_option( ZANA_Starters::OPTION_PREFIX . $b, '' ) ) . '</textarea>', __( 'One a line. Empty = the neutral questions shown greyed.', 'zorderz' ) );
		}
		echo '</table>';
		submit_button();
		echo '</form>';
		self::render_facts();
		ZANA_Turn_Timings::render_panel();
		echo '</div>';
	}

	private static function render_facts(): void {
		echo '<h2>' . esc_html__( 'The business\'s facts', 'zorderz' ) . '</h2><p>' . esc_html__( 'What the chat may state about the business. A fact above someone\'s level is never in their chat.', 'zorderz' ) . '</p>';
		$facts = ZANA_Company_Facts::get_all();
		if ( $facts ) {
			echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>' . esc_html__( 'Id', 'zorderz' ) . '</th><th>' . esc_html__( 'Category', 'zorderz' ) . '</th><th>' . esc_html__( 'Label', 'zorderz' ) . '</th><th>' . esc_html__( 'Value', 'zorderz' ) . '</th><th>' . esc_html__( 'Who sees it', 'zorderz' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $facts as $f ) {
				$del = wp_nonce_url( admin_url( 'admin-post.php?action=zana_fact_delete&id=' . rawurlencode( (string) $f['id'] ) ), 'zana_fact_delete_' . $f['id'] );
				echo '<tr><td><code>' . esc_html( $f['id'] ) . '</code></td><td>' . esc_html( $f['category'] ) . '</td><td>' . esc_html( $f['label'] ) . '</td><td>' . esc_html( mb_substr( (string) $f['value'], 0, 300 ) ) . '</td><td>' . esc_html( $f['tier_limit'] ) . '</td><td><a href="' . esc_url( $del ) . '">' . esc_html__( 'Delete', 'zorderz' ) . '</a></td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="zana_fact_save" />';
		wp_nonce_field( 'zana_fact_save' );
		echo '<p><input name="id" placeholder="' . esc_attr__( 'id (e.g. opening_hours)', 'zorderz' ) . '" /> <select name="category">';
		foreach ( ZANA_Company_Facts::CATEGORIES as $c ) {
			echo '<option value="' . esc_attr( $c ) . '">' . esc_html( $c ) . '</option>';
		}
		echo '</select> <select name="tier_limit">';
		foreach ( ZANA_Company_Facts::TIER_LIMITS as $t ) {
			echo '<option value="' . esc_attr( $t ) . '">' . esc_html( $t ) . '</option>';
		}
		echo '</select> <input name="label" placeholder="' . esc_attr__( 'label', 'zorderz' ) . '" /></p><p><textarea name="value" rows="3" cols="80" placeholder="' . esc_attr__( 'the fact (saving an existing id replaces it)', 'zorderz' ) . '"></textarea></p>';
		submit_button( __( 'Save fact', 'zorderz' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	public static function post_fact_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'zorderz' ), 403 );
		}
		check_admin_referer( 'zana_fact_save' );
		$r = ZANA_Company_Facts::save( array(
			'id'         => wp_unslash( (string) ( $_POST['id'] ?? '' ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ZANA_Company_Facts::save() sanitizes every field (allow-lists, wp_strip_all_tags) before it is stored
			'category'   => wp_unslash( (string) ( $_POST['category'] ?? '' ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ZANA_Company_Facts::save() sanitizes every field (allow-lists, wp_strip_all_tags) before it is stored
			'label'      => wp_unslash( (string) ( $_POST['label'] ?? '' ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ZANA_Company_Facts::save() sanitizes every field (allow-lists, wp_strip_all_tags) before it is stored
			'value'      => wp_unslash( (string) ( $_POST['value'] ?? '' ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ZANA_Company_Facts::save() sanitizes every field (allow-lists, wp_strip_all_tags) before it is stored
			'tier_limit' => wp_unslash( (string) ( $_POST['tier_limit'] ?? 'all' ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ZANA_Company_Facts::save() sanitizes every field (allow-lists, wp_strip_all_tags) before it is stored
		) );
		if ( empty( $r['success'] ) ) {
			wp_die( esc_html( (string) $r['error'] ), 400 );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	public static function post_fact_delete(): void {
		$id = sanitize_key( wp_unslash( (string) ( $_GET['id'] ?? '' ) ) );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'zorderz' ), 403 );
		}
		check_admin_referer( 'zana_fact_delete_' . $id );
		ZANA_Company_Facts::delete( $id );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}
}
