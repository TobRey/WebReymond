<?php
/**
 * Template Name: Kontakt (Booking)
 *
 * Direkte Wege links, Formular rechts. Der Versand läuft serverseitig
 * über admin-post.php und funktioniert auch ohne JavaScript.
 *
 * @package ReymondTobias
 */

defined( 'ABSPATH' ) || exit;

get_header();

$rt_message = rt_contact_message();
$rt_socials = array(
	'Instagram'  => rt_opt( 'instagram' ),
	'SoundCloud' => rt_opt( 'soundcloud' ),
	'Spotify'    => rt_opt( 'spotify' ),
	'TikTok'     => rt_opt( 'tiktok' ),
);
$rt_musik     = get_page_by_path( 'musik' );
$rt_musik_url = $rt_musik ? get_permalink( $rt_musik ) : home_url( '/' );
?>

<section class="page-head shell">
	<p class="eyebrow reveal"><?php echo esc_html( get_the_title() ); ?></p>
	<h1 class="display page-head__title">
		<span class="line-mask"><span data-split><?php esc_html_e( 'Booking', 'reymond-tobias' ); ?></span></span>
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

<section class="section shell" style="padding-top: clamp(1.5rem, 4vw, 3rem)">
	<div class="contact">
		<div class="contact__info">
			<?php if ( rt_opt( 'email' ) ) : ?>
				<div class="info-block reveal">
					<b><?php esc_html_e( 'E-Mail', 'reymond-tobias' ); ?></b>
					<a href="mailto:<?php echo esc_attr( rt_opt( 'email' ) ); ?>"><?php echo esc_html( rt_opt( 'email' ) ); ?></a>
				</div>
			<?php endif; ?>

			<?php if ( rt_opt( 'phone' ) ) : ?>
				<div class="info-block reveal reveal--delay-1">
					<b><?php esc_html_e( 'Telefon', 'reymond-tobias' ); ?></b>
					<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', rt_opt( 'phone' ) ) ); ?>">
						<?php echo esc_html( rt_opt( 'phone' ) ); ?>
					</a>
				</div>
			<?php endif; ?>

			<?php if ( rt_opt( 'base' ) ) : ?>
				<div class="info-block reveal reveal--delay-2">
					<b><?php esc_html_e( 'Basis', 'reymond-tobias' ); ?></b>
					<p><?php echo esc_html( rt_opt( 'base' ) ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( array_filter( $rt_socials ) ) : ?>
				<div class="info-block reveal reveal--delay-3">
					<b><?php esc_html_e( 'Social', 'reymond-tobias' ); ?></b>
					<div class="socials">
						<?php foreach ( $rt_socials as $rt_name => $rt_url ) : ?>
							<?php if ( ! $rt_url ) : ?>
								<?php continue; ?>
							<?php endif; ?>
							<a class="tag" href="<?php echo esc_url( $rt_url ); ?>" target="_blank" rel="noopener">
								<?php echo esc_html( $rt_name ); ?>
							</a>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>
		</div>

		<form
			id="kontaktformular"
			class="form reveal reveal--delay-1"
			method="post"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			data-contact-form
			novalidate
		>
			<input type="hidden" name="action" value="rt_contact" />
			<?php wp_nonce_field( 'rt_contact', 'rt_contact_nonce' ); ?>

			<div class="field">
				<input type="text" id="name" name="name" placeholder=" " required autocomplete="name" />
				<label for="name"><?php esc_html_e( 'Name', 'reymond-tobias' ); ?></label>
				<span class="field__line"></span>
			</div>

			<div class="field">
				<input type="email" id="email" name="email" placeholder=" " required autocomplete="email" />
				<label for="email"><?php esc_html_e( 'E-Mail', 'reymond-tobias' ); ?></label>
				<span class="field__line"></span>
			</div>

			<div class="field">
				<select id="anlass" name="anlass" required>
					<option value="" disabled selected hidden></option>
					<option><?php esc_html_e( 'Club', 'reymond-tobias' ); ?></option>
					<option><?php esc_html_e( 'Festival', 'reymond-tobias' ); ?></option>
					<option><?php esc_html_e( 'Firmenanlass', 'reymond-tobias' ); ?></option>
					<option><?php esc_html_e( 'Private Feier', 'reymond-tobias' ); ?></option>
					<option><?php esc_html_e( 'Anderes', 'reymond-tobias' ); ?></option>
				</select>
				<label for="anlass"><?php esc_html_e( 'Anlass', 'reymond-tobias' ); ?></label>
				<span class="field__line"></span>
			</div>

			<div class="field">
				<input type="text" id="datum" name="datum" placeholder=" " />
				<label for="datum"><?php esc_html_e( 'Datum', 'reymond-tobias' ); ?></label>
				<span class="field__line"></span>
			</div>

			<div class="field">
				<input type="text" id="ort" name="ort" placeholder=" " />
				<label for="ort"><?php esc_html_e( 'Ort', 'reymond-tobias' ); ?></label>
				<span class="field__line"></span>
			</div>

			<div class="field">
				<textarea id="nachricht" name="nachricht" placeholder=" " rows="4"></textarea>
				<label for="nachricht"><?php esc_html_e( 'Nachricht', 'reymond-tobias' ); ?></label>
				<span class="field__line"></span>
			</div>

			<!-- Falle für automatisierte Eintragungen: für Menschen unsichtbar. -->
			<div class="visually-hidden" aria-hidden="true">
				<label for="website"><?php esc_html_e( 'Website', 'reymond-tobias' ); ?></label>
				<input type="text" id="website" name="website" tabindex="-1" autocomplete="off" />
			</div>

			<p class="form__status" data-form-status role="status"><?php echo esc_html( $rt_message ); ?></p>

			<button class="btn btn--solid" type="submit" data-magnetic>
				<?php esc_html_e( 'Anfrage senden', 'reymond-tobias' ); ?>
				<span class="btn__arrow">&rarr;</span>
			</button>
		</form>
	</div>
</section>

<div class="marquee" aria-hidden="true">
	<div class="marquee__track">
		<span class="marquee__item"><?php esc_html_e( 'Booking offen', 'reymond-tobias' ); ?></span>
		<span class="marquee__item marquee__item--outline"><?php esc_html_e( 'Schweiz &amp; Umgebung', 'reymond-tobias' ); ?></span>
		<span class="marquee__item"><?php esc_html_e( 'Antwort in 48h', 'reymond-tobias' ); ?></span>
		<span class="marquee__item marquee__item--outline"><?php esc_html_e( 'Let\'s go', 'reymond-tobias' ); ?></span>
	</div>
</div>

<section class="cta">
	<div class="cta__glow" aria-hidden="true"></div>
	<div class="shell">
		<h2 class="display cta__title reveal">
			<?php esc_html_e( 'Hör zuerst', 'reymond-tobias' ); ?> <em><?php esc_html_e( 'rein', 'reymond-tobias' ); ?></em>
		</h2>
		<a class="btn" href="<?php echo esc_url( $rt_musik_url ); ?>" data-magnetic>
			<?php esc_html_e( 'Zur Musik', 'reymond-tobias' ); ?>
			<span class="btn__arrow">&rarr;</span>
		</a>
	</div>
</section>

<?php
get_footer();
