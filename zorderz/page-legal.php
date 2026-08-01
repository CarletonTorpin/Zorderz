<?php
/**
 * Template Name: Legal Document
 * Description: Terms & Conditions, Privacy Policy, and other legal pages.
 *
 * @package Zorderz
 * @version 1.0.0
 */

get_header(); ?>

<div class="view active" style="min-height: 100vh; min-height: 100dvh; padding: var(--ref-space-4) var(--ref-space-4) var(--ref-space-16);">
    <div style="background: var(--sys-surface); border-radius: var(--ref-radius-lg); padding: var(--ref-space-6); border: 1px solid var(--sys-border); max-width: 800px; margin: 0 auto;">
        <a href="<?php echo esc_url( home_url( '/' ) ); ?>" style="display: inline-flex; align-items: center; gap: 8px; margin-bottom: var(--ref-space-4); font-size: var(--ref-font-sm); color: var(--sys-brand);">
            <i data-lucide="arrow-left" style="width:16px;height:16px"></i>
            <?php esc_html_e( 'Back to Zorderz', 'zorderz' ); ?>
        </a>
        <h1 style="font-size: var(--ref-font-2xl); font-weight: var(--sys-font-wt-b); margin-bottom: var(--ref-space-4);">
            <?php the_title(); ?>
        </h1>
        <div style="font-size: var(--ref-font-base); line-height: 1.8; color: var(--sys-text);">
            <?php
            while ( have_posts() ) :
                the_post();
                the_content();
            endwhile;
            ?>
        </div>
    </div>
</div>

<?php get_footer(); ?>
