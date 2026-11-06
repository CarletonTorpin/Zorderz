<?php
/**
 * ZDZ_App_Groups — the one meaning of an app's colour.
 *
 * An app's colour says which of five groups it belongs to: Sales, Field, Team,
 * Office, Tools. The colours come from the Okabe-Ito colourblind-safe palette, and one
 * more colour — vermilion — is reserved for a single meaning, "something here needs
 * you"; it is never a group colour. An app's own `cc` and `cat` are kept as
 * `cc_plugin` / `cat_plugin` for reference; the group decides what is drawn.
 *
 * Icon ink follows the plate: dark ink on orange, green, sky blue and pink; white on
 * blue and vermilion. Each pairing passes the 3:1 contrast icons need.
 *
 * What is the business's to decide: which app sits in which group, and what each
 * group is called (org.yml `app_groups`, stored as the `zdz_app_groups` option; or
 * the `zdz_app_group` / `zdz_app_group_labels` filters). Core ships a default
 * arrangement for the platform's own apps; an app with no entry is grouped from the
 * category it declares. The colours and the reserved "needs you" meaning are Core's
 * — they are an accessibility contract, not a brand choice.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_App_Groups {

	const OPTION = 'zdz_app_groups';

	/** The five groups, in launcher order. */
	const GROUPS = array(
		'sales'  => array( 'label' => 'Sales', 'color' => '#E69F00', 'ink' => '#1A1A1A' ),
		'field'  => array( 'label' => 'Field', 'color' => '#009E73', 'ink' => '#1A1A1A' ),
		'team'   => array( 'label' => 'Team', 'color' => '#56B4E9', 'ink' => '#1A1A1A' ),
		'office' => array( 'label' => 'Office', 'color' => '#0072B2', 'ink' => '#FFFFFF' ),
		'tools'  => array( 'label' => 'Tools', 'color' => '#CC79A7', 'ink' => '#1A1A1A' ),
	);

	/** The one reserved meaning: "something here needs you". Never a group colour. */
	const NEEDS_YOU     = '#D55E00';
	const NEEDS_YOU_INK = '#FFFFFF';

	/** Core's default arrangement of the platform's own apps (app id => group). */
	const APPS = array(
		'sales-analytics'       => 'sales',
		'estimate-creator'      => 'sales',
		'agreements'            => 'sales',
		'leads'                 => 'sales',
		'dotplot'               => 'sales',
		'charts'                => 'sales',
		'jobs'                  => 'field',
		'prep'                  => 'field',
		'stock'                 => 'field',
		'receipts'              => 'field',
		'doorhangers'           => 'field',
		'route-planner'         => 'field',
		'scheduler'             => 'team',
		'internal-messaging'    => 'team',
		'inbox'                 => 'team',
		'hours'                 => 'team',
		'commission-calculator' => 'office',
		'surveys'               => 'office',
		'knowledge-vault'       => 'office',
		'invoice'               => 'office',
		'zdz-camera'            => 'tools',
		'zdz-media'             => 'tools',
		'zdz-media-all'         => 'tools',
		'zdz-sketch-pad'        => 'tools',
		'game'                  => 'tools',
		'team-roles'            => 'team',
		'territories'           => 'sales',
	);

	/** A new app is grouped from the category it declares. */
	const CAT_FALLBACK = array(
		'sales'    => 'sales',
		'insight'  => 'sales',
		'field'    => 'field',
		'ops'      => 'field',
		'team'     => 'team',
		'personal' => 'team',
		'admin'    => 'office',
		'finance'  => 'office',
		'service'  => 'office',
		'office'   => 'office',
		'tools'    => 'tools',
	);

	/** The business's arrangement: [ 'apps' => [ app_id => group ], 'labels' => [ group => label ] ]. */
	private static function stored(): array {
		$s = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		return is_array( $s ) ? $s : array();
	}

	/** The groups with the business's labels applied. Colours never change. */
	public static function groups(): array {
		$g      = self::GROUPS;
		$labels = (array) ( self::stored()['labels'] ?? array() );
		if ( function_exists( 'apply_filters' ) ) {
			$f      = apply_filters( 'zdz_app_group_labels', $labels );
			$labels = is_array( $f ) ? $f : $labels;
		}
		foreach ( $labels as $id => $label ) {
			$label = trim( wp_strip_all_tags( (string) $label ) );
			if ( isset( $g[ $id ] ) && '' !== $label ) {
				$g[ $id ]['label'] = mb_substr( $label, 0, 24 );
			}
		}
		return $g;
	}

	/** The group id for an app. */
	public static function group_for( string $app_id, string $declared_cat = '' ): string {
		$apps = (array) ( self::stored()['apps'] ?? array() );
		$g    = $apps[ $app_id ] ?? ( self::APPS[ $app_id ] ?? ( self::CAT_FALLBACK[ strtolower( trim( $declared_cat ) ) ] ?? 'tools' ) );
		if ( function_exists( 'apply_filters' ) ) {
			$f = apply_filters( 'zdz_app_group', $g, $app_id, $declared_cat );
			if ( is_string( $f ) && isset( self::GROUPS[ $f ] ) ) {
				$g = $f;
			}
		}
		return isset( self::GROUPS[ $g ] ) ? $g : 'tools';
	}

	/**
	 * Give one app config its group's label, colour and ink. The app's own colour is kept as `cc_plugin`.
	 *
	 * @param array  $config The app config (compact keys: nm, cat, cc, …).
	 * @param string $app_id The registered app id.
	 */
	public static function apply( array $config, string $app_id ): array {
		$gid    = self::group_for( $app_id, (string) ( $config['cat'] ?? '' ) );
		$groups = self::groups();
		$g      = $groups[ $gid ];
		if ( isset( $config['cc'] ) && ! isset( $config['cc_plugin'] ) ) {
			$config['cc_plugin'] = (string) $config['cc'];
		}
		if ( isset( $config['cat'] ) && ! isset( $config['cat_plugin'] ) ) {
			$config['cat_plugin'] = (string) $config['cat'];
		}
		$config['grp'] = $gid;
		$config['cat'] = $g['label'];
		$config['cc']  = $g['color'];
		$config['ink'] = $g['ink'];
		return $config;
	}

	/** CSS custom properties for the groups and the reserved colour. */
	public static function css_vars(): string {
		$v = array();
		foreach ( self::GROUPS as $id => $g ) {
			$v[] = '--zdz-grp-' . $id . ':' . $g['color'] . ';--zdz-grp-' . $id . '-ink:' . $g['ink'];
		}
		$v[] = '--zdz-needs-you:' . self::NEEDS_YOU . ';--zdz-needs-you-ink:' . self::NEEDS_YOU_INK;
		return ':root{' . implode( ';', $v ) . '}';
	}

	/** The groups for the launcher, in order: [ {id, label, color, ink}, … ] plus the reserved colour. */
	public static function for_js(): array {
		$out = array();
		foreach ( self::groups() as $id => $g ) {
			$out[] = array( 'id' => $id, 'label' => $g['label'], 'color' => $g['color'], 'ink' => $g['ink'] );
		}
		return array( 'groups' => $out, 'needsYou' => self::NEEDS_YOU, 'needsYouInk' => self::NEEDS_YOU_INK );
	}

	/**
	 * Validate an arrangement before it is stored (the Identity Pack calls this).
	 * Unknown groups are dropped; app ids are kept as given (an app may not be installed yet).
	 *
	 * @param array $in [ 'apps' => [ app_id => group ], 'labels' => [ group => label ] ] or a flat app map.
	 */
	public static function sanitize( array $in ): array {
		$apps   = isset( $in['apps'] ) && is_array( $in['apps'] ) ? $in['apps'] : ( isset( $in['labels'] ) ? array() : $in );
		$labels = isset( $in['labels'] ) && is_array( $in['labels'] ) ? $in['labels'] : array();
		$out    = array( 'apps' => array(), 'labels' => array() );
		foreach ( $apps as $app => $g ) {
			$app = sanitize_key( (string) $app );
			if ( '' !== $app && is_string( $g ) && isset( self::GROUPS[ $g ] ) ) {
				$out['apps'][ $app ] = $g;
			}
		}
		foreach ( $labels as $g => $label ) {
			if ( isset( self::GROUPS[ $g ] ) && is_scalar( $label ) && '' !== trim( (string) $label ) ) {
				$out['labels'][ $g ] = mb_substr( trim( wp_strip_all_tags( (string) $label ) ), 0, 24 );
			}
		}
		return $out;
	}
}
