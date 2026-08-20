<?php
/**
 * Einzelne Seite ohne eigene Vorlage.
 *
 * @package ReymondTobias
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<section class="page-head shell">
	<h1 class="display page-head__title">
		<span class="line-mask"><span data-split><?php echo esc_html( get_the_title() ); ?></span></span>
	</h1>
</section>

<section class="section shell" style="padding-top: clamp(1.5rem, 4vw, 3rem)">
	<div class="about__body reveal">
		<?php
		while ( have_posts() ) :
			the_post();
			the_content();

			wp_link_pages(
				array(
					'before' => '<p class="player__meta">',
					'after'  => '</p>',
				)
			);
		endwhile;
		?>
	</div>
</section>

<?php
get_footer();
