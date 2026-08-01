<?php
/**
 * Neutral in-repo receipt letterhead template (Zorderz Receipts).
 *
 * WHY THIS FILE EXISTS (crosswalk 03 §B18 / playbook §6): the customer-facing receipt
 * letterhead + certification copy used to live OFF-repo, inside a private, product-named AI bot
 * ("the receipt writer"). That made the bot load-bearing IP and baked one business's name,
 * colours and product wording into every receipt. It now ships IN-REPO as this neutral,
 * placeholder-driven template. ALL identity is resolved at RENDER TIME from
 * ZDZ_Business_Profile — there is NO company, person, product, place or provider name here.
 *
 * HOW IT IS USED:
 *   - As the deterministic letterhead/header + footer wrapper for a receipt page.
 *   - As the neutral scaffold the receipt-writer prompt is built around (the AI fills the
 *     body; this frame is authored here, not at the provider), so the configured bot handle
 *     (ZDZ_Core_Poe / a setting) is a replaceable model, not the source of the letterhead.
 *
 * Consumers call zrcpt_render_letterhead( $ctx ) (see below) or the `zrcpt_letterhead_template`
 * filter. Everything degrades cleanly on an unconfigured install (site name + default ramp).
 *
 * @package Zorderz\Receipts
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'zrcpt_letterhead_identity' ) ) {
    /**
     * Resolve the neutral letterhead identity from Business Profile (with WordPress fallbacks).
     *
     * @return array{name:string,logo_html:string,address:string,phone:string,primary:string,accent:string,ink:string}
     */
    function zrcpt_letterhead_identity(): array {
        $name = get_bloginfo( 'name' );
        $logo = '';
        $addr = '';
        $phone = '';
        $primary = '#1E3A5F';
        $accent  = '#2C5F8A';
        if ( class_exists( 'ZDZ_Business_Profile' ) ) {
            $name    = ZDZ_Business_Profile::name();
            $logo    = ZDZ_Business_Profile::logo_html( 'wide', 'dark', 40 );
            $addr    = (string) ZDZ_Business_Profile::address_line();
            $phone   = (string) ZDZ_Business_Profile::get( 'contact.phone', '' );
            $primary = (string) ZDZ_Business_Profile::get( 'brand.ramp.600', $primary );
            $accent  = (string) ZDZ_Business_Profile::get( 'brand.ramp.500', $accent );
        }
        return [
            'name'      => $name,
            'logo_html' => $logo,
            'address'   => $addr,
            'phone'     => $phone,
            'primary'   => $primary,
            'accent'    => $accent,
            'ink'       => '#ffffff',
        ];
    }
}

if ( ! function_exists( 'zrcpt_render_letterhead' ) ) {
    /**
     * Render the neutral letterhead HEADER block for a receipt.
     *
     * @param array $ctx { heading?:string, subheading?:string } — the mode's doc heading etc.
     * @return string HTML (safe: identity is escaped; brand colours are attribute-escaped).
     */
    function zrcpt_render_letterhead( array $ctx = [] ): string {
        $id       = zrcpt_letterhead_identity();
        $heading  = (string) ( $ctx['heading'] ?? __( 'Completed Work', 'zorderz' ) );
        $sub      = (string) ( $ctx['subheading'] ?? '' );
        $brand    = $id['logo_html'] !== '' ? $id['logo_html'] : '<span class="zrcpt-lh-name">' . esc_html( $id['name'] ) . '</span>';

        $meta = [];
        if ( $id['address'] !== '' ) { $meta[] = esc_html( $id['address'] ); }
        if ( $id['phone'] !== '' )   { $meta[] = esc_html( $id['phone'] ); }
        $meta_html = $meta ? '<div class="zrcpt-lh-meta">' . implode( ' &middot; ', $meta ) . '</div>' : '';

        $html  = '<header class="zrcpt-letterhead" style="background:' . esc_attr( $id['primary'] ) . ';color:' . esc_attr( $id['ink'] ) . ';padding:24px 28px;border-radius:8px 8px 0 0;">';
        $html .= '<div class="zrcpt-lh-brand">' . $brand . '</div>';
        $html .= '<h1 class="zrcpt-lh-heading" style="margin:12px 0 0;font-size:22px;">' . esc_html( $heading ) . '</h1>';
        if ( $sub !== '' ) {
            $html .= '<p class="zrcpt-lh-sub" style="margin:4px 0 0;opacity:.85;">' . esc_html( $sub ) . '</p>';
        }
        $html .= $meta_html;
        $html .= '</header>';

        /**
         * Filter the rendered letterhead. Lets a tenant swap the entire frame without editing
         * code, and is the documented seam for a future Core Document Conventions renderer.
         */
        return (string) apply_filters( 'zrcpt_letterhead_template', $html, $ctx, $id );
    }
}
