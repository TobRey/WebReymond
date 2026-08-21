<?php
/**
 * Einzelner Beitrag.
 *
 * @package DjAtze
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<section class="page-head shell">
	<p class="eyebrow reveal"><?php echo esc_html( get_the_date() ); ?></p>
	<h1 class="display page-head__title">
		<span class="line-mask"><span><?php the_title(); ?></span></span>
	</h1>
</section>

<section class="section shell" style="padding-top: clamp(1.5rem, 4vw, 3rem)">
	<div class="about__body reveal">
		<?php
		while ( have_posts() ) :
			the_post();
			the_content();
		endwhile;
		?>
	</div>
</section>

<?php
get_footer();
