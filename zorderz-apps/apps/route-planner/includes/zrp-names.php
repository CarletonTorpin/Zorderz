<?php
/**
 * What the survey listens for from the street, and the product line each tag points to.
 *
 * Core ships only the two canvassing tags every door-hanger team needs -- a gated community and a no-soliciting sign
 * -- with no product line. What a business looks for (the features of a home that point to what it sells) is the
 * business's own: catalog.yml `survey_tags`, stored as the `zrp_vocabulary` option in the same row shape, or the filter
 * `zrp_default_vocabulary`; an administrator adds more under Zorderz -> Route Planner without a release.
 *
 * A row: slug => [ label, product slug, product label, [ phrases ], flags, [ choice slugs ] ]. A phrase is matched as
 * whole words, case
 * and plurals aside ("corner lot" also hears "corner lots"); the longest phrase wins where two overlap. Flags:
 *   hard_to_see  rarely visible from the street: one home seen means the neighborhood likely has them
 *   ask          the words leave the kind open; the Review list asks which of the row's choice slugs it is
 *   code         the words may carry a gate or lockbox code, which is sealed, never kept in the note
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The canvassing tags Core ships: no product, only "this street is gated" and "this street posts no-soliciting". */
function zrp_core_vocabulary() {
	return array(
		'hoa_gated'        => array( 'HOA / gated community', '', '', array( 'hoa', 'h o a', 'homeowners association', 'gated', 'gated community', 'guard gate', 'guard shack', 'gate code', 'community gate' ), array( 'code' ) ),
		'no_solicit_signs' => array( 'No-solicitation', '', '', array( 'no soliciting', 'no solicitation', 'no solicitors', 'no soliciting sign', 'no soliciting signs', 'no salesmen', 'no salespeople', 'no sales people', 'no flyers', 'no fliers', 'no door hangers', 'no handbills', 'no advertising', 'no trespassing', 'do not knock', 'please do not knock', "don't knock", 'locked gate', 'locked front gate', 'gate is locked', 'gate was locked', 'locked yard gate' ) ),
	);
}

/** Core's tags plus the business's own (the `zrp_vocabulary` option a pack writes). A business row may add to a Core tag. */
function zrp_builtin_vocabulary() {
	$v     = zrp_core_vocabulary();
	$extra = function_exists( 'get_option' ) ? get_option( 'zrp_vocabulary', array() ) : array();
	foreach ( is_array( $extra ) ? $extra : array() as $slug => $row ) {
		if ( ! is_string( $slug ) || ! is_array( $row ) ) {
			continue;
		}
		if ( isset( $v[ $slug ] ) ) {
			// a Core tag keeps its meaning (no product line can be pinned on "no soliciting"); only phrases are added
			$v[ $slug ][3] = array_values( array_unique( array_merge( (array) $v[ $slug ][3], array_map( 'strval', (array) ( $row[3] ?? array() ) ) ) ) );
			continue;
		}
		$v[ $slug ] = $row;
	}
	return $v;
}

/**
 * Tags that count AGAINST a planned route: a street the survey says is gated, or posts no-soliciting signs, is a
 * poorer walk for door hangers. A business may add its own (filter `zrp_plan_negative_tags`); the two Core tags always
 * stay on the list.
 */
function zrp_plan_negative_tags() {
	$core  = array( 'no_solicit_signs', 'hoa_gated' );
	$added = function_exists( 'apply_filters' ) ? (array) apply_filters( 'zrp_plan_negative_tags', $core ) : $core;
	return array_values( array_unique( array_filter( array_map( 'strval', array_merge( $core, $added ) ) ) ) );
}
