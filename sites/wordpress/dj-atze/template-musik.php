<?php
/**
 * Template Name: Musik (Player)
 *
 * Grosser Player mit Cover, Titel und Balkenanzeige, darunter die Titelliste.
 * Die Titel werden unter „Musik“ im Backend gepflegt.
 *
 * @package DjAtze
 */

defined( 'ABSPATH' ) || exit;

get_header();

$rt_tracks = rt_get_tracks();
$rt_first  = $rt_tracks ? $rt_tracks[0] : null;
$rt_kont   = get_page_by_path( 'kontakt' );
$rt_kont_url = $rt_kont ? get_permalink( $rt_kont ) : home_url( '/' );
?>

<section class="page-head shell">
	<p class="eyebrow reveal"><?php echo esc_html( get_the_title() ); ?></p>
	<h1 class="display page-head__title">
		<span class="line-mask"><span data-split><?php esc_html_e( 'Sets', 'dj-atze' ); ?></span></span>
	</h1>

	<div class="page-head__sub reveal reveal--delay-2">
		<?php
		while ( have_posts() ) :
			the_post();
			the_content();
		endwhile;
		?>
	</div>
</section>

<?php if ( $rt_first ) : ?>
	<section class="shell">
		<div class="player reveal" data-player>
			<figure class="player__art">
				<img src="<?php echo esc_url( $rt_first['cover'] ); ?>"
					alt="<?php echo esc_attr( sprintf( /* translators: %s: Titel */ __( 'Cover: %s', 'dj-atze' ), $rt_first['title'] ) ); ?>" />
				<figcaption class="player__index">01</figcaption>
				<span class="player__live"><i></i> <?php esc_html_e( 'Läuft', 'dj-atze' ); ?></span>
			</figure>

			<div class="player__main">
				<p class="player__kicker"><?php esc_html_e( 'Jetzt ausgewählt', 'dj-atze' ); ?></p>

				<h2 class="display player__title"><span><?php echo esc_html( $rt_first['title'] ); ?></span></h2>

				<div class="player__meta">
					<span data-meta-tag><?php echo esc_html( $rt_first['tag'] ); ?></span>
					<span data-meta-count><?php echo esc_html( sprintf( '01 / %02d', count( $rt_tracks ) ) ); ?></span>
				</div>

				<canvas class="player__viz" aria-hidden="true"></canvas>

				<div
					class="player__scrub"
					role="slider"
					tabindex="0"
					aria-label="<?php esc_attr_e( 'Position im Titel', 'dj-atze' ); ?>"
					aria-valuemin="0"
					aria-valuemax="100"
					aria-valuenow="0"
				>
					<div class="player__scrub-track">
						<div class="player__scrub-fill"></div>
						<div class="player__scrub-knob"></div>
					</div>
				</div>

				<div class="player__times">
					<span data-time-now>0:00</span>
					<span data-time-total><?php echo esc_html( $rt_first['duration'] ); ?></span>
				</div>

				<div class="player__controls">
					<button class="ctrl" type="button" data-prev aria-label="<?php esc_attr_e( 'Vorheriger Titel', 'dj-atze' ); ?>">
						<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 5h2v14H6zM20 5v14l-11-7z" /></svg>
					</button>

					<button class="ctrl ctrl--play" type="button" aria-label="<?php esc_attr_e( 'Abspielen', 'dj-atze' ); ?>">
						<svg class="ctrl__play" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4l14 8-14 8z" /></svg>
						<svg class="ctrl__pause" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 4h4v16H6zM14 4h4v16h-4z" /></svg>
					</button>

					<button class="ctrl" type="button" data-next aria-label="<?php esc_attr_e( 'Nächster Titel', 'dj-atze' ); ?>">
						<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 5h2v14h-2zM4 5l11 7-11 7z" /></svg>
					</button>

					<label class="player__volume">
						<span class="visually-hidden"><?php esc_html_e( 'Lautstärke', 'dj-atze' ); ?></span>
						<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true">
							<path d="M4 9h4l5-4v14l-5-4H4zM16 9a4 4 0 010 6" fill="none" stroke="currentColor" stroke-width="1.6" />
						</svg>
						<input type="range" min="0" max="100" value="85" data-volume />
					</label>
				</div>

				<p class="player__notice">
					<?php esc_html_e( 'Vorschau – für diesen Titel ist noch keine Audiodatei hinterlegt.', 'dj-atze' ); ?>
				</p>
			</div>
		</div>
	</section>

	<section class="section shell" style="padding-top: clamp(2rem, 5vw, 4rem)">
		<p class="eyebrow reveal"><?php esc_html_e( 'Alle Titel', 'dj-atze' ); ?></p>
		<div class="tracklist reveal reveal--delay-1" data-tracklist></div>
	</section>
<?php else : ?>
	<section class="section shell">
		<p class="page-head__sub">
			<?php esc_html_e( 'Noch keine Titel angelegt. Im Backend unter „Musik“ Titel hinzufügen.', 'dj-atze' ); ?>
		</p>
	</section>
<?php endif; ?>

<div class="marquee marquee--reverse" aria-hidden="true">
	<div class="marquee__track">
		<span class="marquee__item marquee__item--outline"><?php esc_html_e( 'Laut aufdrehen', 'dj-atze' ); ?></span>
		<span class="marquee__item"><?php esc_html_e( 'Techno', 'dj-atze' ); ?></span>
		<span class="marquee__item marquee__item--outline"><?php esc_html_e( 'Dunkel', 'dj-atze' ); ?></span>
		<span class="marquee__item"><?php esc_html_e( 'Treibend', 'dj-atze' ); ?></span>
	</div>
</div>

<section class="cta">
	<div class="cta__glow" aria-hidden="true"></div>
	<div class="shell">
		<h2 class="display cta__title reveal">
			<?php esc_html_e( 'Sound für deine', 'dj-atze' ); ?> <em><?php esc_html_e( 'Nacht', 'dj-atze' ); ?></em>
		</h2>
		<a class="btn btn--solid" href="<?php echo esc_url( $rt_kont_url ); ?>" data-magnetic>
			<?php esc_html_e( 'Booking anfragen', 'dj-atze' ); ?>
			<span class="btn__arrow">&rarr;</span>
		</a>
	</div>
</section>

<?php
get_footer();
