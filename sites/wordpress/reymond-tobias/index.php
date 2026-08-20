<?php
/**
 * Rückfallvorlage: Beiträge, Archive, Suche.
 *
 * @package ReymondTobias
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<section class="page-head shell">
	<h1 class="display page-head__title">
		<span class="line-mask">
			<span data-split>
				<?php
				if ( is_search() ) {
					esc_html_e( 'Suche', 'reymond-tobias' );
				} elseif ( is_archive() ) {
					echo esc_html( wp_strip_all_tags( get_the_archive_title() ) );
				} else {
					esc_html_e( 'Beiträge', 'reymond-tobias' );
				}
				?>
			</span>
		</span>
	</h1>
</section>

<section class="section shell" style="padding-top: clamp(1.5rem, 4vw, 3rem)">
	<?php if ( have_posts() ) : ?>
		<div class="dates">
			<?php
			while ( have_posts() ) :
				the_post();
				?>
				<a class="date-row reveal" href="<?php the_permalink(); ?>">
					<span class="date-row__day"><?php echo esc_html( get_the_date( 'd.m.' ) ); ?></span>
					<span class="date-row__venue">
						<b><?php the_title(); ?></b>
						<span><?php echo esc_html( get_the_date( 'Y' ) ); ?></span>
					</span>
					<span class="date-row__status"><?php esc_html_e( 'Lesen', 'reymond-tobias' ); ?></span>
				</a>
			<?php endwhile; ?>
		</div>

		<div class="hero__actions" style="margin-top: 2.5rem">
			<?php
			the_posts_pagination(
				array(
					'prev_text' => esc_html__( 'Zurück', 'reymond-tobias' ),
					'next_text' => esc_html__( 'Weiter', 'reymond-tobias' ),
				)
			);
			?>
		</div>
	<?php else : ?>
		<p class="page-head__sub"><?php esc_html_e( 'Nichts gefunden.', 'reymond-tobias' ); ?></p>
	<?php endif; ?>
</section>

<?php
get_footer();
