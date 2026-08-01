<?php
/**
 * WooCommerce Pages Wrapper
 *
 * Single template file that handles ALL WooCommerce pages (shop, product,
 * category, tag). Uses woocommerce_content() which automatically detects
 * the page type and renders the appropriate content.
 *
 * This approach avoids per-template version tracking entirely.
 * @see https://developer.woocommerce.com/docs/theming/theme-development/template-structure/
 *
 * @package Zorderz
 */

get_header(); ?>

<div class="view active" style="min-height: 100vh; min-height: 100dvh; padding: var(--ref-space-4) var(--ref-space-4) var(--ref-space-16);">
	<div style="max-width: 1200px; margin: 0 auto;">
		<?php if ( is_product() ) : ?>
			<a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>" style="display: inline-flex; align-items: center; gap: 8px; margin-bottom: var(--ref-space-4); font-size: var(--ref-font-sm); color: var(--sys-brand);">
				<i data-lucide="arrow-left" style="width:16px;height:16px"></i>
				<?php esc_html_e( 'Back to Shop', 'zorderz' ); ?>
			</a>
		<?php else : ?>
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" style="display: inline-flex; align-items: center; gap: 8px; margin-bottom: var(--ref-space-4); font-size: var(--ref-font-sm); color: var(--sys-brand);">
				<i data-lucide="arrow-left" style="width:16px;height:16px"></i>
				<?php esc_html_e( 'Back to Zorderz', 'zorderz' ); ?>
			</a>
		<?php endif; ?>

		<div style="background: var(--sys-surface); border-radius: var(--ref-radius-lg); padding: var(--ref-space-5); border: 1px solid var(--sys-border);">
			<?php woocommerce_content(); ?>
		</div>
	</div>
</div>

<?php get_footer(); ?>
