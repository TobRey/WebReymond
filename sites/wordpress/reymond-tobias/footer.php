<?php
/**
 * Fuss der Seite.
 *
 * @package ReymondTobias
 */

defined( 'ABSPATH' ) || exit;
?>
</main>

<footer class="site-footer">
	<div class="shell">
		<div class="site-footer__top">
			<a class="site-footer__mark" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<?php echo esc_html( get_bloginfo( 'name' ) ); ?>
			</a>

			<div class="site-footer__links">
				<?php rt_nav( '' ); ?>

				<?php if ( rt_opt( 'email' ) ) : ?>
					<a href="mailto:<?php echo esc_attr( rt_opt( 'email' ) ); ?>"><?php esc_html_e( 'E-Mail', 'reymond-tobias' ); ?></a>
				<?php endif; ?>
			</div>
		</div>

		<div class="site-footer__bottom">
			<span>
				<?php
				printf(
					'&copy; %1$s %2$s',
					esc_html( gmdate( 'Y' ) ),
					esc_html( get_bloginfo( 'name' ) )
				);
				?>
			</span>
			<span><?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></span>
		</div>
	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
