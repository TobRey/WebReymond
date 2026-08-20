<?php
/**
 * Seite nicht gefunden.
 *
 * @package ReymondTobias
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<section class="cta" style="min-height: 70svh; display: grid; place-content: center">
	<div class="cta__glow" aria-hidden="true"></div>
	<div class="shell">
		<h1 class="display cta__title reveal">404 <em><?php esc_html_e( 'verloren', 'reymond-tobias' ); ?></em></h1>
		<a class="btn btn--solid" href="<?php echo esc_url( home_url( '/' ) ); ?>" data-magnetic>
			<?php esc_html_e( 'Zur Startseite', 'reymond-tobias' ); ?>
			<span class="btn__arrow">&rarr;</span>
		</a>
	</div>
</section>

<?php
get_footer();
