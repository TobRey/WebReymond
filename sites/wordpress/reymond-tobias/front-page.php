<?php
/**
 * Startseite: Banner, Laufband, Über, Termine, Abschluss.
 *
 * Der Text im Abschnitt „Über“ kommt aus dem Editor dieser Seite,
 * alles Übrige aus dem Customizer (Design → Customizer → Reymond Tobias).
 *
 * @package ReymondTobias
 */

defined( 'ABSPATH' ) || exit;

get_header();

$rt_stats = array( rt_opt( 'stat_1' ), rt_opt( 'stat_2' ), rt_opt( 'stat_3' ) );
$rt_gigs  = rt_get_gigs();
$rt_musik = get_page_by_path( 'musik' );
$rt_kont  = get_page_by_path( 'kontakt' );
$rt_musik_url = $rt_musik ? get_permalink( $rt_musik ) : home_url( '/' );
$rt_kont_url  = $rt_kont ? get_permalink( $rt_kont ) : home_url( '/' );
?>

<!-- ================= Banner =================
	 Steht die Person rechts im Bild, liegt der Titel links daneben:
	 die Abdunklung ist deshalb links am stärksten. -->
<section class="hero">
	<div class="hero__media">
		<img
			class="hero__img"
			data-parallax="0.12"
			src="<?php echo esc_url( rt_hero_image() ); ?>"
			alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"
			fetchpriority="high"
		/>
	</div>
	<div class="hero__scrim" aria-hidden="true"></div>

	<div class="hero__inner">
		<div class="hero__text">
			<?php if ( rt_opt( 'hero_eyebrow' ) ) : ?>
				<p class="eyebrow hero__eyebrow reveal"><?php echo esc_html( rt_opt( 'hero_eyebrow' ) ); ?></p>
			<?php endif; ?>

			<h1 class="display hero__title">
				<?php rt_lines( rt_opt( 'hero_line1' ) . '|' . rt_opt( 'hero_line2' ), true ); ?>
			</h1>

			<?php if ( rt_opt( 'hero_lead' ) ) : ?>
				<p class="hero__lead reveal reveal--delay-2"><?php echo esc_html( rt_opt( 'hero_lead' ) ); ?></p>
			<?php endif; ?>

			<div class="hero__actions reveal reveal--delay-3">
				<a class="btn btn--solid" href="<?php echo esc_url( $rt_musik_url ); ?>" data-magnetic>
					<?php echo esc_html( rt_opt( 'hero_cta_1' ) ); ?>
					<span class="btn__arrow">&rarr;</span>
				</a>
				<a class="btn" href="<?php echo esc_url( $rt_kont_url ); ?>" data-magnetic>
					<?php echo esc_html( rt_opt( 'hero_cta_2' ) ); ?>
				</a>
			</div>
		</div>

		<div class="hero__side">
			<?php foreach ( $rt_stats as $rt_index => $rt_stat ) : ?>
				<?php
				if ( ! $rt_stat ) {
					continue;
				}

				$rt_parts = array_map( 'trim', explode( '|', $rt_stat ) );
				?>
				<div class="hero__stat reveal reveal--delay-<?php echo (int) ( $rt_index + 1 ); ?>">
					<b><?php echo esc_html( $rt_parts[0] ); ?></b>
					<span><?php echo esc_html( isset( $rt_parts[1] ) ? $rt_parts[1] : '' ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
	</div>

	<div class="hero__scroll" aria-hidden="true">
		<i></i>
		<?php esc_html_e( 'Scrollen', 'reymond-tobias' ); ?>
	</div>
</section>

<!-- ================= Laufband ================= -->
<?php $rt_marquee = rt_list( rt_opt( 'marquee' ) ); ?>
<?php if ( $rt_marquee ) : ?>
	<div class="marquee" aria-hidden="true">
		<div class="marquee__track">
			<?php foreach ( $rt_marquee as $rt_index => $rt_word ) : ?>
				<span class="marquee__item <?php echo 0 === $rt_index % 2 ? '' : 'marquee__item--outline'; ?>">
					<?php echo esc_html( $rt_word ); ?>
				</span>
			<?php endforeach; ?>
		</div>
	</div>
<?php endif; ?>

<!-- ================= Über ================= -->
<section class="section shell">
	<div class="about">
		<div class="about__title display reveal">
			<?php rt_lines( rt_opt( 'about_title' ) ); ?>
		</div>

		<div class="about__body">
			<div class="reveal">
				<?php
				while ( have_posts() ) :
					the_post();
					the_content();
				endwhile;
				?>
			</div>

			<?php $rt_tags = rt_list( rt_opt( 'about_tags' ) ); ?>
			<?php if ( $rt_tags ) : ?>
				<div class="about__tags reveal reveal--delay-3">
					<?php foreach ( $rt_tags as $rt_tag ) : ?>
						<span class="tag"><?php echo esc_html( $rt_tag ); ?></span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>
</section>

<!-- ================= Termine ================= -->
<?php if ( $rt_gigs ) : ?>
	<section class="section shell">
		<p class="eyebrow reveal"><?php esc_html_e( 'Termine', 'reymond-tobias' ); ?></p>
		<h2 class="display reveal" style="font-size: var(--step-3); margin-top: 1rem">
			<?php echo esc_html( rt_opt( 'dates_title' ) ); ?>
		</h2>

		<div class="dates">
			<?php foreach ( $rt_gigs as $rt_index => $rt_gig ) : ?>
				<a
					class="date-row reveal reveal--delay-<?php echo (int) min( $rt_index, 3 ); ?>"
					href="<?php echo esc_url( $rt_gig['link'] ? $rt_gig['link'] : $rt_kont_url ); ?>"
				>
					<span class="date-row__day"><?php echo esc_html( $rt_gig['day'] ); ?></span>
					<span class="date-row__venue">
						<b><?php echo esc_html( $rt_gig['title'] ); ?></b>
						<span><?php echo esc_html( $rt_gig['place'] ); ?></span>
					</span>
					<span class="date-row__status"><?php echo esc_html( $rt_gig['status'] ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
	</section>
<?php endif; ?>

<!-- ================= Abschluss ================= -->
<section class="cta">
	<div class="cta__glow" aria-hidden="true"></div>
	<div class="shell">
		<?php
		$rt_cta   = array_map( 'trim', explode( '|', rt_opt( 'cta_title' ) ) );
		$rt_first = isset( $rt_cta[0] ) ? $rt_cta[0] : '';
		$rt_last  = isset( $rt_cta[1] ) ? $rt_cta[1] : '';
		?>
		<h2 class="display cta__title reveal">
			<?php echo esc_html( $rt_first ); ?>
			<?php if ( $rt_last ) : ?>
				<em><?php echo esc_html( $rt_last ); ?></em>
			<?php endif; ?>
		</h2>

		<a class="btn btn--solid" href="<?php echo esc_url( $rt_kont_url ); ?>" data-magnetic>
			<?php echo esc_html( rt_opt( 'cta_button' ) ); ?>
			<span class="btn__arrow">&rarr;</span>
		</a>
	</div>
</section>

<?php
get_footer();
